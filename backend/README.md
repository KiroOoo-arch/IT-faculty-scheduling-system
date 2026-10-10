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
  unreachable engine never destroys the admin's work. Archiving the old draft, creating its
  replacement and inserting the replacement's sessions are a single transaction, so a persistence
  failure rolls the whole replacement back
- Generation is serialized per academic term by a cache lock; a run that cannot take the lock within
  its wait answers `409` rather than racing the run that holds it
- A generated plan is checked against every other section's `draft`, `approved` and `published`
  sessions in the **same academic year and semester** before anything is written. A clash is refused
  with `422` and the section's existing draft is left exactly as it was

## The five protection layers

1. **Solver constraints** — no room/faculty double-booking, no section self-overlap, availability
   and load ceilings, room type + capacity matching, break avoidance
2. **Generation-time conflict gate + term lock** — one generation run per academic year and semester
   at a time (`409` when contended), and the engine's result is checked against the same term's
   other schedules — drafts included — before it is persisted (`422`)
3. **Manual-edit validation** — `PUT /api/schedules/sessions/{id}` validates the full resulting
   state, including other sections' draft/approved/published sessions in the same term
4. **Publish conflict gate** — cross-section cross-check against approved/published schedules at
   publish time
5. **Published-reference delete guard** — faculty/subject/room/section used by a published schedule
   returns `409` unless confirmed with `?force=1`

> Generation is still per-section, but within a term it is serialized and draft-aware: a new draft
> treats every other section's draft as a live booking, and a plan that clashes is refused before it
> is written — so a batch of drafts for one term can no longer overlap each other.

## Errors from the AI engine

| Engine result | API answers | Why |
|---|---|---|
| Unreachable | `502` | genuine upstream fault |
| `5xx` | `502` | genuine upstream fault |
| `4xx` (e.g. "section has no subjects assigned") | `422` with the engine's own reason | a data problem the admin can fix, not a broken server |

## Settings

`GET/PUT /api/settings` owns the midday break (`lunch_start`, `lunch_end`, `lunch_enabled`). It is a
hard constraint in the solver, so it is read on every generation.

`config/scheduling.php` sizes the generation lock: `ttl` (180 s) is how long a run may hold it before
it expires on its own, and `wait` (30 s) is how long a run waits for a contended lock before answering
`409`. Both are overridable with `SCHEDULE_GENERATION_LOCK_TTL` and `SCHEDULE_GENERATION_LOCK_WAIT`.
The lock lives in the cache store, which defaults to `database`; a cross-process guarantee needs a
shared store, so an in-process store such as `array` only protects a single process.

## Commands

```bash
composer install
cp .env.example .env      # set DB_CONNECTION=pgsql and the DB_* values
php artisan key:generate
php artisan migrate --seed
php artisan serve         # http://127.0.0.1:8000

php artisan test          # 127 passed, 537 assertions

# just the conflict regression files
php vendor/bin/phpunit tests/Feature/ScheduleGenerationConflictTest.php \
    tests/Feature/ScheduleSessionConflictTest.php     # 25 tests, 101 assertions

# optional realistic demo dataset (idempotent)
php artisan db:seed --class=DemoDataSeeder
```

See [`README.md`](../README.md) for full setup and the demo login.
