# Progress Report — IT Faculty Scheduling System

*Updated: September 27, 2026. Covers development from July 18, 2026 to present.*

---

## 1. Project Timeline (from git history)

### Phase 1 — Foundation (July 18–19, 2026)
- **Jul 18:** Project initialized — repository, database documentation, system diagrams, README
- **Jul 19:** AI engine structure created; project reorganized into `backend/`, `frontend/`, `ai-engine/`, `documentation/`

### Phase 2 — Database Design (July 22, 2026)
- Faculty scheduling schema, models, seeder, and first API endpoints
- 11-table PostgreSQL schema: users, faculties, faculty_availabilities, subjects, faculty_subjects, sections, section_subjects, rooms, schedules, schedule_sessions, schedule_generation_logs

### Phase 3 — AI Engine Validation (July 22, 2026)
- **Standalone prototype** proved the constraint model on a fake dataset (no double-booking, labs in lab rooms)
- **Stress tests:** forced part-time faculty restriction (Mon/Wed/Fri respected); deliberately broke the dataset to confirm the solver fails *cleanly* with a human-readable explanation instead of crashing
- This directly derisked the "AI Recommendation / failure explanation" requirement before any integration work

### Phase 4 — Backend CRUD (July 25, 2026)
- Complete CRUD for Faculty, Subject, Room, Section
- Section–subject relationship wired; table-name mismatches between Laravel and the AI engine fixed
- Laravel migrations established as the single source of truth (raw `schema.sql` retired to reference-only)

### Phase 5 — Authentication & Frontend (July 26, 2026)
- Laravel Sanctum token authentication + role-based access control + admin middleware
- React frontend initialized: login, role-based dashboards, schedule generation UI

### Phase 6 — Working Prototype (July 30 – August 2, 2026)
- **Jul 30:** RBAC route regression fixed; partial-scheduling support (best-effort placement with per-session failure explanations); schedule approval workflow; session editing
- **Aug 1:** Full CRUD fixes; ReportController + ScheduleGenerationLog; room **capacity constraint** added; cascade delete on Room/Schedule; reports dashboard; duplicate-schedule bug fixed; auto-archive of old drafts
- **Aug 2:** Faculty availability page; session editing UI; delete confirmation modal; **real-time conflict detection** on manual edits; dev-log with architecture, ER diagram, and process flow (the program-level flow is now documented in `documentation/Program-Flow.md`)

### Phase 7 — Publish Integrity & Hardening (August 4–6, 2026)
- **Aug 4:** **Publish conflict gate** — prevents cross-section room/faculty double-booking at publish time; architecture documentation
- **Aug 6:** Middleware/seeder refinements

### Phase 8 — Reports & Workflow Completion (August 21, 2026)
- **Unpublish flow** (published → draft for editing)
- Reports summary: schedule status overview, faculty load, room usage, sections, generation logs

### Phase 9 — Defense Preparation (August 27–31, 2026)
- System Defense Guide (24 Q&A), Requirements.md status update, SRS, complete system guide with architecture, flows, constraints, database design

### Phase 10 — Instructor Feedback Round (September 8, 2026)
Per instructor direction, the system's access model was redesigned:
- **Faculty login accounts removed** — faculty are records (name, availability, qualifications, workload, type), not users. Admin/Department Head is the only login. Login rejects non-admin roles; last-admin deletion guard added
- **Print/PDF distribution** — published schedules get a Print/PDF button opening a print-friendly weekly grid (A4 landscape) → browser print / Save as PDF for hard-copy distribution
- **Users page** restricted to admin accounts
- **Faculty UI fixed** to read names directly from faculty records (no more "—" display)
- **New feature tests:** AI-generated schedules verified against correct section, year level, and semester; tests run on a dedicated `scheduling_system_testing` database
- **Data Privacy & Security statement** added (privacy notice, security controls, retention & disposal, data subject rights, terms of use, disclaimer)
- Documentation refreshed: Complete Guide, Architecture, Requirements, SRS, API, User Manual, UI handoff checklist for the frontend team

### Phase 11 — Data-Integrity Validation & Test Restoration (September 16, 2026)
Final hardening round before the design defense:
- **Subject–section year/semester validation** (FR-018): the Sections page filters the subject checklist to the section's year level and semester; the backend rejects mismatched assignments with HTTP 422 and blocks schedule generation on any legacy mismatch until corrected (the AI engine is never invoked with invalid assignments). Classified as pre-scheduling data-integrity validation in the Laravel layer — not an OR-Tools constraint
- **Subject lab consistency validation** (FR-019): lab hours require one of the canonical lab room types (computer_lab, science_lab, electronics_lab); zero lab hours require none. Enforced server-side on create and update (HTTP 422)
- **AI scheduler test suite restored**: 32 real unit tests (Python stdlib unittest) directly exercising the CP-SAT solver with controlled fixtures — qualification, day-level availability, room type/capacity, double-booking, teaching load, cross-section conflicts, preferred window, partial/infeasible handling. 32 passed, 0 failed, 0 skipped, 0 warnings/errors
- **One legacy demo-data defect resolved during verification**: an ADVPROG (Year 2, 2nd Semester) assignment on the Year 1 · 1st Semester ELEC-1B section was found by the validation rules and removed through the normal application flow; ELEC-1B then generated OPTIMAL
- **Final regression verification passed**: backend 23 tests (100 assertions), AI engine 32 tests, frontend production build, and the full live lifecycle (generate → review → approve → publish → print → unpublish) with no regressions
- **Program-level flow documented**: `documentation/Program-Flow.md` traces each execution step to its file (entry points, request lifecycle, per-process flows, validation-gate placement, status handling) with the rendered figure in `documentation/screenshots/11-program-flow.png`

### Phase 12 — Live API Verification & Scheduling Defect Fixes (September 27, 2026)
Every documented status code and validation gate was exercised against the running stack (Laravel on 8000, FastAPI on 8001, `curl` with a real Sanctum token). The pass surfaced two defects in the generation path, both now fixed:
- **A fully-placed `FEASIBLE` result was reported as a failure.** The solver returns `FEASIBLE` when every session is placed but its 15 s budget expires before optimality is proven; `ScheduleController` accepted only `OPTIMAL`/`PARTIAL`, so a complete schedule was answered with 422 "No feasible schedule found." `FEASIBLE` is now accepted like `OPTIMAL`/`PARTIAL` and logged as `feasible`
- **An unreachable AI engine returned a raw 500 and logged nothing.** The connection failure is now caught: the endpoint answers `502 {"error":"AI engine unreachable", ...}` and writes a `failure` generation-log row, so an engine outage is visible in the Reports → Generation Logs tab
- **Regression tests added** for both fixes plus an `INFEASIBLE` guard so the accepted-status boundary cannot drift: backend suite is now **26 tests / 116 assertions** (was 23 / 100), all passing; the AI-engine suite stays at 32

---

## 2. Current System Status (as of September 27, 2026)

### Architecture
React (Frontend, port 5173) → Laravel (API/Auth, port 8000) → Python FastAPI (AI/CP-SAT, port 8001) → PostgreSQL

### AI Scheduling Engine — 8 Constraints Enforced

| # | Constraint | Prevents | Status |
|---|---|---|---|
| 1 | Faculty qualification | Unqualified faculty assigned | ✅ |
| 2 | Faculty availability | Scheduling on unavailable days (day-level) | ✅ |
| 3 | Room type matching | Labs in lecture halls | ✅ |
| 4 | Room capacity | Overcrowded rooms | ✅ |
| 5 | Faculty no double-booking | Same teacher, two places | ✅ |
| 6 | Room no double-booking | Same room, two classes | ✅ |
| 7 | Max teaching load | Exceeding max hours | ✅ |
| 8 | Cross-section conflicts | Conflicts with published schedules | ✅ |

### SOP Achievement Status

| SOP | Objective | Status | Implementation |
|---|---|---|---|
| SOP 1 | Auto-generate schedules | ✅ Complete | OR-Tools CP-SAT solver |
| SOP 2 | Prioritize students | ✅ Complete | Section preferred days/times enforced |
| SOP 3 | Detect conflicts | ✅ Complete | 8 constraints + manual edit blocking |
| SOP 4 | Optimize resources | ✅ Complete | Room type + capacity matching; utilization reports |
| SOP 5 | Department Head manages | ✅ Complete | Generate → Approve → Publish → Print workflow |
| SOP 6 | System acceptability | ✅ Complete | Automated tests, cascade delete, data integrity |

### Workflow (final)

```
Admin Login → Manage Scheduling Data → Assign Valid Subjects
           → Generate (AI) → Review/Edit → Approve → Publish → Print/PDF
           → Hard-Copy Distribution        ↓
                                    Unpublish (if corrections needed)
```

Subject assignments are validated before generation (year/semester match + lab consistency, HTTP 422 on violation).

### Test Results
- Backend feature tests: **26 passed (116 assertions)** — authentication, subject/section validation rules, generation attribution, publish gate, solver-status acceptance (FEASIBLE accepted, INFEASIBLE rejected), unreachable-engine handling
- AI engine unit tests: **32 passed, 0 failed, 0 skipped, 0 warnings/errors** — solver exercised directly with controlled fixtures
- TypeScript typecheck clean; production build passes
- Live-verified: admin login, faculty records with names, reports, conflict detection, publish gate, print view, unpublish — plus the September 27 API pass that fixed and re-verified the `FEASIBLE` and unreachable-engine defects

### Frontend Status

| Screen | Status |
|---|---|
| Login Page (admin-only) | ✅ Done |
| Admin Dashboard (Review & Approval, Print/PDF) | ✅ Done |
| Faculty Management (records with name, qualifications, availability) | ✅ Done |
| Subject / Room / Section Management | ✅ Done |
| Users Management (admin accounts only) | ✅ Done |
| Reports Dashboard (5 tabs) | ✅ Done |
| Print/PDF Schedule View | ✅ Done |
| Excel/CSV export | 🔜 Optional enhancement |

---

## 3. Key Technical Achievements

1. **Mathematical proof, not trial-and-error** — Google OR-Tools CP-SAT constraint programming guarantees valid schedules
2. **Best-effort scheduling** — PARTIAL results place what they can and explain every failure
3. **Publish conflict gate** — cross-section double-booking blocked at publish time
4. **Real-time conflict detection** on manual edits
5. **Deliberate security model** — admin-only access, defense-in-depth login check, last-admin guard, reduced attack surface (faculty accounts removed by design decision)
6. **Automated tests on an isolated database** — real data never touched by tests

---

## 4. Remaining / Optional Enhancements

- Excel/CSV export of published schedules
- Soft constraints (lunch break, senior faculty priority, preference weighting)
- Multi-semester planning; university-scale scalability
- Email/SMS notifications
