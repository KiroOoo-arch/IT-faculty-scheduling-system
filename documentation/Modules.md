# System Modules

The functional modules of the IT Faculty Scheduling System, with their current implementation status.

| Module | Where | Status | Responsibility |
|---|---|---|---|
| Authentication | `LoginPage`, `AuthController`, `admin` middleware | ✅ Implemented, tested | Sanctum token login, admin-only authorization, token revocation on logout |
| User Management | `/admin/users`, `UserController` | ✅ Implemented, tested | Admin accounts only; self-delete and last-admin guards |
| Faculty Management | `/admin/faculty`, `FacultyController` | ✅ Implemented, tested | Faculty records (no login accounts): name, employment type, max teaching load |
| Faculty Availability | Faculty → availability, `FacultyController` | ✅ Implemented, tested | Declared day + time windows; a hard boundary for the solver |
| Faculty Qualifications | `faculty_subjects`, `FacultyController` | ✅ Implemented, tested | Which faculty may teach which subjects |
| Room & Laboratory Management | `/admin/rooms`, `RoomController` | ✅ Implemented, tested | Type (`lecture`, `computer_lab`, `science_lab`, `electronics_lab`), capacity, status |
| Subject / Curriculum Management | `/admin/subjects`, `SubjectController` | ✅ Implemented, tested | Code, title, year level, semester, lecture/lab hours, required lab room type |
| Section Management | `/admin/sections`, `SectionController` | ✅ Implemented, tested | Sections, their preferred window, student count, and subject assignments |
| Schedule Generator | `/dashboard`, `ScheduleController`, `ai-engine` | ✅ Implemented, tested | CP-SAT generation via the AI engine, persisted as a draft |
| Schedule Review, Approval & Publishing | `/dashboard`, `ScheduleApprovalController` | ✅ Implemented, tested | Draft → approved → published, with reject and unpublish; publish conflict gate |
| Session Editing | `/dashboard`, `ScheduleSessionController` | ✅ Implemented, tested | Manual day/time/room/faculty edits with full-state conflict validation |
| Reports | `/admin/reports`, `ReportController` | ✅ Implemented, tested | Faculty load, room usage, section summary, schedule status, generation logs |
| Printable Output | `/print-schedule`, `/print-faculty-schedule` | ✅ Implemented, tested | Published timetables with official letterhead; subject-list or weekly-grid layout |
| Settings | `/admin/reports` (break), `SettingController` | ✅ Implemented, tested | The midday break, a hard constraint in the solver |
| Dashboard | `/dashboard` | ✅ Implemented, tested | Generation entry point, schedule review, counts summary |

## Planned, not built

| Module | Reference |
|---|---|
| Signature-before-approval | [`Planned-Signature-Approval.md`](Planned-Signature-Approval.md) — an agreed design for a Department Head signature before a schedule is approved |
| Multi-signatory approval | Same document — Department Head + Registrar, if the printed form requires it |
