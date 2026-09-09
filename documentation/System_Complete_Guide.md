# IT Faculty Scheduling System — Complete Guide

*Updated: September 9, 2026 — synchronized with the verified architecture (commit 7cfe310): accurate solver terminology, three protection layers, room eligibility, and print/PDF distribution*

---

## 1. What the System Does

An **AI-assisted, constraint-based scheduling system** for the IT Department that generates faculty, classroom, and laboratory schedules using constraint-based optimization (Google OR-Tools CP-SAT).

**The workflow:**

```
Admin Login → Manage Scheduling Data → Generate Schedule (AI)
           → Review/Edit → Approve → Publish Conflict Gate → Publish
           → Print/Download → Distribution to Faculty + Students
```

**Key design decision:** Only the **Admin/Department Head** has a login account. Faculty are **scheduling records/entities, not system users** — the system is operated per semester by the department, and published schedules are distributed as **printed/PDF hard copies**.

---

## 2. Technology Stack

| Tool | What It Does in Our System |
|------|---------------------------|
| **React + TypeScript** | Frontend — admin interface (port 5173) |
| **Tailwind CSS** | Styling |
| **Vite** | Frontend build tool |
| **Laravel 13 + PHP 8.5** | Backend — API, business logic, auth (port 8000) |
| **Laravel Sanctum** | Token-based authentication |
| **Python + FastAPI** | AI scheduling engine (port 8001) |
| **Google OR-Tools CP-SAT** | The constraint solver — the "brain" |
| **PostgreSQL** | Database — the central persistent store |

---

## 3. System Architecture

```
Admin / Department Head
        ↓  (browser)
React + TypeScript Frontend (port 5173)
        ↓  HTTP/JSON + Bearer token
Laravel Backend API (port 8000)
        ↓                          ↓
Eloquent ORM /            HTTP POST /generate-schedule/{id}
business workflow                 ↓
        ↓                 FastAPI AI Engine (port 8001)
PostgreSQL                        ↓
        ↕                 OR-Tools CP-SAT Solver
(reads scheduling data            ↓
 via psycopg2)            Candidate Schedule
        ↓                         ↓
        ←──────── Laravel (stores DRAFT + generation log)
```

**Communication between components:**

| From → To | Mechanism |
|-----------|-----------|
| React → Laravel | HTTP/JSON requests with a Sanctum Bearer token |
| Laravel → FastAPI | HTTP POST generation request per section |
| Laravel → PostgreSQL | Eloquent ORM / business workflow operations |
| FastAPI → PostgreSQL | Scheduling-data reads through psycopg2 |
| FastAPI → CP-SAT | Constraint solving |
| Laravel → React | JSON responses |

**Why admin-only access?** The department operates the system per semester. Faculty scheduling information is maintained as records by the Admin, and finalized schedules are distributed as printed/PDF copies. Faculty accounts would add maintenance and security overhead with no corresponding benefit. Faculty remain structured data (name, employment type, availability, qualifications, workload, subject assignments) — exactly what the solver needs.

**Human oversight:** the AI produces a *candidate* schedule only. It never publishes. "AI proposes; the Admin decides."

---

## 4. System Flows

### 4.1 Schedule Generation
1. Admin selects a section and clicks **Generate Schedule**
2. React sends an authenticated request; Laravel validates it and controls the workflow
3. Laravel archives old drafts for that section
4. Laravel calls the FastAPI engine (`POST /generate-schedule/{section_id}`)
5. FastAPI reads scheduling data from PostgreSQL: section information, preferred days/time window, assigned subjects, lecture/lab requirements, qualified faculty, faculty availability, teaching loads, available rooms (type, capacity, status), and existing approved/published sessions for cross-section conflict checking
6. The CP-SAT solver generates a candidate schedule, maximizing the number of successfully scheduled sessions
7. Returns **OPTIMAL / FEASIBLE / PARTIAL / INFEASIBLE**
8. Laravel persists a successful result as a **draft** schedule with its sessions and records the generation log
9. React displays the draft to the Admin

### 4.2 Approval → Publish → Print
1. Admin **reviews** the draft and may **manually edit** sessions (conflict-checked — see Layer 2 below)
2. Admin **approves** the schedule
3. Admin **publishes** — the publish conflict gate performs a final validation; if conflicts are found, publication is rejected and the schedule stays unpublished until corrected and retried
4. On success, older approved/published schedules for the same section may be archived (current implementation behavior)
5. Admin clicks **Print / Download** → print-friendly weekly grid → browser print dialog → **Save as PDF** → hard copies distributed to faculty and students

**Publishing is NEVER automatic.** The Admin/Department Head explicitly approves and publishes.

### 4.3 Unpublish
Published → **Unpublish** → Draft → Edit → Approve → Publish again. Unpublishing allows corrections while preserving the workflow; it does not restore any earlier database state — it returns the current schedule to draft for editing.

### 4.4 Manual Session Editing
The Admin can review and manually modify generated sessions (day, time, room, faculty). Each edit is validated server-side against the scheduling rules — room type matching, faculty double-booking, room double-booking, room/lab eligibility, and time-window fit — and rejected with a clear error on conflict. Faculty themselves never edit schedules; they have no system access.

---

## 5. Scheduling Constraints (CP-SAT)

The scheduler models **eight main constraint categories**, with the **section's preferred time window also directly enforced** by the solver:

| # | Constraint |
|---|-----------|
| 1 | Faculty qualification — only qualified teachers assigned (`faculty_subjects`) |
| 2 | Faculty availability — restricted by each teacher's available **days** (`faculty_availabilities`) |
| 3 | Room type matching — labs in the required lab type, lectures in lecture rooms |
| 4 | Room capacity — student count ≤ room capacity |
| 5 | Faculty no double-booking |
| 6 | Room no double-booking |
| 7 | Maximum teaching load — total scheduled hours per teacher ≤ `max_teaching_load` |
| 8 | Cross-section conflicts — checked against existing approved/published sessions |

Also directly enforced by the solver:

- **Section preferred scheduling window** — all sessions must fit within the section's preferred days and start/end time; if a subject's required hours cannot fit, the solver reports `INFEASIBLE` with an explanation
- **Section self-overlap prevention** — a section cannot attend two sessions at once

**Objective:** the solver maximizes the number of successfully scheduled sessions (best-effort scheduling).

*Future soft constraints (not implemented):* mandatory lunch break, senior faculty priority, preference weighting.

---

## 6. Solver Result Terminology

| Status | Meaning |
|--------|---------|
| **OPTIMAL** | All required sessions scheduled; the solver proved the objective optimal |
| **FEASIBLE** | A feasible solution was found; optimality was not necessarily proven within the solver run |
| **PARTIAL** | Some sessions were scheduled; others could not be placed |
| **INFEASIBLE** | No sessions could be successfully scheduled under the modeled constraints |

Unscheduled sessions include reported reasons such as: no suitable room of the required type, no qualified faculty, insufficient scheduling window, capacity/type constraints, or other modeled conflicts. The system does **not** guarantee every section produces OPTIMAL.

---

## 7. Room & Laboratory Eligibility

Room eligibility considers three factors:

1. **Room type** — must match the session requirement
2. **Room capacity** — section student count must not exceed capacity
3. **Room status/availability** — the room must be available

Current room types: `lecture`, `computer_lab`, `science_lab`, `electronics_lab`.

A subject's lab requirement (`lab_room_type`) determines the required laboratory room type — e.g., a subject requiring `computer_lab` labs can only have its lab sessions placed in `computer_lab` rooms; lecture sessions require lecture rooms.

---

## 8. Three Protection Layers

Conflicts are prevented at three independent points:

**Layer 1 — AI/CP-SAT generation.** The scheduler applies the modeled scheduling constraints while generating the candidate schedule.

**Layer 2 — Manual edit validation.** When the Admin manually changes a session, backend validation checks the relevant scheduling rules and detects conflicts.

**Layer 3 — Publish conflict gate.** Before publication, the backend performs the final conflict validation and blocks publication when conflicts exist.

> **"AI proposes; the Admin decides."** The AI is not the final authority.

---

## 9. Features Reference

| Feature | Where | Details |
|---------|-------|---------|
| Admin login | Login page | Sanctum token; **non-admin roles rejected at login** |
| Users Management | `/admin/users` | **Admin accounts only** — faculty do not have accounts; last-admin deletion guard |
| Faculty Management | `/admin/faculty` | Records with **name, employment type, max load** — no login accounts |
| Faculty Availability | Faculty → Edit | Available days/times used by the scheduler |
| Faculty Qualifications | Faculty → Edit | Faculty-subject qualification mapping |
| Subjects | `/admin/subjects` | Code, year level, semester, lecture/lab hours, lab room type (validated: lab hours require a lab room type and vice versa) |
| Rooms | `/admin/rooms` | Type (lecture / computer_lab / science_lab / electronics_lab), capacity, status |
| Sections | `/admin/sections` | Name, year level, semester, student count, preferred days/times, subject assignments |
| AI Schedule Generation | Dashboard | One click per section; OPTIMAL / FEASIBLE / PARTIAL / INFEASIBLE |
| Draft / Review / Edit | Dashboard | Manual session editing with conflict detection |
| Approval | Dashboard | Draft → Approved |
| Publish Conflict Gate | Dashboard | Final cross-section validation before publication |
| Unpublish | Dashboard | Published → Draft for corrections |
| Print/Download | Dashboard (published schedules) | Print-friendly weekly grid → PDF / hard copy |
| Reports | `/admin/reports` | Faculty Load, Room Usage, Sections, Status, Conflicts |
| Conflict Detection | Session editing + publish gate | Multiple protection layers |

---

## 10. Reports (current endpoints)

| Endpoint | Purpose |
|----------|---------|
| `/api/reports/faculty-workload` | Assigned hours vs max load per faculty member |
| `/api/reports/room-utilization` | Booked hours per week per room |
| `/api/reports/conflicts` | Cross-section conflict scan of published schedules |
| `/api/reports/schedule-status` | Schedule status overview (draft/approved/published/archived counts) |
| `/api/reports/section-summary` | Sessions, hours, faculty count per section |

---

## 11. Database (11 tables)

`users` (**admin login accounts only**) · `faculties` (scheduling records — name stored directly; `user_id` exists only as an optional/nullable legacy relationship, not a login mechanism) · `faculty_availabilities` · `subjects` · `faculty_subjects` · `sections` · `section_subjects` · `rooms` · `schedules` · `schedule_sessions` · `schedule_generation_logs`

---

## 12. Users & Roles

| Role | Access |
|------|--------|
| **Administrator / Department Head** | The only login. Full access: manage data, generate, review/edit, approve, publish, unpublish, print/download, reports |
| **Faculty** | No system access — scheduling records used by the engine; receive schedules via printed/PDF distribution |
| **Students** | No system access — see posted/published schedules |

---

## 13. Verification & Tests

- Backend feature tests (`php artisan test`, 6/6 passing) run against a dedicated `scheduling_system_testing` database — real data is never touched
- **Section/year-level generation tests** verify the AI-generated schedule is attributed to the correct section, year level, and semester, that other sections' schedules are untouched, and that regeneration archives only the target section's drafts
- Frontend TypeScript typecheck clean; production build passes
- Live end-to-end integration test performed (September 2026): admin login → AI generation for BIT-3A (OPTIMAL, 3 sessions, all 8 constraint categories verified) → approve → publish (conflict gate) → unpublish → reports — all passed

---

## 14. Defense Talking Points

**Why constraint-based scheduling?** Scheduling is a constraint satisfaction/optimization problem involving faculty, rooms, sections, time windows, qualifications, capacity, and conflicts. CP-SAT models it mathematically rather than by trial and error.

**Why OR-Tools CP-SAT?** CP-SAT provides a mathematical constraint-solving approach for finding feasible and optimized schedules.

**What does OPTIMAL mean?** When CP-SAT returns OPTIMAL, the solver has proven optimality for the modeled objective under the given constraints and search conditions.

**Is AI the final decision-maker?** No. The AI generates a candidate schedule. The Admin reviews, edits if necessary, approves, and explicitly publishes it.

**Why no faculty login?** The department operates the system per semester. Faculty scheduling information is maintained as records by the Admin, and finalized schedules are distributed as printed/PDF copies. Faculty accounts are therefore unnecessary for the current scope.

**How are conflicts prevented?** Through three layers: (1) solver constraints, (2) manual-edit validation, (3) the publish conflict gate.

**Likely question — "Why can't faculty log in?"**
Answer: The system is operated by the department once per semester. Faculty input (availability, qualifications) is collected by the admin and entered as records. Output (schedules) is distributed as printed/PDF copies. Login accounts would add maintenance and security overhead with no corresponding benefit.
