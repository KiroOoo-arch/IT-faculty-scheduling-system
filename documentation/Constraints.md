# Scheduling Constraints

## Constraint Categories (enforced by the solver — `ai-engine/solver/scheduler.py`)

The system implements **eight main constraint categories**, with the **section's preferred scheduling window** also directly modeled by the solver. These are enforced mathematically by the CP-SAT constraint solver — a generated schedule cannot violate them:

1. **Faculty qualification** — a faculty member can only be assigned to subjects they're qualified to teach (`faculty_subjects`)
2. **Faculty availability** — a faculty member can only teach on days they've marked as available (`faculty_availabilities`)
3. **Room type matching** — lecture sessions require lecture rooms; lab sessions require a room whose type matches the subject's `lab_room_type` (e.g. `computer_lab`)
4. **Room capacity** — eligible rooms must have `capacity >= section.student_count`
5. **Faculty no double-booking** — a faculty member cannot teach two overlapping sessions
6. **Room no double-booking** — a room cannot host two overlapping sessions
7. **Maximum teaching load** — total scheduled hours per faculty member (including existing load from other schedules) cannot exceed `max_teaching_load`
8. **Cross-section conflicts** — new sessions cannot conflict with existing approved/published sessions of other sections (faculty, room, or time)

Plus:

- **Section preferred scheduling window** — all sessions must fit within the section's preferred days and time window (`preferred_days`, `preferred_start_time` to `preferred_end_time`); if a subject's required hours don't fit, the solver reports `INFEASIBLE` with a specific explanation
- **Section self-overlap** — a section cannot attend two sessions at the same time

## Solver Objective & Result Terminology

The solver **maximizes the number of successfully scheduled sessions** (best-effort scheduling). It reports `OPTIMAL`, `FEASIBLE`, `PARTIAL` (with per-session plain-language reasons), or `INFEASIBLE`. The solver is designed to satisfy the defined constraints and reports partial or infeasible results when resources and constraints prevent complete scheduling — it does not guarantee every scheduling problem is solvable.

## Not Implemented (future work)

- Mandatory lunch break in the daily schedule
- Priority/preference weighting for senior faculty
- Faculty scheduling *preferences* as a soft constraint distinct from hard availability (availability is currently a hard rule only)
