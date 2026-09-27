# Program Flow — IT Faculty Scheduling System

*Standalone companion to `User-Flow.md` (what the Admin does with the system) and `System-Architecture.md` (how the system is built). This document traces **how the program itself executes**: what runs first, which file handles each step, in what order, and where each validation gate sits.*

*Every step below was read out of the implementation — `backend/routes/api.php`, `backend/bootstrap/app.php`, the controllers in `backend/app/Http/Controllers/`, `ai-engine/api/app.py`, `ai-engine/solver/scheduler.py`, `frontend/src/main.tsx`, `frontend/src/App.tsx`, `frontend/src/context/AuthContext.tsx` and `frontend/src/pages/`. No behavior is described here that is not in the code; each section cites its file, and §5 maps every step to it.*

---

## 1. Program Entry and Control Flow

Both entry points exist: the browser program starts in `frontend/src/main.tsx` and the API program starts in `backend/public/index.php` (Laravel), which loads `backend/bootstrap/app.php` → `backend/routes/api.php`.

```mermaid
flowchart TD
    subgraph FE["Frontend — React + TypeScript + Vite (port 5173)"]
        M["main.tsx — createRoot renders App"]
        BR["App.tsx — BrowserRouter → AuthProvider → AppRoutes"]
        G{"AuthContext: user + token in localStorage?"}
        LP["LoginPage — POST /api/login"]
        PP["ProtectedRoute pages — AdminDashboard · RoomsPage · UsersPage ·<br/>FacultyPage · SubjectsPage · SectionsPage · ReportsPage · PrintableSchedule"]
        M --> BR --> G
        G -- "no" --> LP
        G -- "yes" --> PP
    end

    subgraph RT["Laravel routes — backend/routes/api.php"]
        PUB["POST /api/login — public route"]
        AUTHG["middleware auth:sanctum — logout · me"]
        ADMG{"middleware admin — EnsureUserIsAdmin"}
    end

    LP --> PUB
    PP -->|"fetch + Bearer token + Accept: application/json"| AUTHG
    AUTHG -->|"401 Unauthenticated"| PP
    AUTHG --> ADMG
    ADMG -- "role not admin" --> F403["403 Forbidden"]
    ADMG -- "role is admin" --> C

    C["Route controller — Auth · Faculty · Subject · Room · Section ·<br/>Schedule · ScheduleApproval · ScheduleSession · Report · User"]

    C --> G1["Gate 1 — SubjectController store/update:<br/>lab_hours above 0 requires a canonical lab_room_type;<br/>lab_hours = 0 requires none · else 422"]
    C --> G2["Gate 2 — SectionController store/update:<br/>assigned subjects must match section year + semester · else 422"]
    C --> G3["Gate 3 — ScheduleController generate:<br/>re-checks every assigned subject · 422 before the AI engine is called"]
    C --> G4["Gate 4 — ScheduleSessionController update:<br/>room type · faculty availability · self-overlap · external double-booking · 422"]
    C --> G5["Gate 5 — ScheduleApprovalController publish:<br/>cross-section conflict gate · 422 with conflict list"]

    G3 -- "clean" --> EP
    G4 -- "clean" --> SAVE["session updated · 200"]
    G5 -- "no conflicts" --> ARC["archive other approved/published schedules of this section"]
    ARC --> ST["status → published · 200"]

    subgraph AIE["AI engine — Python FastAPI (port 8001)"]
        EP["POST /generate-schedule/section_id"]
        SOL["solver/scheduler.py — OR-Tools CP-SAT<br/>max 15 s · maximize number of placed sessions"]
        EP --> SOL
    end

    SOL -->|"200 OPTIMAL · FEASIBLE · PARTIAL · INFEASIBLE · ERROR"| DEC{"Laravel accepts status?"}
    DEC -- "OPTIMAL or PARTIAL" --> DRAFT["Create Schedule status=draft<br/>+ ScheduleSession rows for placed sessions<br/>+ ScheduleGenerationLog"]
    DEC -- "anything else" --> FLOG["ScheduleGenerationLog status=failure<br/>422 to the browser"]
    DRAFT --> ADMIN["Admin reviews draft on AdminDashboard"]
    ADMIN --> EDIT{"Manual edit needed?"}
    EDIT -- "yes" --> G4
    EDIT -- "no" --> APR["PATCH /schedules/id/approve → status=approved"]
    APR --> G5
    ST --> PRINT["PrintableSchedule — GET /schedules/id<br/>weekly grid → window.print → PDF"]
    PRINT --> DIST["Hard-copy distribution to faculty and students"]

    subgraph DB["PostgreSQL"]
        TBL["schedules · schedule_sessions · schedule_generation_logs<br/>faculties · subjects · rooms · sections and their pivot tables"]
    end

    DRAFT --> TBL
    EP -.->|"psycopg2 reads of section, subjects, faculty, rooms,<br/>existing approved/published sessions"| TBL
```

*(Diagram source for the manuscript: `documentation/screenshots/program-flow.mmd`; rendered image: `documentation/screenshots/11-program-flow.png`.)*

**Reading the chart:**

1. `main.tsx` mounts `App`, which wraps `AppRoutes` in `BrowserRouter` and `AuthProvider`. `AuthContext` initializes `token` and `user` from `localStorage`, so a returning admin skips the login screen.
2. Every frontend route except `/login` is wrapped in `ProtectedRoute`, which redirects to `/login` when no user is in context (`App.tsx`). There is no faculty or student UI — the only dashboard is `AdminDashboard`.
3. API calls go to `API_BASE_URL = 'http://127.0.0.1:8000/api'` with `Authorization: Bearer <token>` + `Accept: application/json` (`context/AuthContext.tsx`, and the `headers()` helper in each page).
4. `bootstrap/app.php` registers one middleware alias, `admin → App\Http\Middleware\EnsureUserIsAdmin`. `routes/api.php` nests the whole admin surface inside `auth:sanctum` **and then** `admin`, so a request must first carry a valid token (401 otherwise) and then belong to an `admin` user (403 otherwise).
5. Past the middleware, the route's controller runs. Four of the five validation gates inside the controllers are application-layer data-integrity rules in Laravel, not solver constraints — they all answer **422** and never reach OR-Tools.

---

## 2. Generic Request Lifecycle

Every admin page follows the same cycle:

```mermaid
sequenceDiagram
    participant B as Browser page (React)
    participant R as Laravel router
    participant S as auth:sanctum
    participant A as EnsureUserIsAdmin
    participant V as Validator (validate / rules)
    participant C as Controller action
    participant D as PostgreSQL (Eloquent)

    B->>R: fetch (method, /api/..., JSON body, Bearer token)
    R->>S: resolve route + middleware stack
    S-->>B: 401 Unauthenticated (no / invalid token)
    S->>A: token valid
    A-->>B: 403 Forbidden. Admin access required. (role not admin)
    A->>V: role is admin
    V-->>B: 422 validation errors (ValidationException)
    V->>C: input accepted
    C->>D: Eloquent query / write
    D-->>C: rows
    C-->>B: JSON response 200 / 201 / 422 (business rule)
```

**Status codes the program actually returns:**

| Code | Produced by | When |
|---|---|---|
| 200 | controllers | Successful read/update/approve/publish/unpublish |
| 201 | `SubjectController::store`, `SectionController::store` | Record created |
| 401 | `auth:sanctum` | Missing or invalid Bearer token |
| 403 | `EnsureUserIsAdmin`, `authorizeAdmin()` / role check in controllers | Authenticated but not an `admin` |
| 404 | Route-model binding (`/schedules/{schedule}`, `/sections/{section}`, …), FastAPI `get_connection` path | Row does not exist |
| 422 | `$request->validate(...)`, business-rule gates (Gates 1–5), wrong-status actions | Input rejected — the standard rejection code for this program |
| 502 | `ScheduleController::generate` | The AI engine answered with a non-2xx response |
| 500 | AI engine (`psycopg2.Error`, unexpected exception) | Database or unexpected solver-side failure |

**Note on login failures:** `AuthController::login` throws a `ValidationException` for both wrong credentials and a non-admin role, so the program answers **422 with an `email` error message** (not 401) — the frontend sends `Accept: application/json`, which is what makes Laravel render that as JSON instead of a redirect.

---

## 3. Per-Process Program Flows

### 3.1 Login and logout (`AuthController`)

```mermaid
flowchart TD
    A["LoginPage submits email + password<br/>POST /api/login (public route)"] --> B{"validate: email required|email,<br/>password required|string"}
    B -- "invalid" --> B1["422 validation errors"]
    B -- "valid" --> C["User::where('email')->first()"]
    C --> D{"user found AND<br/>Hash::check(password)?"}
    D -- "no" --> D1["422 — 'The provided credentials are incorrect.'"]
    D -- "yes" --> E{"user.role === 'admin'?"}
    E -- "no" --> E1["422 — 'Only administrator accounts can access this system.'"]
    E -- "yes" --> F["Delete all existing tokens for this user<br/>createToken('api-token')"]
    F --> G["200 { user, token } → localStorage<br/>AuthContext sets token + user"]
    G --> H["Further calls: auth:sanctum → admin"]
    H --> I["POST /api/logout → deletes the current access token"]
    H --> J["GET /api/me → { id, name, email, role }"]
```

- Login is the **only public route**; everything else sits behind `auth:sanctum`.
- Faculty have no accounts, so the role check is the program-level enforcement of the admin-only design (`FAC-AUTH-003`).
- Frontend logout clears `localStorage` (`AuthContext.logout`); the server-side token is revoked by `POST /api/logout`.

### 3.2 Schedule generation (`ScheduleController::generate` → FastAPI → CP-SAT)

```mermaid
flowchart TD
    S["AdminDashboard: POST /api/schedules/generate/sectionId"] --> G3{"Gate 3 — every assigned subject matches<br/>the section year level + semester?"}
    G3 -- "no" --> R422["422 with the offending subject codes<br/>AI engine never called"]
    G3 -- "yes" --> ARCH["Archive existing drafts of this section<br/>(status draft → archived)"]
    ARCH --> HTTP["Http::timeout(30)->post('http://127.0.0.1:8001/generate-schedule/sectionId')"]
    HTTP --> F1{"AI engine response ok?"}
    F1 -- "non-2xx" --> L502["ScheduleGenerationLog status=failure<br/>502 to the browser"]
    F1 -- "200" --> READ["FastAPI reads section, subjects, qualified active faculty,<br/>availabilities, existing load, available rooms,<br/>other sections' approved/published sessions"]
    READ --> CHK{"enough data to model?"}
    CHK -- "no subjects assigned" --> E400["400 no subjects assigned"]
    CHK -- "no qualified faculty" --> E400b["400 no faculty can teach these subjects"]
    CHK -- "no available rooms" --> E400c["400 no available rooms"]
    CHK -- "yes" --> SOLVE["generate_schedule(): build one session per subject<br/>lecture session if lecture_hours above 0,<br/>laboratory session if lab_hours above 0"]
    SOLVE --> PRECHK["Structural pre-check per session:<br/>eligible room of the required type + qualified faculty<br/>else unschedulable with a plain-language reason"]
    PRECHK --> CP["CP-SAT model with the 8 constraint categories<br/>+ section window/self-overlap, Maximize(placed sessions)<br/>max_time_in_seconds = 15"]
    CP --> OUT["{ status, message, sessions[] }<br/>placed sessions carry day/start/end/room/faculty,<br/>unplaced ones carry a reason"]
    OUT --> DEC2{"Laravel accepts the status?"}
    DEC2 -- "OPTIMAL or PARTIAL" --> W["Create Schedule (status=draft) + one ScheduleSession<br/>per placed session + ScheduleGenerationLog<br/>(status optimal | partial, unscheduled list stored)"]
    W --> UI["200 { schedule_id, status, sessions, unscheduled }<br/>AdminDashboard shows 'Status: X — N sessions created'"]
    DEC2 -- "anything else" --> FAIL["ScheduleGenerationLog status=failure<br/>422 with the engine message"]
```

- The engine's own failure codes surface to the browser as **502** (non-2xx from FastAPI) — the Laravel call has no `try`/`catch`.
- Draft archiving happens **before** the engine is called, so a failed generation still leaves the section with its previous drafts archived.
- The solver is a pure function: `ai-engine/api/app.py` does the reading (psycopg2) and `ai-engine/solver/scheduler.py` does the solving, with no HTTP code inside it.

### 3.3 Approve → publish → print (`ScheduleApprovalController`, `PrintableSchedule`)

```mermaid
flowchart TD
    D["Draft schedule on AdminDashboard"] --> ED{"Manual edits needed?"}
    ED -- "yes" --> E["PUT /api/schedules/sessions/sessionId<br/>(Gate 4 — see 3.5)"]
    E --> D
    ED -- "no" --> AP["PATCH /api/schedules/id/approve"]
    AP --> APST{"schedule.status === 'draft'?"}
    APST -- "no" --> A422["422 — only draft schedules can be approved"]
    APST -- "yes" --> AOK["status=approved, approved_by=current user, approved_at=now"]
    AOK --> PUB["PATCH /api/schedules/id/publish"]
    PUB --> PST{"schedule.status === 'approved'?"}
    PST -- "no" --> P422["422 — only approved schedules can be published"]
    PST -- "yes" --> G5{"Gate 5 — findPublishConflicts():<br/>same day + overlapping time with another section's<br/>approved/published session on the same room or faculty?"}
    G5 -- "conflicts" --> C422["422 Cannot publish + conflict list"]
    G5 -- "clean" --> ARCH2["Archive this section's older approved/published schedules"]
    ARCH2 --> LIVE["status=published"]
    LIVE --> PR["AdminDashboard link → /print-schedule?schedule=id (new tab)"]
    PR --> GRID["PrintableSchedule: GET /api/schedules/id<br/>groups sessions into a day × time grid with window.print()"]
    GRID --> PDF["Browser print dialog → paper or Save as PDF → distribution"]
```

- Approval requires `draft`, publishing requires `approved`; every other state answers 422 with the current status named.
- The publish gate compares only against **other sections'** approved/published schedules — a section never conflicts with itself.
- Print is a read-only page: it fetches one schedule (`GET /api/schedules/{schedule}`) and calls `window.print()`.

### 3.4 Unpublish (`ScheduleApprovalController::unpublish`)

```mermaid
flowchart TD
    P["Published schedule"] --> U["Admin clicks Unpublish → confirm dialog"]
    U --> U1["PATCH /api/schedules/id/unpublish"]
    U1 --> S{"status === 'published'?"}
    S -- "no" --> U422["422 — only published schedules can be unpublished"]
    S -- "yes" --> D["status=draft, approved_by=null, approved_at=null"]
    D --> BACK["Back on the dashboard as a draft:<br/>edit (Gate 4) → approve → publish gate again"]
```

Unpublishing returns the **current** schedule to draft for editing; it does not restore any earlier schedule version.

### 3.5 Manual session edit (`ScheduleSessionController::update`)

```mermaid
flowchart TD
    E["AdminDashboard: edit day / start / end / room / faculty"] --> PUT["PUT /api/schedules/sessions/sessionId"]
    PUT --> R{"request user role === 'admin'?"}
    R -- "no" --> R403["403 Only the Department Head can edit schedule sessions"]
    R -- "yes" --> ST{"schedule.status in (draft, approved)?"}
    ST -- "no" --> ST422["422 — cannot edit a session on a published/archived schedule"]
    ST -- "yes" --> VAL["validate: day_of_week 1-7, start_time/end_time H:i<br/>end after start, room_id/faculty_id exist"]
    VAL --> MERGE["Merge submitted fields over current values<br/>(the full resulting state is validated, not just the diff)"]
    MERGE --> C1{"room.type matches session need?<br/>laboratory → subject.lab_room_type, lecture → 'lecture'"}
    C1 -- "mismatch" --> CONF["422 'This change would create a conflict.' + conflict list"]
    C1 -- "ok" --> C2{"faculty has declared availability<br/>and the slot fits inside it?"}
    C2 -- "outside" --> CONF
    C2 -- "ok" --> C3{"overlaps another session of the same schedule?"}
    C3 -- "yes" --> CONF
    C3 -- "no" --> C4{"faculty/room already booked in another section's<br/>approved/published schedule at that time?"}
    C4 -- "yes" --> CONF
    C4 -- "no" --> OK["Session updated · 200 with subject, faculty, room"]
```

This is protection layer 2 — the same rules that the solver enforces at generation time are re-checked on every manual edit.

### 3.6 Reports (`ReportController`, `ReportsPage`)

`ReportsPage` fires all five report calls in parallel on load; every report is read-only and admin-gated.

| Page tab | Endpoint | Controller method | Reads |
|---|---|---|---|
| Overview (schedule status) | `GET /api/reports/schedule-status` | `scheduleStatusOverview` | `schedules` grouped by status + total |
| Faculty Load | `GET /api/reports/faculty-workload` | `facultyWorkload` | published sessions per faculty vs `max_teaching_load` |
| Room Usage | `GET /api/reports/room-utilization` | `roomUtilization` | published sessions per room, summed hours |
| Sections | `GET /api/reports/section-summary` | `sectionSummary` | published sessions per section: count, hours, faculty count |
| Generation Logs / Conflicts | `GET /api/reports/conflicts` | `conflicts` | `schedule_generation_logs` with section + requester |

### 3.7 CRUD pages (shared shape)

`RoomsPage`, `UsersPage`, `FacultyPage`, `SubjectsPage` and `SectionsPage` all follow one program shape: `GET` the list on mount → open the form → `POST`/`PUT`/`DELETE` with `headers()` → re-fetch the list. Two of them carry a gate on write: `SubjectsPage` (Gate 1) and `SectionsPage` (Gate 2, whose subject checklist is filtered to the section's own year level and semester). `FacultyPage` additionally manages qualifications (`/faculties/{id}/subjects`) and availability (`/faculties/{id}/availability`), which feed the solver's qualification and availability constraints.

---

## 4. Program-Level Status and State Handling

### 4.1 Schedule status transitions (as written by the code)

`schedules.status` is a plain string column (default `draft`, no database enum — see `create_schedules_table`), so the transitions below are enforced entirely in controller logic.

| Action (code path) | Allowed from | Result | Rejection |
|---|---|---|---|
| `ScheduleController::generate` (success) | — | creates `draft` | 422 if the status returned by the engine is not OPTIMAL/PARTIAL |
| `ScheduleController::generate` (start) | `draft` | bulk `draft → archived` for that section | — |
| `ScheduleApprovalController::approve` | `draft` | `approved` + `approved_by` + `approved_at` | 422 with current status |
| `ScheduleApprovalController::publish` | `approved` | `published`; older `approved`/`published` of the same section → `archived` | 422 on wrong status, 422 on conflicts |
| `ScheduleApprovalController::unpublish` | `published` | `draft`, `approved_by`/`approved_at` cleared | 422 |
| `ScheduleApprovalController::reject` | `draft` | `rejected` | 422 |
| `ScheduleApprovalController::destroy` | `draft`, `archived` | sessions deleted, then the schedule | 422 |
| `ScheduleSessionController::update` | schedule `draft` or `approved` | session row updated | 422 otherwise |

**Flow:** `draft → approved → published → archived`, with `unpublish` returning `published → draft` for re-editing and `reject` sending a draft to `rejected`.

### 4.2 Solver result branching (`ai-engine/solver/scheduler.py`)

| Returned status | When the solver sets it | Laravel's handling (`ScheduleController`) |
|---|---|---|
| `OPTIMAL` | Every computed session placed **and** the solver proved the objective optimal | Accepted → draft + log `optimal` |
| `FEASIBLE` | Every computed session placed but the 15 s limit was hit before optimality was proven | **Not** in the accepted list → log `failure`, 422 (see §6, observation 1) |
| `PARTIAL` | Some sessions placed (`scheduled_count > 0`) — each unplaced session carries a reason | Accepted → draft + log `partial`, unscheduled list stored |
| `INFEASIBLE` | No session could be placed; reasons reported per session | Log `failure`, 422 with the engine message |
| `ERROR` | The section has no subject with lecture or lab hours defined (engine-side guard in `app.py` returns 400 earlier for "no subjects assigned") | Log `failure`, 422 |

Session-level reasons are always preserved: structurally impossible sessions (no room of the required type/status/capacity, no qualified faculty) get a reason during the pre-check pass, and sessions that the CP-SAT model could not place get the "could not fit given room, faculty, or time conflicts" reason.

### 4.3 Generation log vocabulary

`ScheduleController` writes `schedule_generation_logs.status` as **lowercase**: `strtolower($result['status'])` → `optimal` or `partial` on success, and the literal `failure` for every rejected/failed path (engine unreachable, non-optimal status). `unscheduled_sessions` stores the unplaced sessions with their reasons, which is exactly what the Reports → Generation Logs tab displays.

### 4.4 Frontend state handling

- `AuthContext` holds `token` + `user` and mirrors both into `localStorage`; `ProtectedRoute`/`Dashboard` redirect to `/login` when `user` is null.
- `AdminDashboard` keeps `selectedSectionId` (initially null, auto-selected from the loaded section list), re-fetches schedules whenever the "Show archived" toggle changes (`?show_archived=`), and surfaces generation outcomes as `Status: X — N sessions created` or the server's error message.
- Edit conflicts are shown in the edit dialog itself (`editError`), approve/publish/unpublish errors via `alert()`.

---

## 5. File and Function Index

| Flow step | File | Function / entry |
|---|---|---|
| Program start (browser) | `frontend/src/main.tsx` | `createRoot(...).render(<App/>)` |
| Routing + guards | `frontend/src/App.tsx` | `App`, `AppRoutes`, `ProtectedRoute`, `Dashboard` |
| Auth state + API base | `frontend/src/context/AuthContext.tsx` | `AuthProvider`, `login`, `logout`, `API_BASE_URL` |
| Dashboard actions | `frontend/src/pages/AdminDashboard.tsx` | `headers()`, `fetchSchedules`, `handleGenerate`, `handleApprove`, `handlePublish`, `handleUnpublish`, `confirmDelete`, `saveEditSession` |
| Print / PDF view | `frontend/src/pages/PrintableSchedule.tsx` | schedule fetch + grid build + `window.print()` |
| Reports UI | `frontend/src/pages/admin/ReportsPage.tsx` | 5 parallel report fetches |
| CRUD UIs | `frontend/src/pages/admin/{Rooms,Users,Faculty,Subjects,Sections}Page.tsx` | list/create/update/delete handlers |
| Routes + middleware stack | `backend/routes/api.php` | `auth:sanctum` group, `admin` group |
| Middleware alias | `backend/bootstrap/app.php` | `'admin' => EnsureUserIsAdmin::class` |
| Admin gate | `backend/app/Http/Middleware/EnsureUserIsAdmin.php` | `handle()` → 403 |
| Login / logout / me | `backend/app/Http/Controllers/AuthController.php` | `login`, `logout`, `me` |
| Gate 1 (subject lab consistency) | `backend/app/Http/Controllers/SubjectController.php` | `subjectRules`, `labConsistencyErrors`, `store`, `update` |
| Gate 2 (subject–section integrity) | `backend/app/Http/Controllers/SectionController.php` | `subjectMismatches`, `store`, `update` |
| Gate 3 + generation | `backend/app/Http/Controllers/ScheduleController.php` | `generate` |
| Gate 4 (edit conflicts) | `backend/app/Http/Controllers/ScheduleSessionController.php` | `update`, `findConflicts`, `overlaps` |
| Gate 5 + lifecycle | `backend/app/Http/Controllers/ScheduleApprovalController.php` | `approve`, `publish`, `unpublish`, `reject`, `destroy`, `findPublishConflicts` |
| Reports data | `backend/app/Http/Controllers/ReportController.php` | `facultyWorkload`, `roomUtilization`, `conflicts`, `scheduleStatusOverview`, `sectionSummary` |
| AI engine endpoint | `ai-engine/api/app.py` | `generate_schedule_for_section`, `health`, `get_connection` |
| Solver | `ai-engine/solver/scheduler.py` | `generate_schedule()` |
| Solver test suite | `ai-engine/tests/test_scheduler.py` | 32 unittest cases against `generate_schedule()` |

---

## 6. Observations Recorded While Tracing the Code

Documentation-only notes — nothing here was changed, and each is traceable to the file named. They are recorded so the code and the manuscript tell the same story.

1. **`FEASIBLE` is treated as a failure by Laravel.** The solver returns `FEASIBLE` when every session is placed but optimality was not proven within the 15 s limit (`scheduler.py`, `overall_status = solver.StatusName(status)`), while `ScheduleController::generate` accepts only `['OPTIMAL', 'PARTIAL']`. A fully-placed `FEASIBLE` run is therefore logged as `failure` and answered with 422 "No feasible schedule found." Follow-up for the team: accept `FEASIBLE` alongside `OPTIMAL`, or map it to the same success path.
2. **The `GET /api/login` fallback route is unreachable and returns nothing.** It is declared *inside* the `auth:sanctum` + `admin` groups in `routes/api.php`, and its closure builds a JSON response without returning it, so an unauthenticated caller gets 401 from the guard and a non-admin caller gets 403 from the middleware before the closure runs.
3. **`rejected` is a real schedule status but appears in no documented lifecycle.** `ScheduleApprovalController::reject` writes `status = 'rejected'` and `destroy` allows only `draft`/`archived`; the ERD and lifecycle lists in `System-Architecture.md` / `User-Flow.md` mention only draft, approved, published, archived.
4. **Log-status vocabulary differs from the migration comment.** `create_schedule_generation_logs_table` comments the column as "success, partial, failure"; the code writes `optimal`, `partial`, or `failure`. The ERD value list (`optimal | partial | failure`) matches the code.
5. **The Laravel → engine call has no exception handling.** Only a non-2xx *response* becomes a 502; a refused connection to port 8001 propagates as an unhandled `ConnectionException` instead of a clean "AI engine unavailable" message.
6. **Engine time budget vs HTTP budget.** The solver is capped at 15 s (`max_time_in_seconds = 15.0`) inside a 30 s HTTP timeout, and one request is issued per section — sequential generation of many sections can approach the HTTP limit before the solver's own budget matters.
