# Progress Report — IT Faculty Scheduling System

**Report date:** 21 October 2026
**Branch:** `reconcile/2x-doc-drift-and-seal`
**HEAD at time of writing:** `c84feaf` — *feat: instructor-demo frontend — migrate to new auth module*
**Scope of this document:** documentation only. No application code, test, configuration, database, or dependency was changed to produce it. The guarded backend test described in §5 remains blocked and was not run.

> **How to read the evidence labels used below**
> - **[Verified 2026-10-21]** — observed directly in the inspected local checkout by a read-only command or file inspection.
> - **[Historical]** — measured on an earlier date and carried forward from existing notes; not re-measured for this report.
> - **[Reported]** — supplied as a finding or symptom and still awaiting the evidence that would confirm it.

---

## 1. Status at a glance

| Area | State |
|---|---|
| API surface (28 registered `api/*` GET endpoints) | 25 respond `200`; three fail with `500` because of missing schema [Verified 2026-10-21] |
| Frontend typecheck and lint | `tsc -p tsconfig.app.json --noEmit` exit `0`; `eslint .` exit `0` [Verified 2026-10-21] |
| Multi-session editor | Present, routed, linked from the dashboard, four backend endpoints registered, published-schedule edits refused [Verified 2026-10-21] |
| Login from `localhost:5173` | Succeeds; login from `127.0.0.1:5173` blocked by CORS in the inspected environment [Reported + reproduced locally] |
| Department / classification feature | Code and UI shipped; database schema incomplete in the inspected environment, so the feature is inert there [Verified 2026-10-21] |
| Backend test suites | Not run for this report; historical measurement retained in §3 [Historical] |
| Guarded `DepartmentPreflightTest` case | **Blocked**; isolation requirements unresolved (§5) |
| Documentation-only commit | Prepared locally; not pushed (§8) |

---

## 2. Repository state at time of writing

- **Branch:** `reconcile/2x-doc-drift-and-seal`, HEAD `c84feaf`.
- **Working tree:** 79 entries — 58 modified, 2 added to the index, 1 added then modified, 18 untracked. Nothing in this report required modifying them.
- **Pre-existing staged work (not part of this document's commit):**
  `backend/phpunit.pgsql-guarded.xml`, `backend/tests/bootstrap.pgsql-guard.php`,
  `backend/tests/support/DatabaseTargetGuard.php`, `backend/run_guarded_test.ps1`.
  These remain staged exactly as they were found.
- **Stashes:** three, left untouched — one WIP snapshot on this branch taken at `dd297c4`, and two `ai-engine` snapshots.
- **Documentation structure:** `documentation/` holds the specification set (`Requirements.md`, `SRS.md`, `Modules.md`, `System-Architecture.md`, `API.md`, `Constraints.md`, `Program-Flow.md`), the user and defense material (`System-User-Manual.md`, `System_Defense_Guide.md`/`.docx`, `System_Complete_Guide.md`/`.docx`), the running records (`Progress-report.md` — September 2026, `PROGRESS_HANDOFF.md`, `Implementation_Status.md`, `Bug-Fix-Log.md`), the screenshot set, and `documentation/tools/` (secret scanner and documentation-drift checker with their own test files).
- **New in this task:** this dated report. Existing documents, including the already-modified `PROGRESS_HANDOFF.md` and `Implementation_Status.md`, were read but deliberately not edited, so no unrelated working-tree change is absorbed into the documentation commit.

---

## 3. Previously completed work (evidence-checked)

The items below are supported by the current source, current tests, or commit history. Nothing here is inferred from intent alone.

### 3.1 Half-hour scheduling grid — implemented
- `backend/app/Http/Controllers/ScheduleSessionController.php` declares `private const SLOT_MINUTES = 30` (`:30`) and enforces it in two places for hand-entered meetings: the start must fall on a 30-minute boundary (`:462`), and the duration must be a whole number of 30-minute slots (`:468`).
- The solver documents the same grid: `ai-engine/solver/scheduler.py:13` and `:388` ("every legal start … on the 30-minute grid"), `ai-engine/solver/time_slots.py:11` and `:86`.
- Consequence recorded: a manual edit cannot introduce a start time the generator would never produce.

### 3.2 Configurable lunch break — implemented
- `backend/app/Models/Setting.php` declares `LUNCH_START_KEY`, `LUNCH_END_KEY`, `LUNCH_ENABLED_KEY` (`:18-20`) and a `lunch()` accessor (`:44`).
- `backend/app/Http/Controllers/SettingController.php` validates `lunch_start`, `lunch_end`, `lunch_enabled` (`:30-32`) and returns them with the settings payload.
- `ai-engine/tests/test_lunch_break.py` exists as the engine-side regression file for the same behaviour.
- The setting is editable through the settings endpoint and is surfaced in the UI; the behavior is configurable rather than hard-coded.

### 3.3 Multi-session editor — implemented and wired end to end
| Layer | Evidence [Verified 2026-10-21] |
|---|---|
| Component | `frontend/src/pages/admin/ScheduleSessionEditor.tsx`, 816 lines |
| Route | `frontend/src/App.tsx:66` → `/admin/schedules/:scheduleId/sessions` |
| Navigation | `frontend/src/pages/admin/AdminDashboard.tsx:431` — a "Sessions" link on draft, approved and published rows |
| API | `GET/POST/PUT/DELETE api/schedules/{schedule}/sessions` plus `PUT api/schedules/sessions/{session}` (13 schedule routes registered) |
| Payload contract | Editor sends `{session_ids: […]}` (`:435`), `{sessions: [{id, …}]}` (`:439`), `{sessions: […]}` (`:445`); the controller validates exactly those keys (`ScheduleSessionController.php:240-248`, `:297-306`, `:378-381`) |
| Published-schedule safeguard | `POST`, `PUT` and `DELETE` against a published schedule return `422` with "Only a draft schedule can be edited"; the page renders the same rule as a read-only banner |
| Regression coverage | `backend/tests/Feature/ScheduleSessionMultiSessionTest.php` (79 declared cases in that file) |

### 3.4 Scheduling safeguards — implemented
- **Publish-time cross-section conflict gate:** `ScheduleApprovalController::findPublishConflicts()` (`:190`) refuses publication with a `conflicts` list when a room (`:208`) or a faculty member (`:213`) is already booked.
- **Generation gate:** `ScheduleController::generate` loads the section's subjects with their department (`:29`) and blocks generation when an assigned subject does not match the section's year level, semester or department.
- **Hand-placement gate:** `ScheduleSessionController::reviewBatch()` re-applies the same ownership rule server-side (`:487-495`), so the editor cannot place a subject the section was never assigned.
- **Published-reference protection:** `backend/tests/Feature/PublishedReferenceGuardTest.php` (11 declared cases) pins the rule that referenced master data cannot be silently removed under a published timetable.
- **Master-data deletion guards:** `backend/tests/Feature/MasterDataDeletionTest.php`; **schedule lifecycle:** `ScheduleDeleteLifecycleTest.php`.

### 3.5 Authentication, terms and reports — implemented
- Admin-only token authentication (`AuthController`), versioned Terms of Use acceptance with a per-account read/record endpoint (`LegalController`), and the admin acceptance roster; `backend/tests/Feature/TermsAcceptanceTest.php` (16 declared cases).
- Reports: schedule status, faculty workload, room utilization, section summary and generation logs (`ReportController`), plus the printable faculty schedule.
- CI: frontend lint/build, secret scan, and guard/gate self-tests added in `dd297c4`.

### 3.6 Commit trail for the above
`7edde09` (department preflight ownership and access grants), `5a1f356` (SQLite preflight harness constraints), `29bb9e1` (demo dataset classification), `edc246d` (documented test counts reconciled with measured suites), `dd297c4` (CI additions), `c84feaf` (auth module migration). The multi-session work landed on `feature/multi-session-editor`, whose tip (`9cfdada`) is an ancestor of the current HEAD — i.e. fully integrated, not pending.

### 3.7 Historical validation results — not re-measured
Recorded in `documentation/PROGRESS_HANDOFF.md` as measured on **2026-10-13**:

- Backend: 289 tests, 1,402 assertions, all passing (exit `0`), run against an isolated `scheduling_system_testing` database.
- AI engine: 171 tests, OK.
- Frontend: `npm run build` (`tsc -b && vite build`) succeeded.
- Live run on a purpose-created disposable database: login, terms acceptance, generation (`OPTIMAL`), approve, publish, unpublish, draft→publish guard, print view, reports and settings verified.

**These figures are retained as historical.** They were not re-run for this report, and no test suite was executed (see §5 and §7). A static inventory of the current checkout finds 26 Feature test files (283 `#[Test]`-attributed cases plus one `test_`-prefixed method, including 25 declared cases in `DepartmentPreflightTest.php`), 1 unit test file, and 8 AI-engine test files containing 171 `test_` functions. Declaration counts are not a substitute for a measured run.

---

## 4. Latest diagnostic findings (21 October 2026)

> **All findings in this section are reported local-stack findings.** They were observed in the single inspected checkout, whose frontend and backend both run on one machine. **They are not yet verified on the Main PC**, and none of them is a claim about that machine's behaviour. The Main PC comparison is item 1 of the action plan.

### 4.1 Login succeeds at `localhost:5173` and fails at `127.0.0.1:5173` (CORS allowlist)
- **Confirmed evidence (local):** `backend/config/cors.php:9-13` lists `FRONTEND_URL` (currently `http://localhost:5173`), `http://localhost:5173` and `http://localhost:3000`. A preflight `OPTIONS /api/login` returns HTTP `204` with `Access-Control-Allow-Origin: http://localhost:5173` for that origin and **no** such header for `http://127.0.0.1:5173`, `http://localhost:4173`, a LAN origin, or `null`. In the browser at `127.0.0.1:5173` the console reported *"blocked by CORS policy … No 'Access-Control-Allow-Origin' header"*, the `POST` failed with `net::ERR_FAILED`, and the page displayed "Failed to fetch" — the `TypeError` from `fetch` surfaced at `LoginPage.tsx:190-196`. The same credentials from `localhost:5173` completed sign-in.
- **Still unverified:** whether the Main PC is opened at an origin outside this allowlist. The symptom is identical for four different causes, so the Main PC's console line, address bar and one preflight probe are required before naming it.
- **Recommended fix (not applied):** make the allowlist origin-complete — add `http://127.0.0.1:5173` and the preview port, and allow an environment-supplied list for LAN use — or restrict the application to a single documented origin.
- **Regression test:** a CORS feature test asserting the `Access-Control-Allow-Origin` echo for each supported origin and its absence for an unlisted one.

### 4.2 Frontend calls `127.0.0.1:8000` directly
- **Confirmed evidence (local):** `frontend/src/context/auth.ts:9` resolves `import.meta.env.VITE_API_BASE_URL ?? 'http://127.0.0.1:8000/api'`, and no `frontend/.env` exists in the checkout, so the fallback is always used. The built bundle in `frontend/dist` carries the same literal.
- **Still unverified:** the Main PC's deployment shape. If the browser and the API are not on the same computer, `127.0.0.1` points at the client and produces the same "Failed to fetch" independently of CORS.
- **Recommended fix (not applied):** ship a documented `VITE_API_BASE_URL` example, and fail visibly at startup when the value is absent in a non-loopback deployment.
- **Regression test:** a unit test for the base-URL resolver plus a build-time check that the variable is set in CI.

### 4.3 Signing in revokes other sessions
- **Confirmed evidence (local):** `backend/app/Http/Controllers/AuthController.php:40` calls `$user->tokens()->delete()` before issuing a token. This was reproduced locally: a second sign-in invalidated the browser's token, the next request returned `401`, and the fetch interceptor (`frontend/src/context/auth.ts:56-70`) cleared storage and redirected with no message.
- **Still unverified:** whether the Main PC and another machine share one administrator account — the condition that makes this visible.
- **Recommended fix (not applied):** decide the intended policy. Either keep one token per device, or keep single-session and surface it ("signed in on another device") instead of silently redirecting.
- **Regression test:** two sequential logins for one account, asserting the chosen policy (both tokens valid, or a distinguishable `401`).

### 4.4 Four migrations pending; department tables and classification columns absent
- **Confirmed evidence (local):** `php artisan migrate:status` lists four pending migrations — `2026_10_11_000001_create_departments_table`, `2026_10_11_000002_add_department_scope_to_subjects_and_sections`, `2026_10_12_000001_add_classification_to_subjects`, `2026_10_12_000002_create_subject_department_access_table`. Schema inspection confirms `departments` and `subject_department_access` do not exist, and that `subjects` lacks `classification`/`department_id` while `sections` lacks `department_id`.
- **Still unverified:** the Main PC's migration state, and whether its database is the one intended to keep its data.
- **Recommended fix (not applied):** apply the four migrations to the intended database after a backup decision, then run the read-only preflight command before re-enabling generation. This is a mutation and requires separate approval.
- **Regression test:** a CI check that fails when migrations are pending, plus a model-versus-schema drift test for eager-loaded relations.

### 4.5 Some API endpoints return HTTP 500
- **Confirmed evidence (local):** of 28 registered GET endpoints, 25 returned `200`; `/api/departments`, `/api/subjects` and `/api/subjects/{id}` returned `500` with `SQLSTATE[42P01] … relation "departments" does not exist`. The failing query is the eager load at `SubjectController.php:255` (`with(['faculties', 'department', 'departmentAccess'])`). Unauthenticated probes behave correctly: `/api/legal` `200`, `/api/login` `401`, `/api/subjects` `401`, `/api/me` `401`.
- **Still unverified:** the Main PC's endpoint status.
- **Recommended fix (not applied):** none beyond §4.4 — the failures are a schema prerequisite, not a code defect. Re-run the sweep after migrating.
- **Regression test:** an authenticated smoke test asserting `200` for every registered GET endpoint on a migrated database.

### 4.6 Misleading empty states instead of errors
- **Confirmed evidence (local):** `SubjectsPage.tsx:160-162` parses the response and sets `Array.isArray(data) ? data : []` without checking `res.ok`, so the `500` renders as "No subjects yet." and "0 major · 0 minor / shared · 0 unclassified" while the dashboard lists 14 sessions for those same subjects. The same shape appears at `FacultyPage.tsx:75-79` (whose subject picker is therefore empty) and at `UsersPage.tsx:22-26` (latent — that endpoint currently returns `200`). `SectionsPage.tsx:117-131` already handles the same failure correctly, so the pattern exists in the codebase.
- **Still unverified:** none; the behavior is deterministic given the `500`.
- **Recommended fix (not applied):** check `res.ok` and render the server message before replacing data.
- **Regression test:** component tests that stub `500` and assert an error banner rather than an empty state.

### 4.7 Dashboard faculty panel stuck on "Loading…"
- **Confirmed evidence (local):** `AdminDashboard.tsx:135` sets `setLoading(prev => prev)` in the `finally` block, and `:91`/`:99` do the same to `setSchedulesLoading`, so neither flag can change after mount. The panel renders "Loading…" permanently, with no roster and no full-time/part-time counts. Commit `c84feaf` introduced this: it replaced `setLoading(false)` with the no-op and wrapped the effect in `Promise.resolve().then(...)`, with a comment stating the intent to avoid the `set-state-in-effect` warning. `eslint .` exits `0`, so the regression is invisible to the linter.
- **Still unverified:** whether the same source is what the Main PC runs.
- **Recommended fix (not applied):** restore the real transitions (`setLoading(false)`, `setSchedulesLoading(true|false)`) and satisfy the rule by setting state after the awaited fetch rather than by neutralising it.
- **Regression test:** a component test asserting the faculty count replaces "Loading…" after a mocked response.

### 4.8 The multi-session editor exists and its safeguards hold
- **Confirmed evidence (local):** see §3.3 — file, route, navigation, four endpoints and the read-only/`422` behavior are all present, and the served `dist` bundle contains the editor's code.
- **Still unverified:** whether the Main PC shows it. If it does not, the likely causes are a stale pre-built bundle or a different checkout — not missing source.
- **Recommended fix (not applied):** none in code; verify which artifact the Main PC serves (§6, item 3).
- **Regression test:** a page test asserting the editor renders its budget table and read-only banner for a published schedule.

### 4.9 Housekeeping and local security observations
- [Verified 2026-10-21] An untracked cookie-jar file sits in the repository root and is **not** matched by `.gitignore`; a broad staging command would commit it. Its contents were not read.
- [Verified 2026-10-21] The inspected local backend returns framework exception details (model class and exception name) in API error responses — acceptable locally, not for a published build.
- [Verified 2026-10-21] Untracked scratch artifacts remain: a zero-byte `nul` file, `UsersPage.tsx.bak` (still importing the pre-refactor auth module), two `check_auth`/`fix_auth` scripts, two Python migration helpers, `gate3-restore-rehearsal.ps1` and two `.bak` copies of it, a suite log, a Python install log, `.local/`, `.staged_launcher.ps1`, `Python/`.
- [Verified 2026-10-21] `App.tsx:16` and `:65` still carry `// ← ADD THIS LINE` authoring markers, and `auth.ts:9` has a block comment closing on the same line as the statement.
- These are recorded for a later clean-up decision; none was changed here.

---

## 5. Outstanding safety task — the guarded preflight test (BLOCKED)

### 5.1 The test
`Tests\Feature\DepartmentPreflightTest::it_fails_when_the_department_migrations_have_not_been_applied`
(`backend/tests/Feature/DepartmentPreflightTest.php:165`).

Purpose: prove that the department preflight fails closed — that it reports "not ready" with actionable guidance when the department and classification schema is absent, instead of silently approving generation.

### 5.2 Why it may execute destructive database operations
1. **The class uses `RefreshDatabase`.** On the first test of a process against a non-in-memory database, Laravel's trait calls `migrateDatabases()` → `artisan migrate:fresh`, which **drops every table** in the configured database before re-migrating (`vendor/laravel/framework/src/Illuminate/Foundation/Testing/RefreshDatabase.php`, `refreshTestDatabase()`).
2. **The test itself issues DDL:** `DB::statement('DROP TABLE IF EXISTS subject_department_access CASCADE')` and `DB::statement('DROP TABLE IF EXISTS departments CASCADE')` (`:170-171`). The file's comment argues that PostgreSQL applies DDL transactionally and RefreshDatabase's rollback restores the tables for the next test. That argument holds only while the surrounding transaction is intact and the target database is disposable.

Either mechanism, pointed at a database whose data matters, is unrecoverable from the test's perspective.

### 5.3 Intended safeguards in `backend/run_guarded_test.ps1`
The launcher is written to refuse before it reads any credential:

- **Single test only:** the filter defaults to that exact test name and the launcher stops if `-Filter` names anything else (`:123`), and if more than one PHP file declares it (`:181`).
- **Fixed target constants:** database `scheduling_system_test_disposable_20261017`, role `its_test_runner`, host `127.0.0.1`, port pinned by `[ValidateSet('55433')]` — the port of the separate disposable cluster (`:22-31`).
- **Configuration pinning:** it parses `phpunit.pgsql-guarded.xml`, requires exactly one `<env>` entry per setting, and requires the forced value to equal the approved one (`:140-155`).
- **Guard-list cross-check:** it reads `ALLOWED_DATABASES` and `REFUSED_DATABASES` out of `DatabaseTargetGuard.php` and stops if the target is not allowlisted or a protected database is not refused (`:163-171`).
- **Server identity, not client claims:** the probe compares `current_database()`, the server port and other identity fields against the expected values (`:334-358`).
- **Credential integrity without disclosure:** a password digest is compared with `hash_equals()` and never printed (`:278`).
- **Cleanliness inventory:** it enumerates user objects across every non-system schema, reporting zeros explicitly, so a truncated result cannot be mistaken for a clean database (`:405-563`).

`tests/support/DatabaseTargetGuard.php` supplies the allow/refuse lists, the identity SQL (`select current_database()`), the DDL-rights probe (`has_schema_privilege(current_user, 'public', 'CREATE')`), environment-agreement checks for `DB_CONNECTION`/`DB_DATABASE`/`DB_URL`, and selection-aware DDL requirements so an ordinary non-destructive run does not need DDL rights. The guard itself has a shell-level self-test (`tests/shell/database-target-guard.test.php`).

### 5.4 Unresolved requirements (why the test stays blocked)
1. **Dedicated disposable PostgreSQL cluster.** The launcher targets port `55433` and one named database. That cluster was not verified to exist, be running, or be reachable in this session.
2. **Endpoint isolation.** The target must be loopback-only on the fixed port, with no pooled or shared endpoint through which another client could reach the same database.
3. **Concurrent-writer protection.** No other test run, seeder, application instance, or developer session may hold a connection to that cluster while it runs; `migrate:fresh` would race any of them.
4. **Database-cleanliness criteria.** Before the run the cluster must show zero user objects across all non-system schemas and the expected role/password digest; after the run the same inventory must be re-checked, so an aborted run leaves evidence rather than an assumption.
5. **Runtime verification of the launcher itself.** Its pre-credential checks and its probe have not been executed and observed in this session, so the launcher's own guarantees are unproven here.

### 5.5 Status
**Blocked.** The launcher, PHPUnit, migrations, SQL and database connections were **not** run for this documentation task. No result is claimed for the test. Completion requires the five items in §5.4 to be satisfied and separately authorised.

### 5.6 Two earlier open questions, resolved or corrected today
- **Duplicate preflight commands.** The committed `DepartmentsPreflightCommand.php:41` declares `departments:preflight:diagnostic`, while the untracked `DepartmentPreflight.php:44` declares `departments:preflight`. They are **not** the same signature, contrary to the earlier handoff note. Because Laravel auto-discovers `app/Console/Commands`, **both commands are currently registered** — `php artisan list` shows both — so the running application already depends on an uncommitted file. That is a reproducibility risk to settle before any defence build.
- **`phpunit.isolated.xml`.** It is mentioned only inside a comment in `phpunit.b1-sqlite.xml:24`; the file does not exist in this checkout. The four PHPUnit configurations present are `phpunit.xml`, `phpunit.b1-sqlite.xml`, `phpunit.pgsql-guarded.xml` and the untracked `phpunit.phase2_test.xml`.

---

## 6. Prioritised action plan

1. **Verify the Main PC login failure with real browser and network evidence.** Capture the address bar, the first console error, the Network rows for `OPTIONS`/`POST /api/login`, and one preflight probe from that machine. Until then every login cause in §4.1–4.3 is a candidate, not a conclusion.
2. **Decide the correct frontend/backend URL and CORS configuration** for the intended deployment (single machine, two machines, or LAN), then make the allowlist and `VITE_API_BASE_URL` match that decision.
3. **Verify the multi-session editor on the Main PC** and distinguish source from artifact: compare the checkout's HEAD and the age of any served `dist` against the editor's presence in the bundle.
4. **Review the `500` responses and the missing schema prerequisites without applying migrations.** Confirm the intended database, take a backup decision, then request approval to migrate.
5. **After approval, fix the misleading empty states and the dashboard loading regression**, each with the regression test named in §4.6 and §4.7.
6. **Review session-token behaviour and the housekeeping/security findings** (§4.3, §4.9): choose the token policy, decide on the cookie-jar file and scratch artifacts, and confirm debug output is off for any build shown to others.
7. **Complete the isolated-database safety review** (§5.4) before requesting authorisation for the guarded test.

### Summary
- **Completed and evidenced:** half-hour grid, configurable lunch break, multi-session editor, publish/ownership safeguards, versioned terms acceptance, reports, CI gates.
- **In progress (uncommitted in the working tree):** the auth-module split (`AuthContext.tsx` → `auth.ts` + `AuthProvider.tsx`) with its import updates. It typechecks and lints cleanly but is **not** committed, so no other checkout runs it.
- **Blocked:** the guarded `DepartmentPreflightTest` case (§5.5).
- **Unverified:** every Main PC claim, all runtime test counts, and the backend suites as a whole — none was executed for this report.

---

## 7. What was and was not executed

**Executed (read-only):** `git rev-parse`, `git log`, `git status`, `git stash list`; file and directory inspection; `php artisan migrate:status`; `php artisan list`; `php artisan route:list --path=schedules`; schema column/table introspection; unauthenticated endpoint probes; CORS preflight probes; `npx tsc -p tsconfig.app.json --noEmit` (exit `0`); `npx eslint .` (exit `0`); static counts of test declarations.

**Not executed:** any PHPUnit suite; the guarded launcher; migrations, seeders or schema changes; schedule generation; any authenticated write; any commit of application code. No `.env` value, credential, token, cookie or database content is reproduced in this document.

---

## 8. Publication record

This report is the only file added by this task. It is intended to be committed alone, so that the pre-existing staged guard files, the uncommitted auth refactor, and all other working-tree changes remain untouched and uncommitted. The commit is local; **pushing to GitHub awaits explicit approval** after the commit contents and included paths have been reviewed.

---

### Files cited
`backend/config/cors.php` · `backend/app/Http/Controllers/{AuthController,ScheduleSessionController,ScheduleController,ScheduleApprovalController,SubjectController,SettingController}.php` · `backend/app/Models/Setting.php` · `backend/routes/api.php` · `backend/app/Console/Commands/{DepartmentsPreflightCommand,DepartmentPreflight}.php` · `backend/tests/Feature/DepartmentPreflightTest.php` · `backend/tests/support/DatabaseTargetGuard.php` · `backend/tests/bootstrap.pgsql-guard.php` · `backend/run_guarded_test.ps1` · `backend/phpunit*.xml` · `frontend/src/context/auth.ts` · `frontend/src/context/AuthProvider.tsx` · `frontend/src/pages/LoginPage.tsx` · `frontend/src/pages/AdminDashboard.tsx` · `frontend/src/pages/admin/{SubjectsPage,FacultyPage,UsersPage,SectionsPage,ScheduleSessionEditor}.tsx` · `frontend/src/App.tsx` · `ai-engine/solver/{scheduler,time_slots}.py` · `ai-engine/tests/test_lunch_break.py` · `documentation/PROGRESS_HANDOFF.md` · `documentation/Progress-report.md`
