# Implementation Status & Technical Documentation
## AI-Assisted Student-Centered Constraint-Based Faculty, Classroom, and Laboratory Scheduling System

*Last updated: reflects work through the frontend, the schedule approval workflow, the cross-section
conflict protection (term-scoped generation lock, pre-write conflict gate, draft-aware conflict
checks), the full backend test suite (127 tests), and the AI engine test suite (118 tests).*

This document records what has actually been built, tested, and verified working — as distinct from
what was originally planned in `Requirements.md` and `SRS.md`. Use this alongside those files: they
describe the *intended* system; this describes the *current, working* system.

> **Superseded sections:** earlier revisions recorded the backend as having no authentication and the
> frontend as "not started". Both were true at the time and are no longer — Phases 3, 4 and 7 below
> describe the shipped behaviour.

---

## 1. Overall Status Summary

| Phase | Status | Notes |
|---|---|---|
| Phase 1 — System Analysis | ✅ Done | `Requirements.md`, `SRS.md`, `Modules.md`, `Constraints.md` |
| Phase 2 — Database Design | ✅ Done, tested | PostgreSQL schema via Laravel migrations; 12 domain tables |
| Phase 3 — Backend (Laravel) | ✅ Done, tested | Full CRUD, Sanctum auth + admin RBAC, approval workflow, reports, settings |
| Phase 4 — Frontend (React) | ✅ Done | 10 routes; master-data pages, dashboard, reports, two printable timetables |
| Phase 5 — AI Scheduling Engine | ✅ Done, tested | CP-SAT solver wired to live PostgreSQL via FastAPI |
| Phase 6 — Integration | ✅ Done | Laravel → FastAPI → Postgres write-back confirmed end-to-end |
| Phase 7 — Testing | ✅ Automated suite | 127 backend tests (537 assertions), 118 engine tests |

---

## 2. Database (Phase 2)

**Tech:** PostgreSQL 17, managed through Laravel migrations (not raw `schema.sql` — see Section 8 for why).

### Domain tables (12)

| Table | Purpose |
|---|---|
| `users` | Login accounts — Admin / Department Head |
| `faculties` | Faculty record: name, employee number, type (full_time/part_time/evening), max teaching load, active flag |
| `faculty_availabilities` | Per-day time windows each faculty member can teach |
| `faculty_subjects` | Pivot: which faculty are qualified to teach which subjects |
| `subjects` | Subject catalog: code, title, year level, semester, lecture/lab hours, required lab room type |
| `sections` | Student sections: name, year level, academic year, semester, preferred days/window, student count |
| `section_subjects` | Pivot: which subjects a section takes |
| `rooms` | Classrooms/laboratories: name, type, capacity, status |
| `schedules` | A generated timetable for a section: status, who approved it and when |
| `schedule_sessions` | Individual class blocks: subject, faculty, room, day, start/end time |
| `schedule_generation_logs` | Every generation attempt: outcome, message, unscheduled sessions, who requested it |
| `settings` | Institutional settings the Department Head owns (the midday break) |

Plus 9 Laravel framework tables (`migrations`, `sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs`, `personal_access_tokens`, `password_reset_tokens`) — **21 public tables**
and 36 indexes in total.

### Verified working

- Full schema created via `php artisan migrate`, no errors
- Current demo dataset (live counts): **16 sections, 30 subjects, 11 rooms, 23 faculty**
- Part-time faculty availability (declared days plus time windows) is correctly stored and enforced

> There is **no** `academic_years` or `semesters` table — earlier revisions listed them as pending
> additions from the original `schema.sql`. The academic year and semester are stored as plain
> columns on `sections` and `subjects`.

---

## 3. AI Scheduling Engine (Phase 5)

**Tech:** Python, Google OR-Tools (CP-SAT), FastAPI, psycopg2

### Validation history (in order)

1. **Standalone prototype** (`ai-engine/prototype/scheduler_prototype.py`) — hardcoded fake dataset, proved the core CSP model works: no faculty double-booking, no room double-booking, lab subjects correctly placed in lab rooms.
2. **Stress tests** — added a `TEST_MODE` toggle to the same prototype:
   - `normal` — baseline, OPTIMAL result
   - `force_cruz` — forced the solver to use the part-time faculty member; confirmed his Mon/Wed/Fri restriction was respected (`CHECK PASSED`)
   - `broken` — deliberately removed the only computer lab; confirmed the solver fails *cleanly* with a human-readable explanation instead of crashing — this directly derisks Requirements.md Section H ("AI Recommendation" / failure explanation)
3. **Real-data version** (`ai-engine/solver/scheduler.py`) — the same CSP logic, rewritten as a reusable `generate_schedule()` that accepts real data structures instead of hardcoded lists, making it callable from an API layer.
4. **FastAPI service** (`ai-engine/api/app.py`) — exposes `POST /generate-schedule/{section_id}`. On each call it queries PostgreSQL for the section, its subjects, qualified faculty (with availability windows), available rooms, the break setting, and hours already committed in other published sections; then solves and returns either placements or per-session reasons.

### Verified working

- Solves on **real database data**, re-solving fresh each run rather than caching
- No faculty double-booking, correct lab-room-type matching, and availability day + window restrictions all hold
- Full demo sweep: **15 sections → OPTIMAL, 146 sessions placed, 0 unscheduled**, each solve well under the 15-second budget, with 0 overlapping pairs, 0 room clashes and 0 faculty clashes in the persisted rows
- Reasons in **minutes since midnight** on a **30-minute** grid, so half-hour starts survive
- The midday break is a hard constraint; a bad settings row is treated as "no break" rather than making every section unschedulable

### Failure reporting

| Engine result | Condition |
|---|---|
| `404` | Unknown section |
| `400` | No subjects assigned / no qualified faculty / no available rooms |

These are surfaced by the API as **422** with the engine's own message — they are data problems the
admin can fix, not server faults.

---

## 4. Backend API (Phase 3)

**Tech:** Laravel 13, PHP 8.5, PostgreSQL (`pdo_pgsql`), Laravel Sanctum

### Authorization

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('admin')->group(function () { /* everything else */ });
});
```

Only `POST /api/login` is public. Everything else needs a token, and everything besides `/logout`
and `/me` additionally requires the `admin` role. Faculty are records, not users.

### Controllers & Routes (50 routes under `/api`)

| Resource | Routes | Status |
|---|---|---|
| Auth | `login`, `logout`, `me` | ✅ Tested |
| Users | full CRUD (`index`, `show`, `store`, `update`, `destroy`) | ✅ Tested |
| Faculty | full CRUD + `availability` (GET/POST) + `subjects` (attach/detach) | ✅ Tested |
| Subject | full CRUD, with lab-consistency validation | ✅ Tested |
| Room | full CRUD, with a canonical room-type vocabulary | ✅ Tested |
| Section | full CRUD, with year/semester subject-match validation | ✅ Tested |
| Schedule | `generate/{section}`, `index`, `show`, `approve`, `publish`, `unpublish`, `reject`, `destroy`, session `update` | ✅ Tested |
| Reports | `faculty-workload`, `room-utilization`, `section-summary`, `schedule-status`, `conflicts`, `faculty/{faculty}/schedule` | ✅ Tested |
| Settings | `index` (GET), `update` (PUT) — the midday break | ✅ Tested |

### Error semantics

- `POST /api/login` — **401** for bad credentials, **403** for a valid non-admin account, **422** for a malformed body
- `generate/{section}` — **404** unknown section, **422** when the section's subjects mismatch its year/semester (engine never called), **422** for an engine `4xx` carrying the engine's reason, **502** only when the engine is unreachable or itself faults
- `publish` — **422** when not approved, or when the cross-section conflict check finds a clash
- `DELETE` on faculty/subject/room/section — **409** when a *published* schedule still references it, unless confirmed with `?force=1`
- `DELETE /api/schedules/{id}` — **422** for a published schedule

### Verified working

- `/api/faculties` returns nested `user`, `subjects`, `availabilities`
- `/api/sections` returns nested `subjects` with pivot data
- Schedule generation persists real rows into `schedules` / `schedule_sessions`
- Full sweep of every route with valid and invalid payloads: **82/82 checks pass**

### Known naming defect

`GET /api/reports/conflicts` returns **generation logs**, not conflicts — the Reports UI tab that
consumes it is correctly labelled "Generation Logs". The endpoint name is misleading and should be
renamed (`/reports/generation-logs`); it is left as-is because renaming changes the public API.

---

## 5. Frontend (Phase 4)

**Tech:** React 19, TypeScript, Tailwind CSS 4, Vite (port 5173). API base
`http://127.0.0.1:8000/api`, Bearer token in `localStorage`.

### Pages

| Route | Purpose |
|---|---|
| `/login` | Admin login |
| `/dashboard` | Generate-schedule card + schedule review/approval and delete |
| `/admin/users`, `/admin/faculty`, `/admin/subjects`, `/admin/rooms`, `/admin/sections` | Master data |
| `/admin/reports` | Overview / Faculty Load / Room Usage / Sections / Generation Logs |
| `/print-schedule?schedule={id}` | Printable section timetable — subject list (default) or `&layout=grid` |
| `/print-faculty-schedule?faculty={id}` | Printable per-faculty timetable |

### Notable implementation details

- `constants/system.ts` — organisation and system name used across login, dashboard and the tab title
- `constants/roomTypes.ts` — the canonical room/lab vocabulary, mirroring `RoomController::ROOM_TYPES`
  and `SubjectController::LAB_ROOM_TYPES` (the solver matches room type by exact string)
- `components/PrintLetterhead.tsx` — the official letterhead (SVG stripe sweep, seals, wordmark)
- `components/ScheduleListTable.tsx` — subject-list printable with an Instructor column
- `AuthContext` re-validates the cached profile against `GET /me` on mount, so a renamed account
  appears correctly without a re-login

### Verified working

- Every page renders with **0 console errors and 0 failed network requests**
- All four Reports tabs render real data; invalid `?faculty=` / `?schedule=` IDs degrade to a clear
  message instead of a blank screen or a crash
- `npm run build` (`tsc -b && vite build`) passes with no type errors

### Known debt

`npm run lint` reports **11 errors / 9 warnings**, all `react-hooks/set-state-in-effect` from the
`useEffect(() => { fetchX() }, [])` pattern across the admin pages. Pre-existing, no runtime impact.

---

## 6. Approval Workflow & Protection Layers

```
draft --approve--> approved --publish--> published --unpublish--> draft
  |                    |
  +-- reject --> rejected
  +-- delete (any state except published)
```

**Five protection layers** — name all five when presenting:

1. **Solver constraints** — room/faculty double-booking, section self-overlap, availability and load
   ceilings, room type + capacity, break avoidance, against every other section's draft/approved/
   published sessions in the same academic year and semester
2. **Generation-time conflict gate and term lock** — one generation run per academic year and semester
   at a time (`409` when contended); the engine's plan is cross-checked against that term's other
   schedules before anything is written (`422` on a clash)
3. **Manual-edit validation** — `PUT /api/schedules/sessions/{id}` validates the full proposed state,
   including other sections' draft/approved/published sessions in the same term
4. **Publish conflict gate** — cross-section cross-check before a timetable goes live
5. **Published-reference delete guard** — a published timetable cannot be orphaned by deleting the
   faculty/room/subject/section it depends on

> **Superseded:** earlier revisions of this document recorded a limitation here — a draft used to
> avoid only *approved/published* schedules, so drafts generated in one batch could overlap one
> another. Both halves are no longer true: a new draft treats every other section's *draft*, approved
> and published sessions in the same academic year and semester as live bookings, and generation for
> a term runs one section at a time behind a lock, so a clashing plan is refused rather than written.
> The trade-off is the mirror image — a section generated later has less room to move, and a session
> that no longer fits comes back unplaced with a per-session reason for the admin to resolve.

---

## 7. Issues Encountered & How They Were Resolved

Keeping this section because it's genuinely useful for a capstone defense — it shows debugging process, not just final state.

| Issue | Cause | Fix |
|---|---|---|
| `psql` / `php` / `composer` not recognized | Windows PATH not updated after install | Manually added install paths to PATH; required a full sign-out/sign-in to propagate |
| Broken `.venv`, `pip.exe` pointing to a nonexistent `python.exe` | Mismatched/corrupted virtual environment | Abandoned the venv, used global Python install directly |
| `composer create-project` failed: missing zip extension | `php.ini` had `;extension=zip` commented out | Uncommented it, along with `pdo_pgsql`, `pgsql`, `mbstring`, `fileinfo`, `openssl` |
| `schema.sql`/`seed.sql` were empty placeholder files (161 bytes) | Leftover stub files from initial folder scaffolding, never overwritten | Replaced with real content, verified file size before re-running |
| Laravel `migrate` connected to wrong DB/user despite correct `.env` | Laravel had cached the old config | `php artisan config:clear` |
| All custom migrations created tables with only `id` + `timestamps` | Laravel's `make:migration` generates an empty scaffold — real columns must be added manually | Rewrote each migration with actual columns matching the validated schema design |
| `FacultyController::index()` undefined despite content looking correct in the editor | File edit likely hadn't saved before the request was tested | Re-verified file content on disk with `type`, confirmed, retested |
| Duplicate `section_subjects` migration created | Forgot an earlier migration (`2026_07_25_020048`) already created this table | Checked `php artisan migrate:status`, found it already `Ran`, deleted the duplicate file |

---

## 8. Design Decision: Laravel Migrations as Source of Truth

Early on, the database was built two ways in parallel: once via raw `schema.sql`/`seed.sql` run directly through `psql`, and again via Laravel migrations once the backend was scaffolded. These conflicted (same table names, different column definitions).

**Decision:** Laravel migrations are the single source of truth going forward. The database was dropped and rebuilt via `php artisan migrate` only. `schema.sql`/`seed.sql` remain in the repo as historical/reference documentation of the original design intent, but are not run again.

---

## 9. Testing

| Target | Command | Current result |
|---|---|---|
| Backend | `cd backend && php artisan test` | **127 passed, 537 assertions** |
| AI Engine | `cd ai-engine && python -m unittest discover -s tests -t tests` | **118 tests, OK** |
| Frontend build / typecheck | `cd frontend && npm run build` | passes |
| Frontend lint | `cd frontend && npm run lint` | 11 errors / 9 warnings (known debt) |

The backend and AI-engine rows were re-run for this update; the frontend rows were not. The
AI-engine command needs `-t tests` because `tests/` has no `__init__.py` and Python 3.11+ refuses to
discover a start directory it cannot import.

The backend suite covers, among others: section/subject year-semester matching, subject lab
consistency, room-type vocabulary, faculty availability windows, section window validation, session
conflict detection, the published-reference delete guard, the schedule delete lifecycle, user
approval attribution, schedule generation attribution, login failure codes, and the engine-error
mapping (`4xx` → 422, `5xx` → 502).

An end-to-end API sweep of every route (valid and invalid payloads, status guards, validation
branches) passes 82/82.

---

## 10. Suggested Next Steps

In rough priority order:

1. **Harden login** — rate-limit failed attempts, and force a change of the demo password before any
   networked deployment
2. **Rename `/reports/conflicts`** to reflect that it returns generation logs
3. **Clear the frontend lint debt** — rework the fetch-on-mount effects
4. **Signature-before-approval** — designed and agreed, not built; see `Planned-Signature-Approval.md`
5. **Multi-signatory support** (Department Head + Registrar), if the printed form requires it

> **Reviewed and closed — intended behaviour, not a defect.** Generation deliberately reads only
> *approved* and *published* schedules from other sections, so a new draft never considers another
> section's draft. This is the documented rule (`Constraints.md` #8, `SRS.md` #8, `Requirements.md`
> rule 8), and the publish gate is where a cross-section clash is caught. Making generation globally
> draft-aware was considered and rejected: it would make results depend on generation order and
> contradict the documented workflow.
