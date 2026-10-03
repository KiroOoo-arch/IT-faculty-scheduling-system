# DESIGN HEARING — Technical Deep-Dive
**Slot:** ~30 minutes presentation + deep questioning. This is where panels probe *how it's built* — expect interruptions.
**Structure:** Architecture → Database → Solver model → Workflow → Security → each section ends with "if asked deeper" escalation answers.

> This document pairs with `05-QA-BANK.md` (breadth) — this one gives you *depth*. All facts verified against source code; source files cited per section.

---

## Section 1 — Architecture Walkthrough

**The three-tier design:**

```
┌─────────────────────────────────────────────────┐
│ FRONTEND — React 19 + TypeScript + Vite (5173)  │
│  9 pages: Login, Admin Dashboard, Printable     │
│  Schedule, Users/Faculty/Subjects/Rooms/        │
│  Sections (admin CRUD), Reports                 │
└──────────────────┬──────────────────────────────┘
                   │ HTTP/JSON + Sanctum Bearer token
┌──────────────────▼──────────────────────────────┐
│ BACKEND — Laravel 13, PHP 8.3+ (8000)           │
│  Auth (login/logout/me) + admin middleware      │
│  CRUD controllers ×5, ScheduleController        │
│  (generate + validation gate),                  │
│  ScheduleApprovalController (approve/publish/   │
│  unpublish/reject + conflict gate),             │
│  ScheduleSessionController (edit + conflict     │
│  check), ReportController (5 reports)           │
└───────┬────────────────────────┬────────────────┘
        │ Eloquent ORM           │ HTTP POST /generate-schedule/{id}
┌───────▼─────────┐   ┌──────────▼─────────────────┐
│   PostgreSQL    │◄──│ AI ENGINE — FastAPI (8001) │
│   (5432)        │   │ psycopg2 reads (parameter- │
│  11 domain tbls │   │ ized), OR-Tools CP-SAT     │
└─────────────────┘   └────────────────────────────┘
```

**Why each choice (the panel's favorite question):**

| Choice | Justification |
|---|---|
| React + TS for frontend | Component model fits CRUD-heavy dashboards; TypeScript catches contract mismatches at build time; Vite gives fast dev iteration |
| Laravel for API | Mature auth (Sanctum first-party), validation, Eloquent ORM, migrations; team productivity via conventions |
| Python/FastAPI for AI | OR-Tools is Python-first; FastAPI is async and lightweight for a compute endpoint; isolates solver deps (ortools, pandas) from web deps |
| PostgreSQL | ACID, JSONB (used for `preferred_days`, `unscheduled_sessions`), FK constraints + cascade rules at DB level |
| Three services, one DB | Best tool per layer; independent maintainability; single source of truth avoids sync issues |

**Inter-service contract:** Laravel POSTs to `http://127.0.0.1:8001/generate-schedule/{section_id}`; FastAPI reads all needed data itself (section, subjects, faculty + qualifications + availability, rooms), solves, returns `{status, sessions[], unscheduled[]}`; Laravel persists schedules + sessions + generation log. Synchronous request/response — appropriate at department scale.

**If asked deeper:**
- *"Why not a monolith?"* → It effectively is one deployable unit per service on one machine; the split exists for technological fit (PHP web vs Python optimization), not microservice dogma. Scaling path: the AI engine is stateless — it can be replicated behind a queue without touching the rest.
- *"Why does FastAPI read the DB directly instead of Laravel passing all data?"* → Keeps the HTTP payload small and the contract simple (just a section ID); the solver needs many tables, and self-serving avoids duplicating Laravel's serialization logic. Parameterized psycopg2 queries prevent injection.
- *"What if the AI engine is down?"* → Laravel's HTTP call fails; the generation request returns an error to the UI; no partial writes occur because persistence happens only after a solver response. The rest of the system (CRUD, reports, editing) is unaffected.

---

## Section 2 — Database Design

**11 domain tables** (17 migration files total; the others are framework tables):

| Table | Purpose | Key columns |
|---|---|---|
| `users` | Admin accounts ONLY | name, email, password, **role** ('admin') |
| `faculties` | Faculty records (not users!) | **name** (stored directly), employee_no (unique), faculty_type (full_time/part_time), max_teaching_load, is_active; `user_id` is a **nullable legacy field — never call it a login** |
| `faculty_availabilities` | Available days + time windows per faculty | faculty_id, day_of_week, start_time, end_time |
| `subjects` | Catalog | code (unique), title, year_level, semester_name, lecture_hours, lab_hours, **lab_room_type** |
| `faculty_subjects` | Qualifications (M:N) | faculty_id, subject_id |
| `sections` | Student sections | name, year_level, semester_name, academic_year, student_count, **preferred_days (JSON)**, preferred_start/end_time |
| `section_subjects` | Section curriculum (M:N) | section_id, subject_id |
| `rooms` | Physical rooms/labs | name (unique), type (lecture/computer_lab/science_lab/electronics_lab), capacity, status |
| `schedules` | One per generation | section_id, **status (draft/approved/published/archived)**, approved_by, approved_at |
| `schedule_sessions` | Individual class blocks | schedule_id, subject_id, faculty_id, room_id, session_type, day_of_week, start/end_time |
| `schedule_generation_logs` | Audit trail | section_id, requested_by, status (optimal/partial/failure), message, unscheduled_sessions (JSON) |

**Design principles to articulate:**
1. **M:N relationships via pivot tables** (faculty_subjects, section_subjects) — normalized, no update anomalies
2. **Referential integrity at the DB level** — FK constraints with cascade deletes (deleting a faculty cleans availability/qualifications/sessions; deleting a schedule deletes its sessions)
3. **Migrations are the schema source of truth** — the AI engine reads the same schema; no drift
4. **Status as enumerated strings** with application-enforced transitions (draft → approved → published → archived; unpublish reverts published → draft)
5. **JSONB for genuinely schema-flexible data only** (preferred_days, unscheduled session explanations) — not as a dumping ground

**If asked deeper:**
- *"Why does `faculties` have `user_id` if faculty don't log in?"* → Honest answer: it's a **nullable legacy column** from before the September 2026 design decision removed faculty accounts; a migration detached faculty from users and moved `name` onto the faculty record. It exists for migration history, not function.
- *"Why strings for status instead of enums?"* → Application-enforced transitions with a small, controlled set of values; changing status values requires no DB migration. (Either answer is defensible; know why.)
- *"How do you prevent duplicate schedules for a section?"* → Regeneration archives the section's old drafts; publish archives older approved/published schedules of the same section; only one live published schedule per section at a time.

---

## Section 3 — The Solver Model (the deepest section; know it cold)

**Location:** `ai-engine/solver/scheduler.py` + `ai-engine/api/app.py`. Solver: `ortools.sat.python.cp_model`.

**Data flow:** FastAPI endpoint reads section, section's subjects, faculty (with qualifications and available days), rooms → builds the CP-SAT model → solves → returns structured result.

**Variables (per subject-session needed for the section):** for each candidate combination of (day, start time, room, faculty) the model carries a boolean `is_scheduled`; the model *maximizes the sum* of scheduled booleans → best-effort scheduling.

**How the 8 constraints are expressed (be able to describe at least 4):**

1. **Qualification** — a session's faculty variable ranges only over faculty in `faculty_subjects` for that subject (domain restriction, no big constraint needed)
2. **Availability** — the session's (day, start) is restricted to slots that fit inside a declared `faculty_availabilities` window (day + start/end hour); a faculty with no declared availability falls back to the section's days
3. **Room type** — lecture sessions → rooms of type `lecture`; laboratory sessions → rooms matching the subject's `lab_room_type` (domain filter)
4. **Capacity** — rooms with `capacity ≥ section.student_count` only (domain filter)
5. **Faculty no double-booking** — for every pair of sessions sharing a faculty and day: not (startA < endB ∧ startB < endA), enforced via before/after disjunction booleans
6. **Room no double-booking** — same pairwise overlap logic on rooms
7. **Max load** — total scheduled hours per faculty (including committed hours in other schedules) ≤ `max_teaching_load`
8. **Cross-section conflicts** — sessions of other sections that are approved/published act as **fixed external blocks**; new sessions must be time-disjoint from them when sharing faculty or room

Plus: **section preferred window** (day ∈ preferred_days; start/end within preferred times) and **section self-overlap** (the section can't attend two sessions simultaneously).

**The reification detail that makes you sound like you built it (and we really did fix this):** time-disjointness of two sessions is a **disjunction** — session A is entirely *before* B, or entirely *after*. In CP-SAT this is modeled with a boolean `before` and `after` and `AddBoolOr([before, after])` — the pairwise constraints reify both directions. ⚠️ **History:** an earlier one-directional implication (`¬overlap → start after external session`) made valid schedules INFEASIBLE because evening sessions of an approved schedule lay completely outside a morning section's window. We diagnosed it by logging the solver status, reified disjointness properly as before-or-after for both external and internal conflicts, and added a 46-test solver suite covering exactly these cases. **Tell this story if asked "what was your hardest bug?" — it demonstrates real understanding of constraint modeling.**

**Solve phase:** `solver.parameters.max_time_in_seconds = 15.0`; status mapped: OPTIMAL (proven best), FEASIBLE (valid, budget hit), then reported to Laravel as OPTIMAL/PARTIAL (some `is_scheduled` false, each with reason) / INFEASIBLE. On our dataset, OPTIMAL arrives in ~1–2 s.

**If asked deeper:**
- *"Why CP-SAT and not a genetic algorithm / simulated annealing?"* → Metaheuristics give no validity guarantee and no optimality proof; CP-SAT exhaustively reasons over the constraint space within its budget and *proves* optimality. Deterministic, explainable, industrial-grade.
- *"Why not ML?"* → No training data exists for this department; scheduling validity is a hard requirement, not a probabilistic one; CP gives guaranteed satisfaction + explanations. (ML is a poor fit; this is a modeling problem, not a prediction problem.)
- *"Why maximize sessions instead of optimizing compactness/fairness?"* → Correctness first: every placed session must satisfy all hard constraints. Objective extensions (compact schedules, balanced loads) are additive soft objectives — future work.
- *"What's the complexity? Does it scale?"* → Pairwise no-overlap constraints grow O(n²) in sessions per day; CP-SAT handles this scale comfortably. 15 s budget caps worst-case; at department scale (tens of sections) it's fine; university scale would partition by department/college — architecturally supported by the stateless engine.

---

## Section 4 — Workflow & Controllers

**Status machine:** `draft → approved → published → archived`; `unpublish: published → draft`. Regeneration archives old drafts; publish archives older approved/published schedules of the same section.

**Controller responsibilities (know which controller does what):**

| Controller | Responsibilities |
|---|---|
| `AuthController` | login (rejects non-admin roles), logout (token revoke), me |
| `FacultyController` | CRUD + attachSubjects/detachSubject + availability endpoints |
| `SubjectController` | CRUD + **lab consistency rule**: lab_hours > 0 requires a lab_room_type; lab_hours = 0 requires none (422 otherwise) |
| `RoomController`, `SectionController` | CRUD; Section enforces **subject year/semester matching** on save; time normalization for AM/PM input |
| `ScheduleController` | **Generation**: pre-flight validation gate (re-checks subject/section matching, 422 with named subjects), archives old drafts, calls AI engine, persists result, writes generation log |
| `ScheduleApprovalController` | approve/publish/unpublish/reject/delete; **publish conflict gate** (cross-section check, 422 with conflict details) |
| `ScheduleSessionController` | manual edit with **server-side conflict detection** (422 with specific conflicts) |
| `ReportController` | 5 endpoints: faculty-workload, room-utilization, conflicts, schedule-status, section-summary |

**Every failure path returns structured 422s with human-readable reasons** — panels love this; it's the difference between "error" and "explanation." Separately, a shared **published-reference guard** (`GuardsPublishedReferences`) blocks deleting any faculty, subject, room, or section still referenced by a **published** schedule, answering **409** with the affected schedule IDs and session counts until the request confirms with `?force=1`.

---

## Section 5 — Security Model

1. **Authentication:** Laravel Sanctum — email/password → bearer token; `POST /logout` revokes
2. **Authorization (two levels):** login itself rejects non-admin roles (defense-in-depth: even a valid faculty credential can't get a token); all management routes nested in `auth:sanctum` + `admin` middleware
3. **Last-admin guard:** the system refuses to delete the final admin account — no lockout
4. **Injection safety:** Eloquent ORM (parameterized), psycopg2 parameterized queries
5. **XSS:** React escapes by default; no `dangerouslySetInnerHTML`
6. **Input validation:** Laravel `validate()` on every write; whitelist rules
7. **CSRF:** Laravel's protection for cookie-based flows; API uses token auth
8. **Data privacy:** documented notice, retention, and data-subject rights in `documentation/Data-Privacy-and-Security.md`

**If asked deeper:**
- *"Why remove faculty logins — isn't that less secure?"* → Inverse: **smaller attack surface**. Fewer accounts = fewer credentials to leak, no privilege escalation paths, no portal endpoints to protect. Distribution via print/PDF matches department practice. This was an instructor-directed design decision we can justify on security grounds.
- *"How are passwords stored?"* → Laravel's bcrypt/argon2 hashing (framework default); never plaintext.
- *"What about the token in localStorage?"* → Sanctum token auth with explicit logout revocation; standard SPA pattern; the admin-only model limits exposure.

---

## Section 6 — API Surface (know a handful cold)

**Auth:** `POST /api/login`, `POST /api/logout`, `GET /api/me`
**CRUD:** `apiResource` for faculties, subjects, rooms, sections, users (+ faculty subject/availability endpoints); destructive deletes accept `?force=1` to override the published-reference **409 guard**
**Scheduling:** `POST /api/schedules/generate/{section}` · `GET /api/schedules[/{id}]` · `PATCH .../approve|publish|unpublish|reject` · `PUT /api/schedules/sessions/{session}` · `DELETE /api/schedules/{id}`
**Reports:** `GET /api/reports/faculty-workload | room-utilization | conflicts | schedule-status | section-summary`
**AI engine:** `GET :8001/health` · `POST :8001/generate-schedule/{section_id}`

---

## Presentation skeleton for the 30-minute slot

| Time | Topic |
|---|---|
| 0–4 min | Architecture walkthrough (Section 1) |
| 4–9 min | Database design (Section 2) |
| 9–17 min | Solver model — the core (Section 3) |
| 17–22 min | Workflow + controllers (Section 4) |
| 22–26 min | Security (Section 5) |
| 26–30 min | API surface + wrap-up |

**Deep-dive demo option:** if the panel wants to see internals, have `ai-engine/solver/scheduler.py` open scrolled to the constraint section, and `backend/routes/api.php` — showing real constraint code for 20 seconds is worth five minutes of claims.
