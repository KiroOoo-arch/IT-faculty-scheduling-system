# FACT SHEET — Single Source of Truth
## AI-Assisted Student-Centered Constraint-Based Faculty, Classroom, and Laboratory Scheduling System

> **TEAM RULE: Say only what is on this sheet.** Every claim here was verified against the actual source code and live-tested (October 10, 2026). If a fact isn't here, don't say it. If a panelist disputes a number, this sheet has the source file to point to.

---

## 1. The One-Line Elevator Pitch

> "Our system replaces manual, error-prone faculty scheduling with a constraint-programming AI that mathematically guarantees conflict-free schedules — while keeping the Department Head in full control of what gets published."

Memorize this. It answers "what is your system?" in one breath.

---

## 2. Official Title & Why Each Word Matters

**AI-Assisted Student-Centered Constraint-Based Faculty, Classroom, and Laboratory Scheduling System**

| Word | Why it's there | If asked |
|---|---|---|
| **AI-Assisted** | Uses Google OR-Tools CP-SAT, an industry constraint solver. "Assisted" because the AI proposes; the Admin decides. Publishing is never automatic. | "Why not 'AI-based'?" → Because human oversight is a deliberate design principle, not an afterthought. |
| **Student-Centered** | Schedules are built around each **section's** preferred days and time window; room capacity protects students from overcrowded rooms. | "How is it student-centered?" → Section preferences are enforced by the solver itself, not treated as a suggestion. |
| **Constraint-Based** | Scheduling is modeled as a Constraint Programming problem: variables (day, time, room, faculty per session), domains, and hard constraints that must all hold. | "What is constraint programming?" → See Q4 in the Q&A bank. |
| **Faculty, Classroom, and Laboratory** | Covers all three resource types — lecture rooms, computer labs, science labs, electronics labs. | "Only IT labs?" → Room types include science and electronics labs too. |
| **Scheduling System** | End-to-end: data management → generation → review → approval → publication → printed distribution. | — |

---

## 3. Problem Statement (say it this way)

Manual faculty scheduling in the IT Department is:
1. **Slow** — building one semester's schedule takes hours of manual cross-checking
2. **Error-prone** — double-booked faculty, wrong room types (labs held in lecture rooms), exceeded teaching loads
3. **Invisible** — conflicts across sections are only discovered after the schedule is posted
4. **Untraceable** — no record of who generated what, when, or why a schedule changed

Our system solves all four with constraint-based AI generation, a five-layer conflict defense, and a full audit trail.

---

## 4. Technology Stack (verified from code)

| Layer | Technology | Port | Source of truth |
|---|---|---|---|
| Frontend | React + TypeScript + Vite + Tailwind CSS | 5173 | `frontend/package.json`, 9 page components |
| Backend API | Laravel 13 (PHP ^8.3) + Sanctum 4.3 | 8000 | `backend/composer.json` |
| AI Engine | Python + FastAPI + Google OR-Tools CP-SAT | 8001 | `ai-engine/api/app.py`, `ai-engine/solver/scheduler.py` |
| Database | PostgreSQL | 5432 | 17 migration files in `backend/database/migrations/` |

**Communication:** React → Laravel over HTTP/JSON with Sanctum Bearer tokens. Laravel → FastAPI over HTTP POST `http://127.0.0.1:8001/generate-schedule/{id}`. FastAPI reads scheduling data directly from PostgreSQL via psycopg2 (parameterized queries). Laravel persists results.

**Key phrase:** "Three services, one database, one source of truth."

---

## 5. The Verified Numbers (do not get these wrong)

| Fact | Correct value | ❌ Do NOT say |
|---|---|---|
| Backend tests | **127 passing (537 assertions)** across 17 feature test files | "23 tests" (outdated) |
| Solver tests | **118 passing** (Python unittest, 5 modules) | "46" (the old test_scheduler.py-only size) |
| Cross-section conflict tests | **25 passing (101 assertions)** — `ScheduleGenerationConflictTest`, `ScheduleSessionConflictTest` | — |
| Total automated tests | **245 passing** | — |
| Solver time limit | **15 seconds max** per generation | "instant" |
| Database tables | **11 domain tables** (+ auth/cache/jobs framework tables) | "15 tables" |
| Constraint categories | **8 hard** + section preferred window + section self-overlap | "exactly 8 total" |
| Room types | **4**: lecture, computer_lab, science_lab, electronics_lab | "2 types" |
| Schedule statuses | **4**: draft → approved → published → archived (+ unpublish reverts to draft) | — |
| Generation speed | **OPTIMAL in ~1–2 s** for demo-size data (verified live) | — |

---

## 6. The 8 Hard Constraints (memorize in order)

Enforced mathematically by CP-SAT in `ai-engine/solver/scheduler.py` — a generated schedule **cannot** violate them:

1. **Faculty qualification** — only faculty linked via `faculty_subjects` can be assigned
2. **Faculty availability** — only on the days the faculty member has declared, and only when the whole session fits inside one of their declared time windows (day + start/end hour)
3. **Room type matching** — lectures in lecture rooms; labs in rooms matching the subject's `lab_room_type`
4. **Room capacity** — `rooms.capacity ≥ sections.student_count`
5. **Faculty no double-booking** — one teacher, one place at a time
6. **Room no double-booking** — one room, one class at a time
7. **Maximum teaching load** — total hours ≤ `max_teaching_load` (including existing load from other schedules)
8. **Cross-section conflicts** — no conflict with other sections' sessions in the **same academic year and semester**; `draft`, approved and published schedules all count as active bookings (so two drafts generated for one term cannot claim the same faculty member or room)

Plus two modeled by the solver directly:
- **Section preferred scheduling window** — sessions must fit the section's `preferred_days` + start/end time
- **Section self-overlap** — a section can't attend two sessions at once

**⚠️ Precision trap:** faculty availability **is** enforced as a day **plus a time window** — the solver places a session only when it fits entirely inside one declared window on that day. A faculty member with no declared availability at all falls back to the section's preferred days. Do NOT describe it as day-level only.

---

## 7. The Five Protection Layers (best defense material in the whole project)

1. **Generation constraints** — CP-SAT enforces all constraints while *producing* the candidate schedule, treating every other section's `draft`, `approved` and `published` sessions in the same academic year and semester as fixed bookings
2. **Generation-time conflict gate + term lock** — one generation run per academic year and semester at a time (a contested run answers **409**), and the engine's plan is cross-checked against that term's other schedules before anything is written (**422** on a clash — the section's existing draft survives, and the replacement is one transaction)
3. **Manual-edit conflict detection** — every admin edit of a session (day/time/room/faculty) is re-checked server-side, against other sections' draft/approved/published sessions in the same term, and rejected with 422 on conflict
4. **Publish conflict gate** — a final cross-section conflict check against approved/published schedules before any schedule goes live
5. **Published-data delete guard** — deleting master data (faculty, subject, room, or section) that a **published** schedule still uses is blocked with **HTTP 409** and requires an explicit `?force=1` confirmation

Plus a **pre-flight validation gate in Laravel** (application rules, not solver constraints): subject–section year/semester matching and subject lab-hour/room-type consistency. Invalid data returns HTTP 422 and **never reaches the AI engine**.

**One-sentence version:** "AI proposes, five layers defend, the Admin decides."

---

## 8. Workflow (lifecycle)

```
Admin Login → Manage Data → Generate (AI) → DRAFT → Review/Manual Edit
   → APPROVED → Publish Conflict Gate → PUBLISHED → Print/PDF → Distribution
                                          ↓ (Unpublish)
                                       DRAFT (re-edit → re-approve → re-publish)
```
- Old drafts auto-archive when a section regenerates
- Publishing archives older approved/published schedules of the same section
- Statuses: `draft`, `approved`, `published`, `archived`
- **Every generation is logged** in `schedule_generation_logs` (audit trail)
- Deleting master data still used by a **published** schedule returns **409**; retry with `?force=1` to override after confirmation

---

## 9. User Model (say it carefully — panels probe this)

| User | System access |
|---|---|
| **Admin / Department Head** | The **only** login. Full control: all CRUD, generation, approval, publication, reports, admin accounts |
| **Faculty** | **Not users.** They are scheduling *records* (name, type, qualifications, availability, max load). Receive printed/PDF schedules |
| **Students** | Not users. Recipients of published schedules |

**Why this design (be ready to defend it):** per-semester usage, hard-copy distribution culture, and a drastically reduced attack surface — the instructor directed this decision (September 2026). Answer the "why?" with: *"It's a deliberate security-scope decision: fewer accounts, fewer credentials to protect, and distribution by print matches how the department actually works."*

---

## 10. Security Model

- Laravel Sanctum token auth (login → Bearer token → revoke on logout)
- Login itself **rejects non-admin roles** (defense-in-depth)
- All management routes behind `auth:sanctum` + `admin` middleware (verified in `backend/routes/api.php`)
- Last-admin deletion guard
- Eloquent ORM (SQL-injection-safe), React output escaping (XSS), Laravel validation on every write
- psycopg2 parameterized queries in the AI engine
- Data privacy statement exists: `documentation/Data-Privacy-and-Security.md`

---

## 11. Live-Verified Results (October 2, 2026)

These were demonstrated in the running system today:
- ✅ **Generation is OPTIMAL on the live dataset** — BSIT 1A produces 7 sessions (all placed) in well under a second; the demo dataset holds **7 faculty, 11 subjects, 5 rooms, and 9 sections** (BSIT 1A–1D, 2A, 2B, 3A–3C)
- ✅ Full workflow: generate → draft → approve → publish → print/PDF view → unpublish
- ✅ Validation gates fire correctly (422 with named offending subjects on mismatched assignments)
- ✅ No errors in Laravel logs, browser console, or network traffic
- ✅ Data integrity: zero orphaned sessions, zero broken references
- ✅ Frontend production build passes (tsc + vite)

---

## 12. Honest Limitations (volunteer these — credibility!)

| Limitation | How to frame it |
|---|---|
| Department-level scope only | "University-wide scheduling is future work; the architecture (independent services) is ready for it" |
| Soft *preferences* not modeled yet (seniority priority, gap minimization) | "We deliberately hardened all *validity* constraints first — the midday break is already a hard constraint — and soft preferences are a v2 feature" |
| Availability is declared per day-of-week, not per calendar date | "A session must fit inside a declared window on a declared day; term-date ranges are future work" |
| Small demo dataset | "The solver's complexity is independent of dataset size for correctness; performance was verified at 1–2 s for our scale" |
| Synchronous generation call | "Appropriate at department scale; queues would be the scaling path" |

**Never** claim: "always OPTIMAL", "it uses machine learning", "faculty can log in", "university-ready".
**Always** frame partial/INFEASIBLE as a *feature*: the system explains exactly what's wrong with the data instead of producing a bad schedule.

---

## 13. Corrections to Older Documents (if a panelist read them)

| Old claim | Correct now |
|---|---|
| "6/6 backend tests" | 127 backend tests (537 assertions) + 118 solver tests = 245 total |
| Faculty Dashboard exists | Removed Sept 2026 — faculty are records, not users |
| Print/PDF "planned" | Implemented (`PrintableSchedule.tsx`) and demo-verified |
| "Not enforced" max load | IS enforced (constraint #7, verified in `scheduler.py`) |
| 2 room types | 4 room types |
| Old report endpoints (overview, workload, sections, generation-logs) | faculty-workload, room-utilization, conflicts, schedule-status, section-summary |

---

## 14. Key Source Files (point here when challenged)

| Claim | File |
|---|---|
| 8 constraints + window + self-overlap | `ai-engine/solver/scheduler.py` |
| 15s solver limit | `ai-engine/solver/scheduler.py` (line ~206) |
| Validation gates (year/semester, lab consistency) | `backend/app/Http/Controllers/ScheduleController.php`, `SectionController.php`, `SubjectController.php` |
| Publish conflict gate · published-data delete guard (409 + `force=1`) | `backend/app/Http/Controllers/ScheduleApprovalController.php`, `backend/app/Http/Controllers/Concerns/GuardsPublishedReferences.php` |
| Term lock + generation-time conflict gate (409/422, same-term drafts count, atomic draft replacement) | `backend/app/Http/Controllers/ScheduleController.php`, `backend/config/scheduling.php` |
| Admin-only routing | `backend/routes/api.php` |
| 11 domain tables | `backend/database/migrations/` (17 files total incl. framework tables) |
| Frontend pages (9) | `frontend/src/pages/` |
| Constraint documentation | `documentation/Constraints.md` |
| Test counts | `backend/tests/Feature/` (127 tests / 537 assertions across 17 files), `ai-engine/tests/` (118 tests across 5 modules; `test_scheduler.py` holds 46) |

---

*Fact sheet verified October 10, 2026 against the running system and source code.*
