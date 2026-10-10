# Requirements

## Functional Requirements

| ID | Requirement | Status |
|---|---|---|
| FR-001 | Admin login/authentication (single admin/Department Head account) | ✅ Implemented — Laravel Sanctum with token-based auth |
| FR-002 | Manage faculty records (create, view, update, delete) — faculty are records, not login accounts | ✅ Implemented — Full CRUD with name stored on the record |
| FR-003 | Manage subjects (create, view, update, delete) | ✅ Implemented — Full CRUD with faculty qualification mapping |
| FR-004 | Generate a conflict-free schedule for a section using an AI/constraint solver | ✅ Implemented — FastAPI + OR-Tools CP-SAT solver with 8 constraints |
| FR-005 | Detect and report scheduling conflicts (faculty, room, section double-booking) | ✅ Implemented — Real-time conflict detection on manual edits |
| FR-006 | Store faculty availability (days/times each faculty can teach) | ✅ Implemented — faculty_availabilities table with day/time ranges |
| FR-007 | Link faculty to subjects they are qualified to teach | ✅ Implemented — faculty_subjects pivot table with CRUD |
| FR-008 | Link sections to the subjects they take in a given semester | ✅ Implemented — section_subjects pivot table with sync |
| FR-009 | Track room type and capacity for scheduling | ✅ Implemented — rooms table with type, capacity, status |
| FR-010 | Persist generated schedules for later review/approval | ✅ Implemented — schedules + schedule_sessions tables |
| FR-011 | Schedule approval workflow | ✅ Implemented — Draft → Approved → Published → Archived |
| FR-013 | Schedule reports (workload, room utilization) | ✅ Implemented — ReportController with multiple report types |
| FR-014 | Session editing with conflict detection | ✅ Implemented — ScheduleSessionController with validation |
| FR-015 | Publish conflict gate (cross-section double-booking prevention) | ✅ Implemented — ScheduleApprovalController |
| FR-016 | Unpublish schedule (revert to draft) | ✅ Implemented — ScheduleApprovalController with status change |
| FR-017 | Print/Download published schedule for hard-copy distribution | ✅ Implemented — Print-friendly weekly grid (PrintableSchedule page) feeding the browser print dialog for print or save-as-PDF |
| FR-018 | Subject–section year/semester integrity: a section may only be assigned subjects whose year level and semester match the section's own; mismatched assignments are rejected at save time, and schedule generation is blocked for any legacy mismatched assignment | ✅ Implemented — Sections UI filters the subject checklist to the section's year level and semester; SectionController validates assignments (HTTP 422 naming offending subject codes); ScheduleController blocks generation (HTTP 422, AI engine never invoked) until mismatches are fixed |
| FR-019 | Subject lab consistency: lab_hours > 0 requires a lab_room_type of computer_lab, science_lab, or electronics_lab; lab_hours = 0 requires lab_room_type to be null. Enforced server-side on create and update (HTTP 422), so invalid subject data can never reach schedule generation | ✅ Implemented — SubjectController validates the lab_hours/lab_room_type pair on store and update; canonical types mirror the frontend constant shared with the Rooms page |

> **Note:** FR-012 (Faculty portal) was **removed by design decision** — faculty do not log into the system. The system is used per semester by the department; published schedules are distributed as printed/PDF copies. See `UI-HANDOFF.md` and the Architecture doc.

## Non-Functional Requirements

| Requirement | Status |
|---|---|
| **Security** | ✅ Implemented — Laravel Sanctum authentication, admin-only access (login rejects non-admin roles), last-admin deletion guard, input validation |
| **Performance** | ✅ Verified — OPTIMAL result in 1-2 seconds for small datasets, 5-10 seconds for medium datasets |
| **Reliability** | ✅ Implemented — Structured error messages, cascade deletes, data integrity constraints |
| **Scalability** | ⚠️ Partially tested — Department-level scheduling verified; university-scale would need architectural changes |
| **Usability** | ✅ Implemented — React frontend with intuitive dashboard and CRUD pages |
| **Maintainability** | ✅ Implemented — Clean separation of concerns, MVC architecture, documented code |

## Implementation Summary

### ✅ Fully Implemented
- Authentication (Sanctum, admin-only login)
- CRUD for Faculty, Subjects, Rooms, Sections, Users (admin accounts)
- AI Schedule Generation (OPTIMAL / FEASIBLE / PARTIAL / INFEASIBLE)
- Schedule Approval Workflow (Draft → Approved → Published → Archived)
- Unpublish Schedule (Published → Draft for editing)
- Reports Dashboard (Faculty Load, Room Usage, Sections, Schedule Status, Conflicts)
- Manual Edit Conflict Detection
- Publish Conflict Gate
- Cascade Delete Integrity
- Subject–Section Year/Semester Validation (pre-scheduling data-integrity/business-rule validation in Laravel — enforced at assignment time and again before AI generation; NOT an OR-Tools constraint)
- Subject Lab Consistency Validation (pre-scheduling subject data-integrity validation in Laravel — canonical lab room types required when lab hours exist; NOT an OR-Tools constraint)

### ⚠️ Partially Implemented
- Scalability (department-level only)
- Soft constraints (seniority preference) — note the midday break is implemented as a **hard** constraint, not a soft one

### 📋 Not Implemented (Future Enhancements)
- Multi-semester planning
- Email/SMS notifications
- Advanced analytics dashboard

## Test Results

| Test Category | Status |
|---|---|
| Authentication (admin-only login) | ✅ Passing |
| CRUD Operations | ✅ All endpoints verified |
| AI Schedule Generation | ✅ OPTIMAL / FEASIBLE / PARTIAL / INFEASIBLE |
| Subject–Section Year/Semester Validation | ✅ Mismatched assignments rejected with 422; generation blocked (AI engine never called) |
| Subject Lab Consistency Validation | ✅ Invalid lab_hours/lab_room_type combinations rejected with 422 on create and update |
| AI Engine Unit Tests | ✅ 118 unit tests across five modules directly exercising the CP-SAT solver (`generate_schedule()`) via stdlib unittest — 118 tests OK, 0 failed, 0 skipped, 0 warnings/errors |
| Manual Edit Conflict Check | ✅ Real-time validation |
| Published-Reference Delete Guard | ✅ Deleting faculty/subject/room/section still used by a published schedule returns HTTP 409; `?force=1` overrides after confirmation |
| Schedule Approval Workflow | ✅ Draft → Approved → Published |
| Unpublish Schedule | ✅ Published → Draft reversion |
| Reports Dashboard | ✅ Faculty Load, Room Usage, Sections, Schedule Status, Conflicts |

## Database Tables

| Table | Purpose | Status |
|---|---|---|
| users | Admin accounts (Department Head) — the only login accounts | ✅ Implemented |
| faculties | Faculty profiles (name stored directly; no login account) | ✅ Implemented |
| faculty_availabilities | Availability schedules | ✅ Implemented |
| subjects | Subject catalog | ✅ Implemented |
| faculty_subjects | Qualification mapping | ✅ Implemented |
| sections | Student sections | ✅ Implemented |
| section_subjects | Section-subject assignments | ✅ Implemented |
| rooms | Classrooms and labs | ✅ Implemented |
| schedules | Generated schedules | ✅ Implemented |
| schedule_sessions | Individual class blocks | ✅ Implemented |
| schedule_generation_logs | Audit trail | ✅ Implemented |

## Constraints Implemented

| # | Constraint | Type | Status |
|---|---|---|---|
| 1 | Faculty qualification | Hard | ✅ Enforced |
| 2 | Faculty availability | Hard | ✅ Enforced |
| 3 | Room type matching | Hard | ✅ Enforced |
| 4 | Room capacity | Hard | ✅ Enforced |
| 5 | Faculty no double-booking | Hard | ✅ Enforced |
| 6 | Room no double-booking | Hard | ✅ Enforced |
| 7 | Max teaching load | Hard | ✅ Enforced |
| 8 | Cross-section conflicts | Hard | ✅ Enforced |

## Notes

This document reflects the current state of the system as of October 2026. Most recent full verification: backend **143 tests passed (604 assertions)**, AI scheduler **118 unit tests passed** across five modules (0 failed/skipped/warnings), frontend production build passed, and the full live lifecycle verified (generate → review/edit → approve → publish → print → unpublish). This supersedes the September 16 verification, which recorded 90 backend tests / 380 assertions and 46 engine tests.

**September 27, 2026 — defects found by live API verification and fixed:** a fully-placed `FEASIBLE` solver result is now accepted (it was previously reported to the Admin as "No feasible schedule found."), and an unreachable AI engine now answers `502` while still writing a `failure` generation-log row (it previously returned a raw `500` and logged nothing). Regression tests were added; the suite has since grown from the 90 tests / 380 assertions recorded at the time of that fix to **143 tests / 604 assertions**, all passing.

**Design decision:** Faculty login accounts were removed at the instructor's direction. Faculty remain as data records (name, availability, qualifications, workload, employment type, subject assignments) used by the scheduling engine; only the Admin/Department Head authenticates. Published schedules are distributed as printed/PDF hard copies.

**Last Updated:** October 4, 2026 (documentation synchronization)
