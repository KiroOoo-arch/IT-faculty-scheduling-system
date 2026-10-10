# AI Engine — OR-Tools CP-SAT Scheduling Solver

The scheduling engine for the IT Faculty Scheduling System. It reads a section's constraints straight
from PostgreSQL, solves them with Google OR-Tools CP-SAT, and returns the placement — or the reason a
session could not be placed. It never writes to the database; Laravel persists results.

**Stack:** Python, FastAPI, Google OR-Tools (`ortools==9.15.6755`). Runs on **port 8001**.

## Structure

```
api/          FastAPI app (app.py) — reads the DB, calls the solver
solver/       scheduler.py (pure CP-SAT), time_slots.py (minute helpers)
tests/        unittest suite
prototype/    early solver prototype
data/         sample/reference data
examples/     example payloads and results
exports/      generated exports
seed_rooms.py helper for seeding rooms
```

## Install and run

```bash
python -m venv .venv
# Windows: .venv\Scripts\activate     macOS/Linux: source .venv/bin/activate
pip install -r requirements.txt
python -m uvicorn api.app:app --host 127.0.0.1 --port 8001
```

Database credentials come from `ai-engine/.env` (`DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`) — the same database the Laravel backend uses, configured separately.

## API

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/health` | Health check plus database connectivity |
| `POST` | `/generate-schedule/{section_id}` | Solve a section's timetable |

### Errors

| Status | Condition |
|---|---|
| `404` | Unknown section |
| `400` | Section has no subjects assigned |
| `400` | No faculty can teach any of the section's subjects |
| `400` | No available rooms |

Laravel surfaces those `400`s as `422` carrying this message, because they are data problems the
admin can fix rather than server faults. An unreachable engine stays `502`.

## What the engine reads

- **Section** — `preferred_days`, `preferred_start_time/end_time`, `student_count`
- **Subjects** — via `section_subjects` (`lecture_hours`, `lab_hours`, `lab_room_type`)
- **Faculty** — via `faculty_subjects`, requiring `is_active = true` and a non-null `name`
- **Availabilities** — `faculty_availabilities` day/window rows; a faculty with none falls back to
  the section's own days
- **Rooms** — only `status = 'available'`
- **Existing load** — hours already committed in *other* sections **of the same academic year and
  semester**, for schedules with status `draft`, `approved` or `published`
- **Break** — the `settings` row for the midday break (hard constraint)

## Solver behaviour

- Reasons in **minutes since midnight** and only starts sessions on a **30-minute** boundary, so a
  07:30 start survives
- Maximises the number of sessions placed; unplaceable sessions come back with a reason instead of
  failing the whole run
- Refuses to place a session on a faculty member or room another section in the **same academic year
  and semester** already holds. `draft` schedules count alongside `approved` and `published` ones, so
  two sections planned in one sitting cannot claim the same slot; the target section is always
  excluded, so its own previous draft never blocks its regeneration
- Budget: **`max_time_in_seconds = 15.0`** (`solver/scheduler.py`)
- Statuses: `OPTIMAL`, `FEASIBLE`, `PARTIAL`, `INFEASIBLE`, `ERROR`

Rooms are matched with an exact type comparison and must seat the whole section, so a missing or
too-small lab room is reported as an unschedulable session rather than a crash.

## Tests

```bash
python -m unittest discover -s tests -t tests     # 118 tests, OK
```

`-t tests` names the top-level directory. `tests/` has no `__init__.py`, and Python 3.11+ refuses to
start discovery in a directory it cannot import, so the plain `-s tests` form fails with
`ImportError: Start directory is not importable`. On Windows, prefix the command with
`./.venv/Scripts/python.exe` when the virtual environment is not activated.
