# Requirements

## Functional Requirements

| ID | Requirement | Status |
|---|---|---|
| FR-001 | User login/authentication | Planned — not yet implemented |
| FR-002 | Manage faculty records (create, view, update, delete) | Partially implemented — view only (`GET /api/faculties`, `GET /api/faculties/{id}`). Create/update/delete not yet built. |
| FR-003 | Manage subjects (create, view, update, delete) | Partially implemented — view only (`GET /api/subjects`, `GET /api/subjects/{id}`). Create/update/delete not yet built. |
| FR-004 | Generate a conflict-free schedule for a section using an AI/constraint solver | Implemented and verified — FastAPI + OR-Tools CP-SAT solver (`POST /generate-schedule/{section_id}`), tested against real seeded data with an `OPTIMAL` result |
| FR-005 | Detect and report scheduling conflicts (faculty, room, section double-booking) | Implemented as part of the solver — the solver refuses to produce a schedule that violates these constraints, and returns an `INFEASIBLE` status with a human-readable reason when no valid schedule exists (e.g. "No faculty is qualified to teach subject_id X") |
| FR-006 | Store faculty availability (days/times each faculty can teach) | Implemented — `faculty_availabilities` table, seeded and used by the solver |
| FR-007 | Link faculty to subjects they are qualified to teach | Implemented — `faculty_subjects` pivot table |
| FR-008 | Link sections to the subjects they take in a given semester | Implemented — `section_subjects` pivot table |
| FR-009 | Track room type and capacity for scheduling | Implemented — `rooms` table, used by the solver to match lecture vs. lab room types |
| FR-010 | Persist generated schedules for later review/approval | Planned — not yet implemented. Solver currently returns JSON only; nothing is written to `schedules`/`schedule_sessions` yet |

## Non-Functional Requirements

| Requirement | Status |
|---|---|
| **Security** | Planned. No authentication or role-based access control exists yet — all API endpoints are currently open with no login required |
| **Performance** | Partially verified. The solver returned an `OPTIMAL` result in a few seconds on a small dataset (3 faculty, 3 subjects, 1 section, 2 rooms) via `max_time_in_seconds = 15.0`. Not yet tested at larger scale |
| **Reliability** | Partially addressed. Backend returns structured error messages (e.g. 404 for missing section, 400 for missing subjects/faculty/rooms) instead of silent failures |
| **Scalability** | Not yet tested at scale — current validation is limited to a small hand-seeded dataset |

## Notes

This document reflects what has been built and verified as of the current stage of development, not the full original vision. Items marked "Planned" are intended but not yet implemented in the codebase confirmed so far.