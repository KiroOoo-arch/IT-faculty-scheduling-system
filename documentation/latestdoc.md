Development Log — IT Faculty Scheduling System
System Architecture

React (Frontend) → Laravel (API, Auth, Business Logic) → Python FastAPI (AI Engine, CP-SAT Solver) → PostgreSQL
Features Implemented
Feature	Description
RBAC	Admin-only access via Laravel Sanctum (login rejects non-admin roles; faculty are records, not users)
CRUD Operations	Faculty, Subjects, Rooms, Sections, Users
Faculty-Subject Assignment	Qualifications mapping
AI Schedule Generation	OR-Tools CP-SAT with 8 constraints
Best-Effort Scheduling	Places what it can, reports why rest failed
Room Capacity Enforcement	Prevents overcrowding
Approval Workflow	Draft → Approved → Published
Print/Download (PDF)	Print published schedule for hard-copy distribution (Faculty Portal removed by design decision)
Reports	Faculty workload, room utilization
Section Dropdown	Generate schedules for any section
Auto-Archive	Old drafts archived on new generation
Archived Schedule Viewer	Toggle to show/hide archived
AI Constraints (8 Total)
#	Constraint	Purpose
1	Faculty qualification	Only qualified teachers assigned
2	Faculty availability	No scheduling on unavailable days (day-level)
3	Room type matching	Labs in labs, lectures in lecture rooms
4	Room capacity	Students ≤ room capacity
5	No faculty double-booking	One teacher, one place at a time
6	No room double-booking	One room, one class at a time
7	Max teaching load	Faculty under max hours
8	Cross-section conflicts	Avoids conflicts with published schedules
Bug Fix Log
Bug #1: 302 Redirect on CRUD Forms
Symptom: Creating/editing Sections, Subjects returned 302 redirect instead of JSON
Root Cause: Frontend fetch missing Accept: application/json header
Fix: Added headers() helper function with 'Accept': 'application/json'
Files: SectionsPage.tsx, FacultyPage.tsx, SubjectsPage.tsx
Bug #2: Time Format Validation Error
Symptom: "The preferred start time must match format H:i."
Root Cause: Browser type="time" input sends "07:30 am" but Laravel expects "07:30"
Fix: Added .split(' ')[0] to strip AM/PM before sending
Files: SectionsPage.tsx (handleSubmit function)
Bug #3: Subject Not Appearing in Generated Schedule
Symptom: Creating a subject → generating → not in schedule
Root Cause: Subject not assigned to section AND/OR no faculty qualified to teach it
Fix: Two-step: (1) Edit Section → check subject box, (2) Edit Faculty → check subject box
Files: SectionsPage.tsx (added subject checkboxes), FacultyPage.tsx
Bug #4: FOLA Subject Never Scheduled (Critical)
Symptom: FOLA always dropped even with qualified faculty (Romel Oyao)
Root Cause: app.py queried faculty_availabilities table → empty result for Romel → available_days = [] → AI restricted faculty to zero days
Fix: Added fallback if not available_days: available_days = section["preferred_days"]
Files: ai-engine/api/app.py
Bug #5: Python Crash — 'int' object does not support indexing
Symptom: 500 Internal Server Error on schedule generation
Root Cause: (f["id"]) in Python is NOT a tuple (missing trailing comma). psycopg2 tried to index into an integer.
Fix: Changed (f["id"]) to (f["id"],)
Files: ai-engine/api/app.py (line ~128)
Bug #6: Schedule Stacking (Duplicate Faculty Schedules)
Symptom: Faculty saw sessions from multiple published schedules
Root Cause: No cleanup when publishing new schedules
Fix: Added auto-archiving of old published schedules in publish() method
Files: ScheduleApprovalController.php
Bug #7: Wrong Section ID Sent on Generate
Symptom: Dashboard showing BIT-2A but generating for section ID 1
Root Cause: selectedSectionId initialized to 1, not synced with dropdown
Fix: Changed state to null, added useEffect to auto-select first section
Files: AdminDashboard.tsx
Bug #8: Admin Dashboard Cluttered with Drafts
Symptom: Old draft schedules piling up, hard to track current one
**Root Cause`: No cleanup when generating new schedules
Fix: Added auto-archive old drafts on generate + "Show Archived" toggle button
Files: ScheduleController.php, ScheduleApprovalController.php, AdminDashboard.tsx
Test Results: 18/18 Passing

Authentication & RBAC       ✅
CRUD Operations             ✅
AI Schedule Generation      ✅  (OPTIMAL / PARTIAL / INFEASIBLE)
Manual Edit Conflict Check  ✅
Schedule Approval Workflow  ✅
Reports (Workload, Rooms)   ✅
Print/Download (PDF)        ✅

01/08/26