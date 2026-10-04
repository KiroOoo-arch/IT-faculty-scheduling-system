# System Architecture — IT Faculty Scheduling System

## 1. Architecture Overview

> **Role model:** The authorized **Admin / Department Head** is the only system user and operator. **Faculty are scheduling records/entities, not system users** — they never log in. Faculty data (name, employee number, employment type, qualifications, subject assignments, availability, max teaching load) feeds the scheduling engine, and published schedules reach faculty and students as **printed/PDF copies**.

### The four protection layers (conflicts and silent data loss are both blocked)

1. **AI generation constraints** — the CP-SAT solver mathematically enforces all constraint categories when producing a candidate schedule
2. **Manual edit conflict detection** — every admin edit of a draft/approved session is re-checked server-side
3. **Publish conflict gate** — a final cross-section conflict check runs before any schedule goes live
4. **Published-reference guard** — deleting master data (faculty, room, subject, section, user) that a *published* schedule still depends on is refused with **409** and the exact scope of the loss; only an explicit `?force=1` proceeds (see §2.10)

Layers 1–3 protect the *contents* of a schedule while it is being built. Layer 4 protects the timetable that has **already been distributed** — the one case where the data that is changing lives outside the schedule and the loss would otherwise be invisible.

Plus a **pre-scheduling validation gate in Laravel** (application-layer business rules, not solver constraints): subject–section year/semester integrity (FR-018) and subject lab consistency (FR-019) are enforced when data is saved and re-checked before the AI engine is ever called — invalid assignments return HTTP 422 and cannot reach the solver.

> **Human oversight:** Publishing never happens automatically. The AI produces a *candidate* schedule only; the Admin reviews, edits, approves, and decides publication through the conflict gate.
>
> **Companion documents:** `User-Flow.md` — the Administrator user flow as a standalone document; `Program-Flow.md` — how the program actually executes (entry points and control flow, request lifecycle, per-process flows, validation-gate placement, status handling, file/function index).
```mermaid
graph TB
    subgraph Frontend["Frontend — React + TypeScript + Vite (port 5173)"]
        AD[Admin Dashboard<br/>Generate · Review · Approve · Publish · Edit · Print]
        CP[CRUD Pages<br/>Users · Faculty · Subjects · Rooms · Sections]
        RP[Reports Page]
    end

    subgraph Backend["Backend — Laravel 13 + Sanctum (port 8000)"]
        API[REST API /api]
        AUTH[Auth + RBAC<br/>login / logout / me]
        SCH[ScheduleController<br/>generate]
        SAP[ScheduleApprovalController<br/>approve · publish + Conflict Gate]
        SES[ScheduleSessionController<br/>update + Conflict Detection]
        AUTHGATE[Admin-only gate<br/>EnsureUserIsAdmin middleware]
    end

    subgraph AI["AI Engine — Python FastAPI (port 8001)"]
        APP["app.py<br/>/generate-schedule/{section_id}"]
        SOLVER[OR-Tools CP-SAT Solver]
    end

    DB[(PostgreSQL)]

    Frontend -->|HTTP/JSON + Bearer token| Backend
    Backend -->|HTTP POST /generate-schedule/id| AI
    AI -->|OPTIMAL / FEASIBLE / PARTIAL / INFEASIBLE| Backend
    AI -.direct psycopg2 read.-> DB
    Backend -.Eloquent ORM.-> DB
    Backend -->|JSON| Frontend
```

## 2. Data Flow

### 2.1 Schedule Generation Flow

```mermaid
sequenceDiagram
    participant U as Admin (browser)
    participant F as AdminDashboard (React)
    participant L as Laravel (ScheduleController)
    participant A as FastAPI (ai-engine/app.py)
    participant DB as PostgreSQL

    U->>F: Click "Generate Schedule"
    F->>L: POST /api/schedules/generate/{section} (Bearer token)
    L->>L: Validate the section's assigned subjects<br/>match its year level + semester
    Note over L: Mismatch → 422, the AI engine is never called
    L->>A: POST http://127.0.0.1:8001/generate-schedule/{id}
    A->>DB: Query section, subjects, faculty + availabilities,<br/>available rooms, existing approved/published sessions
    DB-->>A: data
    A->>A: OR-Tools CP-SAT solver (8 constraints)
    A-->>L: {status: OPTIMAL/FEASIBLE/PARTIAL/INFEASIBLE, sessions[]}
    alt OPTIMAL, FEASIBLE or PARTIAL
        L->>DB: Supersede the previous draft (archive) — only now that the result is real
        L->>DB: Create Schedule (draft) + ScheduleSession rows
        L->>DB: Write ScheduleGenerationLog
        L-->>F: 200 {schedule_id, sessions, unscheduled}
        F-->>U: "Status: OPTIMAL — N sessions created"
    else INFEASIBLE / failure
        Note over L: The existing draft is left untouched —<br/>a failed attempt is retryable, not destructive
        L->>DB: Write ScheduleGenerationLog (failure)
        L-->>F: 422 {message} or 502 (engine unreachable)
        F-->>U: Error message
    end
```

### 2.2 Approve → Publish → Print/Download Flow

```mermaid
sequenceDiagram
    participant U as Admin
    participant L as Laravel (ScheduleApprovalController)
    participant DB as PostgreSQL

    U->>L: PATCH /api/schedules/{id}/approve
    L->>DB: status draft → approved
    L-->>U: approved

    U->>L: PATCH /api/schedules/{id}/publish
    L->>L: findPublishConflicts(schedule)
    alt conflicts found
        L-->>U: 422 "Cannot publish: conflicts..."
    else no conflicts
        L->>DB: archive older published/approved (same section)
        L->>DB: status → published
        L-->>U: published
    end

    Note over U: Admin opens the published schedule and clicks Print/Download
    U->>U: Print-friendly weekly grid → browser print dialog → Save as PDF
    Note over U: Hard copies distributed to faculty and students
```

### 2.3 Manual Session Editing (Conflict Detection)

```mermaid
sequenceDiagram
    participant U as Admin
    participant L as Laravel (ScheduleSessionController)
    participant DB as PostgreSQL

    U->>L: PUT /api/schedules/sessions/{id} (day/time/room/faculty)
    L->>DB: Load session + related schedule context
    L->>L: Conflict detection:
    L->>L:  • faculty double-booking?
    L->>L:  • room double-booking?
    L->>L:  • room type matches session (lab → required lab type)?
    L->>L:  • within the faculty's declared availability (day + time window)?
    L->>L:  • within section's time window / days?
    alt conflict found
        L-->>U: 422 with the specific conflict reason(s) (edit rejected)
    else no conflict
        L->>DB: Save updated session
        L-->>U: Updated session JSON
    end
```

### 2.4 Unpublish → Re-edit Loop

When a published schedule needs changes:

```text
PUBLISHED → Admin selects Unpublish → status becomes DRAFT
        → Admin edits (conflict-checked) → Approve → Publish conflict gate → PUBLISHED
```

Unpublish returns the *current* schedule to draft for editing — it does not restore any earlier schedule state.

### 2.5 Room & Laboratory Eligibility

A subject's lab sessions can only be placed in rooms satisfying **all** of:

```text
room.type == subject.lab_room_type        (e.g. computer_lab)
AND room.capacity >= section.student_count
AND room.status == available
```

Example: *Programming Laboratory — lab_hours = 3, lab_room_type = computer_lab* → the solver only considers rooms with type `computer_lab`, sufficient capacity, and available status. Lecture sessions likewise require `lecture`-type rooms.

### 2.6 Solver Result Terminology

The engine is **AI-assisted constraint-based scheduling using OR-Tools CP-SAT** (a constraint optimizer — not a machine-learning model). Possible results:

| Status | Meaning |
|---|---|
| **OPTIMAL** | Best possible solution found — all sessions placed |
| **FEASIBLE** | A valid solution found within the time limit (all placed, but optimality unproven) |
| **PARTIAL** | Some sessions placed; each unplaced session gets a plain-language reason |
| **INFEASIBLE** | No sessions could be placed; reasons reported |

The solver is designed to satisfy the defined scheduling constraints and reports partial or infeasible results when the available resources and constraints prevent complete scheduling. The system implements **eight main constraint categories**, with the **section's preferred scheduling window** (preferred days + start/end time) also directly modeled by the solver:

1. Faculty qualification
2. Faculty availability
3. Room type matching
4. Room capacity
5. Faculty no double-booking
6. Room no double-booking
7. Maximum teaching load
8. Cross-section conflicts (against existing approved/published sessions)

### 2.7 End-to-End Workflow

```text
Generate → DRAFT → Admin Review / Manual Edit → APPROVED
        → Publish Conflict Gate → (conflict: REJECT) / (no conflict: PUBLISHED)
        → Print / Download PDF → Distribution to Faculty + Students
        → (Unpublish returns PUBLISHED → DRAFT for re-editing)
```
### 2.8 Print / Download (Hard-Copy Distribution)

After a schedule is published, the Admin produces the hard copy for distribution:

1. Admin opens the published schedule and clicks **Print / Download**
2. A print-friendly weekly grid view opens (department header, section name, day columns × time rows, subject/faculty/room per cell)
3. The browser print dialog opens — the Admin prints directly or chooses **Save as PDF**
4. Printed/PDF copies are distributed to faculty and posted for students

**Design note:** Faculty do not log into the system. Faculty are records used by the scheduling engine; published schedules reach them as printed/PDF copies. This shrinks the security surface and matches the per-semester usage pattern of the department.

### 2.9 Unpublish API Detail

When an admin needs to make changes to a published schedule:

1. Admin clicks "Unpublish" button on a published schedule
2. Confirmation dialog appears: "Unpublish this schedule? It will revert to draft for editing."
3. On confirmation, system calls PATCH /api/schedules/{id}/unpublish
4. Schedule status changes from "published" to "draft"
5. Schedule can now be edited and goes through the approval workflow again

**API Endpoint:** PATCH /api/schedules/{schedule}/unpublish

**Response:**
- Success: { message: "Schedule unpublished and reverted to draft." }
- Error: { message: "Only published schedules can be unpublished." }


### 2.10 Published-Reference Guard (Destructive Master-Data Deletes)

Master data genuinely changes over time — a laboratory gets decommissioned, a subject is retired, a faculty member leaves. Deleting a room, subject, section or faculty record also removes the `schedule_sessions` rows that reference it (an explicit `deleting` hook on the model, rather than a RESTRICT foreign key that would surface as a 500). That is correct for drafts and archives. It is **not** correct for a published schedule, because a published schedule is the timetable of record — quietly dropping one of its sessions produces a document that is simply wrong, and nothing in the system would say so.

`GuardsPublishedReferences` is the trait that closes that gap. `RoomController`, `SubjectController`, `FacultyController` and `SectionController` all call `publishedReferenceConflict()` before deleting.

> **User records are the exception.** Deleting a user does **not** go through this trait, because a user is never referenced by a `schedule_session` — only by `schedules.approved_by`. That column is *nullable*, and the `User` model's `deleting` hook nulls it rather than deleting the schedules: the approval genuinely happened and the timetable should survive, so the system keeps the schedule and simply stops being able to name who approved it. `UserController::destroy()` carries its own two guards instead — an admin cannot delete their own account, and cannot delete the last admin account (which would lock the system out permanently).

```mermaid
sequenceDiagram
    participant U as Admin
    participant L as Laravel (Controller + GuardsPublishedReferences)
    participant DB as PostgreSQL

    U->>L: DELETE /api/rooms/{id}
    L->>DB: Count sessions referencing this room<br/>+ which of their schedules are published
    alt referenced by a published schedule and no force flag
        L-->>U: 409 {message, requires_confirmation true,<br/>published_schedule_ids[], sessions_at_risk,<br/>published_sessions_at_risk}
        Note over U: Client shows a SECOND confirmation<br/>quoting the server's message verbatim
        alt Admin declines
            U->>U: Nothing is sent. No mutation of any kind.
        else Admin confirms
            U->>L: DELETE /api/rooms/{id}?force=1
            L->>DB: Model deleting hook removes EVERY session<br/>referencing the room (drafts, archives and published)
            L-->>U: 200 Room deleted successfully
        end
    else not referenced by a published schedule (or forced)
        L->>DB: Model deleting hook removes referencing sessions
        L-->>U: 200 Room deleted successfully
    end
```

**What the 409 reports.** The counts are deliberately split: the message leads with the *total* sessions that would disappear and then states how many of those sit in the published timetable. Reporting only the published subset would understate the loss — deleting one room dropped **31** sessions in the verified run, of which only **3** were in published schedules. The payload also carries `published_schedule_ids`, so a client can name the affected schedules. Nothing is mutated on this path: the record is still readable immediately afterwards.

**What `?force=1` actually does.** The guard steps aside (`$request->boolean('force')`), the record is deleted, and the model's `deleting` hook mass-deletes every session that referenced it — **including the ones inside published schedules**. Note three consequences, all deliberate and all verified:

| After a forced delete | Observed |
|---|---|
| Sessions referencing the record | The full set is removed (31 → 0), across drafts, archives and published |
| Status of the affected published schedule | **Unchanged — still `published`.** Nothing downgrades, archives or flags it |
| Audit trail | **None written.** No generation log, no tombstone; the loss is not recorded anywhere |
| Recovery | Manual: unpublish the schedule, correct the data, regenerate, re-approve, re-publish |

> **Design intent.** The guard is a speed bump, not a veto. Refusing the delete outright would make the system unusable whenever master data legitimately changes. What it refuses is letting the loss happen *quietly*: the admin sees the exact scope, confirms a second time, and has to say `force` explicitly. The tradeoff accepted is that the consequence is **visible up front but not reversible afterwards** — there is no automatic downgrade of the affected schedule and no audit row. This is the one place in the system where an operation can leave a published schedule incomplete, and it should be presented as a known, bounded gap rather than as a solved problem.

**Coverage.** Backend feature test `PublishedReferenceGuardTest` exercises the 409 and the force path.

### 2.11 Authentication and Session Flow (Laravel Sanctum)

The API uses Sanctum in **token mode** (`HasApiTokens` on the `User` model), not its cookie/CSRF mode. A token is a random string shown once; Sanctum stores only a SHA-256 hash of it in `personal_access_tokens`, so it can be compared but never recovered.

```mermaid
sequenceDiagram
    participant U as Admin (browser)
    participant F as AuthContext (React)
    participant L as Laravel (AuthController)
    participant DB as PostgreSQL

    U->>F: Email + password
    F->>L: POST /api/login — the ONLY public route
    L->>DB: Look up user by email
    alt unknown user or wrong password
        L-->>F: 401 {message: 'The provided credentials are incorrect.'}
    else valid credentials, role != admin
        L-->>F: 403 {message: 'Only administrator accounts can access this system.'}
        Note over L: No token is ever issued to a non-admin
    else valid admin
        L->>DB: Delete all existing tokens for this user
        L->>DB: createToken('api-token') — store SHA-256 hash
        L-->>F: 200 {user, token}
        F->>F: Store token + user in localStorage
    end

    Note over U,F: Every later request sends a Bearer token in the Authorization header
    U->>L: Any other /api/* request
    L->>L: middleware auth:sanctum — resolves the token to a user
    Note over L: Missing or invalid → 401 (controller never constructed)
    L->>L: middleware admin — EnsureUserIsAdmin
    Note over L: Valid token but role != admin → 403
    L-->>U: Controller response

    U->>L: POST /api/logout
    L->>DB: Delete ONLY $request->user()->currentAccessToken()
    L-->>U: 200 Logged out successfully
```

**Middleware chain.** `routes/api.php` nests the entire admin surface inside `auth:sanctum` **and then** `admin`, so a request must first carry a valid token (401 otherwise) and then belong to an `admin` user (403 otherwise). Login is the only route outside that nest.

**Session semantics worth stating out loud.**

- **One session per account.** Login deletes all prior tokens before issuing a new one, so signing in on a second browser invalidates the first. This is deliberate for a single-admin system, not an accident.
- **Logout is per-session.** It deletes only the token used for that request, so it does not sign the account out everywhere.
- **Tokens do not expire on their own.** `config/sanctum.php` leaves `expiration` unset; a token lives until logout or until the user is deleted. The 401 path therefore fires when a token is *gone*, not when it is *old*.
- **Client-side expiry handling.** A single `window.fetch` interceptor in `AuthContext.tsx` watches for any 401, clears `localStorage`, and hard-redirects to `/login`. It ignores `/api/login` itself so a failed sign-in cannot trigger a redirect loop. It is installed at module load, before React mounts, because child page effects run before the provider's effect and would otherwise miss the first fetch after a hard reload.
- **JSON error rendering.** `bootstrap/app.php` sets `shouldRenderJsonWhen(...)` for `api/*`, without which Laravel would answer an unauthenticated browser-style request with a 302 to the login route (which itself sits behind auth) rather than a readable 401.

## 3. User Types and User Flows

### 3.1 System Users (Complete List)

| User Type | System Access | Role in the System |
|---|---|---|
| **Administrator / Department Head** | **Login account — the only system user** (one combined role; the Department Head logs in with the Administrator account) | Full operational control: manage faculty/subject/room/section records, generate schedules, review/edit, approve, publish, unpublish, print/download, view reports, manage admin accounts |
| **Faculty** | **No login — not a system user** | Maintained as scheduling records (name, employment type, qualifications, availability, max load). Their data feeds the scheduling engine; they receive published schedules as **printed/PDF copies** |
| **Students** | **No login — not a system user** | Recipients of published schedules via printed/PDF copies posted or distributed by the department |

> **Role-model note:** The SRS lists *Administrator* and *Department Head* as two user classes; the implemented system issues a single **admin** login role that covers both (the Department Head uses the Administrator account). There is no faculty login, no faculty portal, and no student account. Faculty and students exist only as data records / recipients.

### 3.2 User Flow — Administrator / Department Head

```mermaid
flowchart TD
    A[Login<br/>email + password] --> B{Sanctum auth<br/>role == admin?}
    B -- no / non-admin --> X[Rejected:<br/>Only administrator accounts can access this system]
    B -- yes --> C[Admin Dashboard]
    C --> D[Manage Scheduling Data<br/>Faculty records · Subjects · Rooms · Sections]
    D --> DEL["Delete a master-data record<br/>Faculty · Subject · Room · Section"]
    DEL -- "still used by a published schedule" --> DEL1[HTTP 409<br/>total sessions at risk + published count<br/>· nothing is mutated]
    DEL1 -. admin declines .-> C
    DEL1 -- admin confirms --> DEL2[Retry with ?force=1<br/>delete proceeds]
    DEL2 --> C
    DEL -- "no published reference" --> DEL3[Deleted]
    DEL3 --> C
    D --> D1[Create/Edit Subject<br/>lab_hours > 0 requires computer_lab /<br/>science_lab / electronics_lab · else HTTP 422]
    D1 --> E[Create/Edit Section<br/>assign subjects]
    E --> E1{Subjects match section<br/>year level + semester?}
    E1 -- no --> E2[HTTP 422<br/>offending subject codes named<br/>· assignment rejected]
    E2 --> E
    E1 -- yes --> F[Generate Schedule]
    F --> F1{Laravel validation gate:<br/>all assigned subjects match<br/>year level + semester?}
    F1 -- no --> F2[HTTP 422<br/>AI engine never called]
    F2 --> E
    F1 -- yes --> F3[FastAPI → OR-Tools CP-SAT<br/>candidate schedule]
    F3 --> G{Result}
    G -- OPTIMAL / FEASIBLE / PARTIAL --> H[DRAFT: Review / Manual Edit<br/>server-side conflict detection]
    G -- INFEASIBLE --> I[Reasons reported<br/>fix data and retry]
    I --> E
    H --> J[Approve]
    J --> K{Publish conflict gate:<br/>cross-section conflicts?}
    K -- conflict --> L[Publish rejected 422]
    L --> H
    K -- none --> M[PUBLISHED]
    M --> N[Print / Download<br/>print-friendly weekly grid<br/>→ browser print dialog → Save as PDF]
    N --> O[Distribute hard copies<br/>to faculty and students]
    M -. changes needed .-> P[Unpublish<br/>PUBLISHED → DRAFT]
    P --> H
```

Text flow (same steps):

```text
Login (admin-only) → Manage Data → Create/Edit Subjects (lab consistency validated)
  → Create/Edit Sections + Assign Subjects (year/semester validated, 422 on mismatch)
  → Generate Schedule → Laravel validation gate (422 blocks legacy mismatches; AI never called)
  → OR-Tools CP-SAT candidate → DRAFT → Review / Manual Edit (conflict-checked)
  → Approve → Publish conflict gate → PUBLISHED → Print/Download PDF → Distribute
  → (Unpublish returns to DRAFT for re-editing)
  → (Deleting a master-data record still used by a published schedule: 409 with the exact scope,
     then an explicit ?force=1 to proceed — see §2.10)
```

Every validation step above is enforced server-side (SectionController, SubjectController, ScheduleController) and mirrored in the UI — the subject checklist on the Sections page only lists subjects matching the section's year level and semester, with legacy mismatches flagged in place. The published-reference guard is enforced by the controllers themselves via the `GuardsPublishedReferences` trait (§2.10) and surfaced in the UI as a second confirmation quoting the server's message.

### 3.3 User Flow — Faculty (Non-User)

Faculty never log in and never interact with the system directly:

```text
Admin enters faculty data (name, type, qualifications, availability, max load)
  → faculty records feed the scheduling engine
  → Admin publishes the schedule
  → Admin prints / exports PDF
  → faculty member receives the printed/PDF copy of their schedule
```

### 3.4 User Flow — Students (Non-User)

Students never log in and never interact with the system directly:

```text
Admin publishes the section schedule
  → Admin prints / exports PDF
  → copies are posted or distributed to the class
```


## 4. Reports Dashboard

The Reports page provides analytics and summaries with the following tabs:

| Tab | Description | Data Source |
|-----|-------------|-------------|
| **Overview** | Schedule Status Overview (Archived/Published counts), Faculty Members count, Rooms count, Sections count | /api/reports/schedule-status |
| **Faculty Load** | Assigned hours vs max teaching load per faculty member | /api/reports/faculty-workload |
| **Room Usage** | Booked hours per week per room | /api/reports/room-utilization |
| **Sections** | Sessions, hours, faculty count per section | /api/reports/section-summary |
| **Conflicts** | Cross-section conflict scan of published schedules | /api/reports/conflicts |
| **Generation Logs** | Schedule generation history, success/failure rates | generation log records (via section-summary/schedule-status data) |

### Schedule Status Overview
- **Archived**: Count of schedules that have been archived (old versions)
- **Published**: Count of currently active published schedules
- **Total Schedules**: Sum of all schedules in the system

### Resource Summary
- **Faculty Members**: Total faculty count with active load indicator
- **Rooms**: Total rooms with breakdown (lecture rooms, labs)
- **Sections**: Total sections with total session count

## 5. Database Design (ER Diagram)

> **Entity naming:** ER entities are shown in singular form (FACULTY, SUBJECTS, …); the physical PostgreSQL tables use Laravel's plural convention (`faculties`, `subjects`, …). They are the same structures.
>
> **Two different status fields (not a wording drift):** `SCHEDULES.status` and solver results use uppercase `OPTIMAL | FEASIBLE | PARTIAL | INFEASIBLE` (the FastAPI/OR-Tools response values), while `SCHEDULE_GENERATION_LOGS.status` is stored lowercase (`optimal | feasible | partial | failure`) — exactly as written by `ScheduleController`. `OPTIMAL`, `FEASIBLE` and `PARTIAL` are all accepted as successful results; `INFEASIBLE` (and the engine's `ERROR`) are logged as `failure` and answered with 422.

```mermaid
erDiagram
    USERS ||--o{ FACULTY : has
    USERS ||--o{ SCHEDULES : approves
    FACULTY ||--o{ FACULTY_SUBJECTS : qualified
    FACULTY ||--o{ FACULTY_AVAILABILITIES : available
    FACULTY ||--o{ SCHEDULE_SESSIONS : teaches
    SUBJECTS ||--o{ FACULTY_SUBJECTS : taught-by
    SUBJECTS ||--o{ SECTION_SUBJECTS : assigned-to
    SUBJECTS ||--o{ SCHEDULE_SESSIONS : included-in
    SECTIONS ||--o{ SECTION_SUBJECTS : includes
    SECTIONS ||--o{ SCHEDULES : generates
    SECTIONS ||--o{ SCHEDULE_GENERATION_LOGS : logged
    ROOMS ||--o{ SCHEDULE_SESSIONS : hosts
    SCHEDULES ||--o{ SCHEDULE_SESSIONS : contains
    USERS ||--o{ SCHEDULE_GENERATION_LOGS : requested

    USERS {
        int id PK
        string name
        string email
        string password
        string role "admin — the only login account"
    }

    FACULTY {
        int id PK
        string name "faculty name stored directly"
        int user_id FK "nullable — legacy link, not a login"
        string employee_no
        string faculty_type "full_time | part_time | evening (app validates full_time, part_time)"
        int max_teaching_load
        boolean is_active
    }

    SUBJECTS {
        int id PK
        string code
        string title
        int year_level
        string semester_name
        int lecture_hours
        int lab_hours
        string lab_room_type "computer_lab | science_lab | electronics_lab | null"
        boolean is_active
    }

    SECTIONS {
        int id PK
        string name
        int year_level
        string academic_year
        string semester_name
        int student_count
        json preferred_days
        time preferred_start_time
        time preferred_end_time
    }

    ROOMS {
        int id PK
        string name
        string type "lecture | computer_lab | science_lab | electronics_lab"
        int capacity
        string status "available | under_maintenance | inactive"
    }

    SCHEDULES {
        int id PK
        int section_id FK
        string status "draft | approved | published | rejected | archived"
        int approved_by FK
        timestamp approved_at
        timestamp created_at
    }

    SCHEDULE_SESSIONS {
        int id PK
        int schedule_id FK
        int subject_id FK
        int faculty_id FK
        int room_id FK
        string session_type "lecture | laboratory"
        int day_of_week "1-6"
        time start_time
        time end_time
    }

    FACULTY_AVAILABILITIES {
        int id PK
        int faculty_id FK
        int day_of_week
        time start_time
        time end_time
    }

    FACULTY_SUBJECTS {
        int faculty_id FK
        int subject_id FK
    }

    SECTION_SUBJECTS {
        int section_id FK
        int subject_id FK
    }

    SCHEDULE_GENERATION_LOGS {
        int id PK
        int section_id FK
        int requested_by FK
        string status "optimal | feasible | partial | failure"
        string message
        json unscheduled_sessions
        timestamp created_at
    }
```

## 6. Project Structure

```text
it-faculty-scheduling-system/
├── ai-engine/                      # Python FastAPI (port 8001)
│   ├── api/app.py                  # OR-Tools CP-SAT solver + endpoints
│   ├── seed_rooms.py
│   ├── requirements.txt
│   └── .env                        # (ignored)
│
├── backend/                        # Laravel 13 + Sanctum (port 8000)
│   ├── app/
│   │   ├── Http/Controllers/       # Auth, User, Faculty, Subject, Room,
│   │   │                           #   Section, Schedule, ScheduleApproval,
│   │   │                           #   ScheduleSession, Report
│   │   ├── Models/                 # User, Faculty, Room, Subject, Section,
│   │   │                           #   Schedule, ScheduleSession, ...
│   │   └── Providers/AppServiceProvider.php
│   ├── bootstrap/app.php
│   ├── config/
│   ├── database/migrations/        # 11 domain tables + 3 framework tables
│   ├── routes/api.php
│   └── .env                        # (ignored)
│
├── frontend/                       # React + TypeScript + Vite (port 5173)
│   └── src/
│       ├── App.tsx
│       ├── context/AuthContext.tsx
│       └── pages/                  # Login, AdminDashboard,
│                                   #   Users, Faculty, Subjects, Rooms,
│                                   #   Sections, Reports
│
├── documentation/                  # API.md, Requirements.md, SRS.md, Modules.md,
│                                   #   Constraints.md, Bug-Fix-Log.md, ...
│
├── .gitignore
├── LICENSE
└── README.md
```
