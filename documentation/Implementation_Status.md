# Implementation Status & Technical Documentation
## AI-Assisted Student-Centered Constraint-Based Faculty, Classroom, and Laboratory Scheduling System

*Last updated: reflects work through the completion of Phase 3 (Backend CRUD) and core Phase 5 (AI Engine).*

This document records what has actually been built, tested, and verified working — as distinct from what was originally planned in `Capstone_Project_Summary` and `Requirements.md`. Use this alongside those files: they describe the *intended* system; this describes the *current, working* system.

---

## 1. Overall Status Summary

| Phase | Status | Notes |
|---|---|---|
| Phase 1 — System Analysis | ✅ Mostly done | Requirements.md drafted; Modules.md/Constraints.md may still need updates to match actual implementation |
| Phase 2 — Database Design | ✅ Done, tested | Schema + seed data validated with real queries |
| Phase 3 — Backend (Laravel) | ✅ Core CRUD done, tested | Faculty/Subject/Room/Section full CRUD live; auth not yet implemented |
| Phase 4 — Frontend (React) | ⬜ Not started | |
| Phase 5 — AI Scheduling Engine | ✅ Core validated, connected to real data | Standalone CSP model proven, then wired to live Postgres data via FastAPI |
| Phase 6 — Integration | ✅ Core path working | Laravel → FastAPI → Postgres write-back confirmed end-to-end |
| Phase 7 — Testing | ⬜ Formal testing not started | Ad-hoc testing done throughout (see Section 5) |

---

## 2. Database (Phase 2)

**Tech:** PostgreSQL 17.10, managed through Laravel migrations (not raw `schema.sql` — see Section 6 for why).

### Tables implemented (12 total)
| Table | Purpose |
|---|---|
| `users` | Login accounts — department head or faculty |
| `faculties` | Faculty profile: employee number, type (full_time/part_time/evening), max teaching load |
| `faculty_availability` | Which day-of-week + time ranges each faculty member can teach |
| `subjects` | Subject catalog: code, title, lecture/lab hours, required lab room type |
| `faculty_subjects` | Pivot: which faculty are qualified to teach which subjects |
| `sections` | Student sections (e.g. BSIT 1A): year level, preferred days/time window |
| `section_subjects` | Pivot: which subjects a section takes this semester |
| `rooms` | Classrooms/labs: name, type, capacity, status |
| `schedules` | A generated schedule for a section: status (draft/approved/published), who approved it |
| `schedule_sessions` | Individual class blocks within a schedule: subject, faculty, room, day, start/end time |
| `academic_years` / `semesters` | Academic calendar (from original `schema.sql`; not yet fully wired into Laravel migrations) |

### Verified working
- Full schema created via `php artisan migrate`, no errors
- Seed data mirrors the original validated prototype dataset (Prof. Reyes, Santos, Cruz; PROG1/PROG2/MATH1; R101/LAB1)
- Confirmed via direct query: Prof. Cruz's part-time availability (Mon/Wed/Fri only) is correctly stored and enforced

---

## 3. AI Scheduling Engine (Phase 5)

**Tech:** Python, Google OR-Tools (CP-SAT solver), FastAPI, psycopg2

### Validation history (in order)
1. **Standalone prototype** (`ai-engine/prototype/scheduler_prototype.py`) — hardcoded fake dataset, proved the core CSP model works: no faculty double-booking, no room double-booking, lab subjects correctly placed in lab rooms.
2. **Stress tests** — added a `TEST_MODE` toggle to the same prototype:
   - `normal` — baseline, OPTIMAL result
   - `force_cruz` — forced the solver to use the part-time faculty member; confirmed his Mon/Wed/Fri restriction was respected (`CHECK PASSED`)
   - `broken` — deliberately removed the only computer lab; confirmed the solver fails *cleanly* with a human-readable explanation instead of crashing — this directly derisks Requirements.md Section H ("AI Recommendation" / failure explanation)
3. **Real-data version** (`ai-engine/solver/scheduler.py`) — same CSP logic, rewritten as a reusable function (`generate_schedule()`) that accepts real data structures instead of hardcoded lists, making it callable from an API layer.
4. **FastAPI service** (`ai-engine/api/app.py`) — exposes `POST /generate-schedule/{section_id}`. On each call, it:
   - Queries PostgreSQL for the section's assigned subjects, qualified faculty (with their availability), and available rooms
   - Runs the CSP solver against that real data
   - Returns either a generated schedule or an explanation of infeasibility
   - `/health` endpoint confirms DB connectivity independent of solving

### Verified working
- `/generate-schedule/1` (BSIT 1A) tested multiple times — confirmed the solver re-solves fresh each time rather than caching (different runs produced different valid arrangements)
- No faculty double-booking, correct lab-room-type matching, and Prof. Cruz's day restriction all held on **real database data**, not just the fake prototype dataset

---

## 4. Backend API (Phase 3)

**Tech:** Laravel 13.21.1 (via `laravel/laravel` v13.8.0 installer), PHP 8.5.8, PostgreSQL driver (`pdo_pgsql`)

### Models
- `User`, `Faculty`, `FacultyAvailability`, `Subject`, `Section`, `Room`, `Schedule`, `ScheduleSession`
- Key relationships: `Faculty belongsToMany Subject` (via `faculty_subjects`), `Faculty hasMany FacultyAvailability`, `Section belongsToMany Subject` (via `section_subjects`), `Faculty belongsTo User`

### Controllers & Routes (all under `/api`)
| Resource | Routes | Status |
|---|---|---|
| Faculty | full CRUD (`index`, `show`, `store`, `update`, `destroy`) | ✅ Tested |
| Subject | full CRUD | ✅ Tested |
| Room | full CRUD | ✅ Tested |
| Section | full CRUD (includes syncing subjects via `subject_ids`) | ✅ Tested |
| Schedule generation | `POST /schedules/generate/{section}` — calls the FastAPI engine, persists results into `schedules`/`schedule_sessions` | ✅ Tested |

### Verified working
- `/api/faculties` returns nested `user`, `subjects`, `availabilities` data correctly
- `/api/sections` returns nested `subjects` with pivot data
- `/api/rooms` returns room list correctly
- Schedule generation writes real, valid rows into `schedules` and `schedule_sessions` — confirmed across two separate generation runs with different (both valid) outcomes

### Not yet implemented
- Authentication/authorization (Sanctum) — all endpoints are currently open, no login required
- Reports endpoints (faculty workload, room utilization, etc. — per Requirements.md Section I)

---

## 5. Issues Encountered & How They Were Resolved

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

## 6. Design Decision: Laravel Migrations as Source of Truth

Early on, the database was built two ways in parallel: once via raw `schema.sql`/`seed.sql` run directly through `psql`, and again via Laravel migrations once the backend was scaffolded. These conflicted (same table names, different column definitions).

**Decision:** Laravel migrations are the single source of truth going forward. The database was dropped and rebuilt via `php artisan migrate` only. `schema.sql`/`seed.sql` remain in the repo as historical/reference documentation of the original design intent, but are not run again.

---

## 7. Suggested Next Steps

In rough priority order:
1. **Authentication** (Laravel Sanctum) — needed before this can be considered a real multi-user system
2. **React frontend** — currently the system is only testable via browser JSON responses or curl; no visual UI exists yet
3. **Reports endpoints** — faculty workload, room/lab utilization, per Requirements.md Section I
4. **Formal testing pass** — unit tests for the solver, feature tests for the API, matching Requirements.md's non-functional requirements (30-second generation target, etc.)