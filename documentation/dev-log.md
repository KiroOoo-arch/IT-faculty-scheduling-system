
---

## Features Implemented

| Feature | Description |
|---|---|
| RBAC | Admin/Faculty roles via Laravel Sanctum |
| CRUD Operations | Faculty, Subjects, Rooms, Sections, Users |
| Faculty-Subject Assignment | Qualifications mapping |
| AI Schedule Generation | OR-Tools CP-SAT with 8 constraints |
| Best-Effort Scheduling | Places what it can, reports why rest failed |
| Room Capacity Enforcement | Prevents overcrowding |
| Approval Workflow | Draft → Approved → Published |
| Faculty Portal | View published schedule |
| Reports | Faculty workload, room utilization |
| Section Dropdown | Generate schedules for any section |
| Auto-Archive Drafts | Old drafts archived on new generation |
| Archived Schedule Viewer | Toggle to show/hide archived |

## AI Constraints (8 Total)

| # | Constraint | Purpose |
|---|---|---|
| 1 | Faculty qualification | Only qualified teachers assigned |
| 2 | Faculty availability | No scheduling outside declared hours |
| 3 | Room type matching | Labs in labs, lectures in lecture rooms |
| 4 | Room capacity | Students ≤ room capacity |
| 5 | No faculty double-booking | One teacher, one place at a time |
| 6 | No room double-booking | One room, one class at a time |
| 7 | Max teaching load | Faculty under max hours |
| 8 | Cross-section conflicts | Avoids conflicts with published schedules |

---

## Bug Fix Log

### Bug #1: 302 Redirect on CRUD Forms
- **Symptom**: Creating/editing Sections, Subjects returned 302 redirect instead of JSON
- **Root Cause**: Frontend fetch missing `Accept: application/json` header
- **Fix**: Added `headers()` helper function with `'Accept': 'application/json'`
- **Files**: `SectionsPage.tsx`, `FacultyPage.tsx`, `SubjectsPage.tsx`

### Bug #2: Time Format Validation Error
- **Symptom**: `"The preferred start time must match format H:i."`
- **Root Cause**: Browser `type="time"` input sends `"07:30 am"` but Laravel expects `"07:30"`
- **Fix**: Added `.split(' ')[0]` to strip AM/PM before sending
- **Files**: `SectionsPage.tsx` (`handleSubmit`)

### Bug #3: Subject Not Appearing in Generated Schedule
- **Symptom**: Creating a subject → generating → not in schedule
- **Root Cause**: Subject not assigned to section AND/OR no faculty qualified
- **Fix**: Two-step: (1) Edit Section → check subject, (2) Edit Faculty → check subject
- **Files**: `SectionsPage.tsx`, `FacultyPage.tsx`

### Bug #4: FOLA Subject Never Scheduled (Critical)
- **Symptom**: FOLA always dropped even with qualified faculty (Romel Oyao)
- **Root Cause**: `app.py` queried `faculty_availabilities` → empty for Romel → `available_days = []` → AI restricted faculty to zero days
- **Fix**: `if not available_days: available_days = section["preferred_days"]`
- **Files**: `ai-engine/api/app.py`

### Bug #5: Python Crash — 'int' object does not support indexing
- **Symptom**: 500 Internal Server Error on schedule generation
- **Root Cause**: `(f["id"])` in Python is NOT a tuple (missing trailing comma)
- **Fix**: Changed `(f["id"])` to `(f["id"],)`
- **Files**: `ai-engine/api/app.py` (line ~128)

### Bug #6: Schedule Stacking (Duplicate Faculty Schedules)
- **Symptom**: Faculty saw sessions from multiple published schedules
- **Root Cause**: No cleanup when publishing new schedules
- **Fix**: Added auto-archiving of old published schedules in `publish()`
- **Files**: `ScheduleApprovalController.php`

### Bug #7: Wrong Section ID Sent on Generate
- **Symptom**: Dashboard showing BIT-2A but generating for section ID 1
- **Root Cause**: `selectedSectionId` initialized to `1`, not synced with dropdown
- **Fix**: Changed state to `null`, added `useEffect` for auto-select
- **Files**: `AdminDashboard.tsx`

### Bug #8: Admin Dashboard Cluttered with Drafts
- **Symptom**: Old draft schedules piling up
- **Root Cause**: No cleanup when generating new schedules
- **Fix**: Auto-archive old drafts on generate + "Show Archived" toggle
- **Files**: `ScheduleController.php`, `ScheduleApprovalController.php`, `AdminDashboard.tsx`

---

## Test Results: 18/18 Passing

---

## Features Added (Round 2)

| Feature | Description |
|---|---|
| Faculty Availability UI | Set preferred days/times per faculty via edit form |
| Schedule Session Editing | Admin can manually edit day/time/room/faculty per session |
| Conflict Detection | Backend validates changes prevent double-booking |
| Delete Schedules | Draft/archived schedules can be permanently deleted |

## New Bug Fixes

### Bug #7: ScheduleController.php Corrupted (Duplicate Class)
- **Symptom**: Delete button caused `Call to undefined method destroy()`
- **Root Cause**: `ScheduleController.php` accidentally had `class ScheduleApprovalController` instead of `class ScheduleController`
- **Fix**: Restored correct class name in `ScheduleController.php`
- **Files**: `ScheduleController.php`

### Bug #8: Time Format on Session Edit
- **Symptom**: `"The start time field must match the format H:i."` when editing a session
- **Root Cause**: Browser `type="time"` sends `"09:00 am"` but Laravel expects `"09:00"`
- **Fix**: Added `.split(' ')[0]` before sending the PUT request
- **Files**: `AdminDashboard.tsx`


graph TB
    subgraph Frontend["Frontend (React + TypeScript + Vite)"]
        AD[Admin Dashboard]
        FP[Faculty Portal]
        CP[CRUD Pages]
        RP[Reports Page]
    end

    subgraph Backend["Backend (Laravel 13 + Sanctum)"]
        API[REST API]
        AUTH[Authentication & RBAC]
        VALID[Validation & Conflict Detection]
        DB[(PostgreSQL)]
    end

    subgraph AI["AI Engine (Python FastAPI)"]
        SOLVER[OR-Tools CP-SAT Solver]
        CONSTRAINTS[8 Scheduling Constraints]
    end

    Frontend -->|HTTP/JSON| Backend
    Backend -->|HTTP POST| AI
    AI -->|OPTIMAL/PARTIAL/INFEASIBLE| Backend
    Backend -->|JSON Response| Frontend

erDiagram
    USERS ||--o{ FACULTY : has
    FACULTY ||--o{ FACULTY_SUBJECTS : qualified
    FACULTY ||--o{ FACULTY_AVAILABILITIES : available
    FACULTY ||--o{ SCHEDULE_SESSIONS : teaches
    SUBJECTS ||--o{ FACULTY_SUBJECTS : taught-by
    SUBJECTS ||--o{ SECTION_SUBJECTS : assigned-to
    SUBJECTS ||--o{ SCHEDULE_SESSIONS : scheduled-as
    SECTIONS ||--o{ SECTION_SUBJECTS : includes
    SECTIONS ||--o{ SCHEDULES : generates
    ROOMS ||--o{ SCHEDULE_SESSIONS : hosts
    SCHEDULES ||--o{ SCHEDULE_SESSIONS : contains

flowchart LR
    A[Create Subject] --> B[Assign to Section]
    B --> C[Assign to Faculty]
    C --> D[Set Availability]
    D --> E[Generate Schedule]
    E --> F{AI Result}
    F -->|OPTIMAL| G[Review]
    F -->|PARTIAL| G
    F -->|INFEASIBLE| H[Adjust] --> E
    G --> I{Need Edit?}
    I -->|Yes| J[Edit Session] --> G
    I -->|No| K[Approve] --> L[Publish]
    L --> M[Faculty Views]
