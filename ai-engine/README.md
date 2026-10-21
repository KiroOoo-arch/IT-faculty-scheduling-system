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
| `GET` | `/health` | Liveness — is the process up, and can it open a database connection at all |
| `GET` | `/ready` | Readiness — is the classification schema applied and reviewed, so generation can be served |
| `POST` | `/generate-schedule/{section_id}` | Solve a section's timetable |

### Errors

| Status | Condition |
|---|---|
| `404` | Unknown section |
| `400` | Section has no subjects assigned |
| `400` | No faculty can teach any of the section's subjects |
| `400` | No available rooms |
| `422` | A major subject is not authorized for this section's department |
| `422` | A subject has not been classified yet, so it is schedulable nowhere |

Laravel surfaces those `400`s as `422` carrying this message, because they are data problems the
admin can fix rather than server faults. An unreachable engine stays `502`. The ownership `422` passes
through unchanged, though in practice the backend's own authorization check refuses the request first.

### Liveness and readiness

`/health` is **liveness only**: it opens a connection and closes it, and answers `ok` even when the
schema this engine needs is absent. That is deliberate — a liveness probe that failed on a missing
migration would make an orchestrator restart a perfectly healthy process in a loop.

`/ready` is what gates traffic. It answers **`200`** only when a connection opens, the classification
schema is applied (`departments`, `subject_department_access`, `subjects.department_id`,
`subjects.classification`, `sections.department_id`), at least one department is recorded *and* at least
one subject carries a review outcome. Otherwise it answers **`503`** with a `reason` of
`database_unavailable`, `schema_incompatible`, `ownership_not_seeded` or `database_error`, and `missing`
lists the schema items that are absent — which is the part that makes the answer actionable.

The "at least one reviewed subject" half of the readiness test matters: a database with departments but
no classified subject has nothing the engine can schedule, because every subject would be refused. That
is a half-finished activation, and readiness must not promise a service the engine cannot provide.

Neither endpoint repeats an exception message. A `psycopg2` error can carry the host, port, user and
database name from the connection string and both endpoints are unauthenticated, so `/ready` reports the
fact of a failure and never the SQL, the credentials or the connection string.

**Deployment follow-up — not implemented here.** This repository contains no deployment configuration:
no Dockerfile, compose file, orchestrator manifest or CI workflow, so there is nothing in-tree to wire
these endpoints to. Wherever the engine is deployed, point the **liveness** probe at `/health` and the
**readiness** probe at `/ready`, and let readiness hold traffic back instead of restarting the process.
Until that is done, the degraded states below are served rather than refused at the door: a
pre-migration database fails readiness, and a migrated but unreviewed one refuses every subject.

## Subject classification and department ownership

Every subject carries a review outcome (`subjects.classification`):

- **`minor`** — a shared / service subject: no owner, open to every department
- **`major`** — owned by one department (`subjects.department_id`) and schedulable only for sections
  whose department is authorized for it
- **`unclassified`** — not reviewed yet: schedulable nowhere. A subject row that does not carry the
  column at all is read the same way, so the guard fails closed

A major's authorization lives in `subject_department_access`, one row per department allowed to schedule
it. The owner's row is mandatory; a second department may hold one too, which is how `ELEC201` — owned by
ELEC (BindTech Electronics) — is scheduled by the BIT (Industrial Technology) sections as well. Department
codes are what this guard compares; the department *names* are display labels and appear only in the
messages it returns. That is why the guard reads the grants
rather than comparing `subjects.department_id` to `sections.department_id`: a major shared across
departments is a legitimate state, and it is recorded as data rather than special-cased here.

The guard runs inside `POST /generate-schedule/{section_id}` before the solver is reached, and raises
**`422`** naming the subject, its owning department and the target section — or, for an unreviewed
subject, saying that it has not been classified yet. It decides on **ids and grants**, never on the
joined department name, so a department row that cannot be resolved can never turn a major into a shared
subject, and a missing name never widens access. A section with no department of its own has no claim on
any major and can only take minor subjects. The Laravel backend applies the same rule when a section's
subject list or department changes, and when a subject's classification, owner or grants change, so a
bypassed assignment is usually refused before the engine is called at all.

### Behaviour across database states

The `sections` and `subjects` queries `LEFT JOIN departments`, so behaviour depends on how far a
deployment has got. **The order matters: migrate, then seed, then start the engine.**

| State | `departments`, `subject_department_access` | `subjects.classification` | `POST /generate-schedule/{id}` |
|---|---|---|---|
| Before migrations | tables absent | column absent | **`500`** `Database error: relation "departments" does not exist`. Generation is impossible. `GET /health` still reports `ok`, because it only opens a connection — it cannot see this. `/ready` answers `503 schema_incompatible`. |
| Migrated, not seeded | tables exist, empty | every row `unclassified` | **Nothing can be scheduled.** Every subject is unreviewed, so the guard refuses each one with `422` naming a missing classification. `/ready` answers `503 ownership_not_seeded`. |
| Seeded | populated | `major` / `minor` | **Full enforcement.** An unauthorized major is refused with `422`; a major granted to the section's department is accepted, and minor subjects are accepted everywhere. |

A migrated-but-unseeded database therefore fails closed. It is not a silent state any more: the subjects
themselves carry the missing decision, `/ready` refuses to serve it, and `departments:preflight` reports
it as a failure with the seeder command to run.

Two degraded shapes are handled deliberately:

- **A section whose department row cannot be resolved** — `department_id` set, but no matching
  `departments` row — is still compared by id, and is described as `department #<id>` instead of being
  reported as having no department.
- **A subject whose department row cannot be resolved** is still a major: refused for a foreign section,
  and named as `department #<id>` in the error.

### Activating it safely

Classification is only as good as the backfill, and the failure is now loud: between the migration and
the seeder every subject is `unclassified`, so nothing can be scheduled, `/ready` answers `503`, and
generation is refused rather than run unrestricted. Activate in this order, and treat the preflight as
the gate:

```bash
php artisan migrate                                    # 1. schema
php artisan db:seed --class=DepartmentSeeder            # 2. classification + access backfill (idempotent)
php artisan departments:preflight                       # 3. validation — must exit 0, and print READY
curl -fsS http://127.0.0.1:8001/ready                   # 4. engine readiness — must be 200
```

`departments:preflight` is read-only and safe to re-run. It exits **`1`** and names the reasons when the
migrations are missing, a department the seeder expects is absent, a listed major is still unclassified
or owned by the wrong department, a listed major's owner holds no access grant, a minor subject holds
one anyway, or a section holds a subject it may not schedule — a major of an unauthorized department, or
an unclassified subject. It exits **`0`** when the reviewed classification is complete and consistent.
Run it after the backfill, and again after any bulk import or section reshuffle.

A subject nobody has reviewed is a **warning**, not a failure, as long as no section holds it: it cannot
be scheduled, and nothing breaks until one does. The moment a section holds one, the preflight fails and
names it.

**Known gap.** The preflight is a deployment gate, not a policy authority: it compares the database
against `DepartmentSeeder`'s lists, so it can only speak about the codes those lists name. A code on no
list stays `unclassified` — the safe state, and deliberately never guessed at — until someone reviews it
through the API.

## What the engine reads

- **Section** — `preferred_days`, `preferred_start_time/end_time`, `student_count`, `department_id`
- **Subjects** — via `section_subjects` (`lecture_hours`, `lab_hours`, `lab_room_type`,
  `classification`, `department_id`)
- **Authorization** — `subject_department_access` (one row per department allowed to schedule a major)
  and `sections.department_id` (which department a section belongs to)
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
python -m unittest discover -s tests -t tests     # 171 tests, OK
```

Three files carry the classification coverage. `tests/test_readiness.py` pins which database states are
served at all. `tests/test_department_ownership.py` pins what the guard does.
`tests/test_department_guard_sensitivity.py` pins the *pinning*: it rewrites one guard decision at a time
in an in-memory copy of `api/app.py`, re-runs the ownership suite against that mutant, and fails unless
the suite catches it. A mutation that survives means the rule it inverted is no longer covered, so a
green run is evidence the ownership tests are load-bearing rather than decorative. Each mutation also
declares the minimum number of tests that must catch it, so coverage thinning trips the check instead of
passing quietly. Neither file opens a database, and `api/app.py` is never written.

`-t tests` names the top-level directory. `tests/` has no `__init__.py`, and Python 3.11+ refuses to
start discovery in a directory it cannot import, so the plain `-s tests` form fails with
`ImportError: Start directory is not importable`. On Windows, prefix the command with
`./.venv/Scripts/python.exe` when the virtual environment is not activated.
