# Project Progress Handoff

Project: IT Faculty Scheduling System
Branch: reconcile/2x-doc-drift-and-seal
Last verified commit: 29bb9e1 (fix: classify the demo dataset so the documented seed flow generates)

## Implemented areas reported
- Multi-session scheduling
- Subject classification: Major, Minor/Shared, Unclassified
- Department ownership and authorized cross-department access
- Subject review queue and bulk classification workflow
- Department preflight and AI-engine scheduling guards
- Associated frontend, backend, tests, and documentation

## Latest reported checks (all measured 2026-10-13)
- Frontend: `npm run build` (`tsc -b && vite build`) succeeded.
- AI engine: `python -m unittest discover -s tests -t tests` — 171 tests, OK.
- Backend certification: VERIFIED. `php artisan test` — 289 tests, 1402 assertions, all
  passing, exit 0 — run against the isolated `scheduling_system_testing` database after
  confirming that database was empty and at the latest migration. The PostgreSQL-only
  preflight test passed as part of that run.
- Documentation drift: `python documentation/tools/check_doc_drift.py` re-measured the
  suites and the affected documents were reconciled to the measured numbers.
- Live application run: backend :8000, AI engine :8001, frontend :5173 on a disposable
  database — login, terms acceptance, generation (OPTIMAL), approve, publish, unpublish,
  the draft-publish guard, print view, reports, and settings all verified.

## Database safety status (updated 2026-10-13)
- The backend suite ran against `scheduling_system_testing` only, as configured in
  `backend/phpunit.xml` (`DB_CONNECTION=pgsql`, `DB_DATABASE=scheduling_system_testing`).
  That database was verified empty and at the latest migration before the run, and every
  test runs inside RefreshDatabase's transaction.
- The local application run used a purpose-created disposable database,
  `scheduling_system_localrun_20261013`, so no existing database was migrated, seeded, or
  otherwise changed to make the demo work.
- The development database `scheduling_system` holds live development data (3 users,
  16 sections, 30 subjects, 1 schedule, 23 faculty) and is four migrations behind the
  current set. It was observed read-only: not migrated, not seeded, and not used by the
  tests. The department and classification features will not work there until those four
  migrations are applied.
- No production database, PostgreSQL configuration, or deployment setting was changed.

## Next steps
1. Apply the four pending department/classification migrations to `scheduling_system` when
   its data is meant to be kept, then run `php artisan departments:preflight` before
   activating generation.
2. Decide whether `backend/phpunit.isolated.xml` is expected: `phpunit.b1-sqlite.xml` cites
   it, but it is absent from this checkout and from every branch of the repository.
3. Decide how the untracked `backend/app/Console/Commands/DepartmentPreflight.php` relates
   to the committed `DepartmentsPreflightCommand.php` — both declare the same
   `departments:preflight` signature, and only the committed one currently wins.
4. Plan production activation and deployment separately, after the defense and with
   explicit approval.

## Notes
- No passwords, credentials, database contents, raw terminal logs, advertisements, or
  speculative claims are included in this handoff.
- All Phase 2S findings are treated as reports unless backed by actual verified output.
- No database, PostgreSQL configuration, or deployment settings were changed in this phase.
