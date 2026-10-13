# Backend — Laravel API

The API for the IT Faculty Scheduling System: authentication, master-data CRUD, the schedule
approval workflow, and all database writes.

**Stack:** Laravel 13, PHP 8.5, PostgreSQL, Laravel Sanctum (Bearer tokens). Runs on **port 8000**.

## Responsibilities

- Token authentication and admin-only authorization
- CRUD for users, faculty, subjects, rooms, sections
- Calling the AI engine and **persisting** its result
- The schedule lifecycle and its guards
- Reports and printable-schedule data

The API is the only service that writes to the database. The engine reads for itself but never
writes.

## Authorization model

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('admin')->group(function () {
        // all management, schedule, settings and report routes
    });
});
```

Every route except `POST /api/login` needs a valid token, and everything besides `/logout` and `/me`
additionally requires the `admin` role. Faculty have no accounts — they are records.

Login answers `401` for bad credentials, `403` for a valid non-admin account, and `422` for a
malformed body, so clients can tell a wrong password apart from a broken request.

## The schedule lifecycle

```
draft --approve--> approved --publish--> published --unpublish--> draft
  |                    |
  +-- reject --> rejected
  +-- delete (any state except published)
```

Notable rules:

- `approve` / `publish` / `reject` / `unpublish` each refuse a schedule in the wrong state with `422`
- `publish` runs a cross-section conflict check first and refuses on a clash
- A **published** schedule cannot be deleted — unpublish it first
- Generating supersedes the previous *draft* only after a usable result exists, so a failed or
  unreachable engine never destroys the admin's work

## The five protection layers

1. **Solver constraints** — no room/faculty double-booking, no section self-overlap, availability
   and load ceilings, room type + capacity matching, break avoidance, against every other section's
   `draft`, `approved` and `published` sessions in the same academic year and semester
2. **Term lock + pre-write conflict gate** — one generation run per academic year and semester at a
   time (`409` when contended), and the engine's plan is cross-checked against that term's other
   schedules before anything is written (`422` on a clash, leaving the existing draft untouched)
3. **Manual-edit validation** — `PUT /api/schedules/sessions/{id}` validates the full resulting state,
   including other sections' draft/approved/published sessions in the same term
4. **Publish conflict gate** — cross-section cross-check at publish time
5. **Published-reference delete guard** — faculty/subject/room/section used by a published schedule
   returns `409` unless confirmed with `?force=1`

> Generation is per-section, but the **term** is the unit that is serialized: a run holds a cache lock
> for its academic year and semester, so a second run for the same term waits (and answers `409` if the
> wait expires) instead of racing the one in flight. A new draft avoids rooms and faculty held by **every
> other section's** `draft`, `approved` and `published` sessions in that term, and the generated plan is
> cross-checked before it is written. The publish gate remains the final check before a clash reaches
> students.

## Subject classification and department ownership

Every subject carries a review outcome in `subjects.classification`:

| Value | Meaning | Owner | Schedulable where |
|---|---|---|---|
| `major` | a reviewed major subject | exactly one department (`subjects.department_id`) | sections whose department holds a `subject_department_access` grant for it |
| `minor` | a reviewed shared / service subject | none | every section, including one with no department |
| `unclassified` | not reviewed yet — the column default | none | nowhere: assignment and generation both refuse it |

A major's authorization lives in **`subject_department_access`** (one row per department allowed to
schedule it, `PRIMARY KEY (subject_id, department_id)`). The owner's row is mandatory; a second
department can hold one too, which is how `ELEC201` — owned by ELEC (BindTech Electronics) — is also
scheduled by the BIT (Industrial Technology) sections. Authorization is data, not a comparison of two
columns, so a shared major needs no special case anywhere.

Before the code and the label: `departments.code` (`BIT`, `ELEC`) is the stable handle ownership, access
grants, section name prefixes and `?department_id=` all key on, and `departments.name` is only ever
printed — currently *Industrial Technology* and *BindTech Electronics*. Renaming a department is
therefore a one-line edit to `DepartmentSeeder::DEPARTMENTS` plus a re-seed; the seeder matches on
`code`, so it renames the existing row instead of adding a second department, and it touches no subject,
grant, section or schedule. `php artisan departments:preflight` reports departments by code, so the
rename never affects whether a database is safe to activate.

The database enforces the invariant set, so no importer or manual SQL session can create a major with no
owner, a minor that claims one, or an unknown classification value.

`unclassified` is the default and is never inferred as `minor`: an unreviewed subject grants nothing,
which is the opposite of the old behaviour where a null `department_id` meant "shared" whether or not
anyone had reviewed it.

The same rule is applied in four places, all reading `Subject::isSchedulableBySection()`:

- the section editor, when a section's subject list or department changes;
- the scoped subject list (`GET /api/subjects?department_id=N`);
- the pre-generation gate (`POST /api/schedules/generate/{section}`);
- the multi-session validator (`POST /api/schedules/{schedule}/sessions`);

and the AI engine re-applies it as a second line of defence, reading the same access table.

### The deployment gate

```bash
php artisan departments:preflight      # read-only, safe to re-run, exit 0 required
```

It exits **`1`**, naming the reasons, when the migrations are missing, a department the seeder expects is
absent, a listed major is still unclassified or owned by the wrong department, a listed major's owner
holds no access grant, a minor subject holds one anyway, or an existing `section_subjects` row pairs a
section with a subject it may not schedule (a major of an unauthorized department, or an unclassified
subject). It exits **`0`** when the reviewed classification is complete and consistent. It never writes.

A subject that nobody has reviewed is reported as a **warning** rather than a failure, because it blocks
nothing until a section holds it — at which point the assignment check fails and names it.

### Activation sequence

The migrations and the backfill ship together. Between them every subject is `unclassified`, so nothing
can be scheduled: the state fails closed instead of running unrestricted.

```bash
php artisan migrate                                      # 1. schema
php artisan db:seed --class=DepartmentSeeder              # 2. classification + access backfill (idempotent)
php artisan departments:preflight                         # 3. validation — must exit 0 and print READY
curl -fsS http://127.0.0.1:8001/ready                     # 4. engine readiness — must be 200
```

Re-run step 3 after any bulk import or section reshuffle.

### Reviewed curriculum

The backfill classifies the approved curriculum: **17 majors** (15 owned by BIT, `ELEC` and the
cross-department `ELEC201` owned by ELEC) and **13 minor / shared subjects**. `ELEC201` is owned by ELEC
with access grants for both ELEC and BIT. A code on neither list stays `unclassified` until someone
reviews it through the API.

## Errors from the AI engine

| Engine result | API answers | Why |
|---|---|---|
| Unreachable | `502` | genuine upstream fault |
| `5xx` | `502` | genuine upstream fault |
| `4xx` (e.g. "section has no subjects assigned") | `422` with the engine's own reason | a data problem the admin can fix, not a broken server |

## Settings

`GET/PUT /api/settings` owns the midday break (`lunch_start`, `lunch_end`, `lunch_enabled`). It is a
hard constraint in the solver, so it is read on every generation.

## Commands

```bash
composer install
cp .env.example .env      # set DB_CONNECTION=pgsql and the DB_* values
php artisan key:generate
php artisan migrate --seed
php artisan serve         # http://127.0.0.1:8000

php artisan test          # 289 passed, 1402 assertions

# the same suite against an isolated database copy (no shared database is touched)
./vendor/bin/phpunit -c phpunit.phase2_test.xml

# optional realistic demo dataset (idempotent)
php artisan db:seed --class=DemoDataSeeder
```

See [`README.md`](../README.md) for full setup and the demo login.
