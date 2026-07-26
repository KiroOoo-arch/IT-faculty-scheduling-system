# Scheduling Constraints

## Hard Constraints (enforced by the solver — `ai-engine/solver/scheduler.py`)

These are mathematically guaranteed by the CP-SAT constraint solver — a generated schedule cannot violate any of these:

- A faculty member cannot teach two sessions at the same time (no double-booking)
- A room cannot host two sessions at the same time (no double-booking)
- A section cannot attend two sessions at the same time (no self-overlap)
- A faculty member can only be assigned to teach on days they've marked as available (`faculty_availabilities`)
- A faculty member can only be assigned to a subject they're qualified to teach (`faculty_subjects`)
- A lecture session can only be assigned to a room of type matching the session (e.g. lecture rooms for lecture sessions, computer labs for lab sessions requiring `computer_lab`)
- All sessions must fit within the section's preferred time window (`preferred_start_time` to `preferred_end_time`)
- If a subject's required hours don't fit within the section's preferred window, the solver reports `INFEASIBLE` with a specific explanation rather than producing an invalid schedule

## Constraints Not Yet Implemented

These were part of the original planning but are **not currently enforced** by the solver:

- Maximum teaching load per faculty member (schema has a `max_teaching_load` column, default 24, but the solver does not currently check or enforce it)
- Mandatory lunch break in the daily schedule
- Priority/preference weighting for senior faculty
- Faculty scheduling *preferences* as a soft constraint distinct from hard availability (currently, availability is treated as a hard rule only — there's no separate "preferred but not required" layer)

## Notes

Earlier planning documents referenced a 21-unit maximum teaching load; the actual schema default is 24 hours/week (`max_teaching_load` column on the `faculties` table). This should be reconciled — confirm the correct intended value before implementing the load-limit constraint.