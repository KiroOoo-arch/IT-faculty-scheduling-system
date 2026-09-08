# IT Faculty Scheduling System — Complete Guide

*Updated: September 8, 2026 — reflects the faculty-login removal and print distribution design*

---

## 1. What the System Does

An **AI-assisted scheduling system** for the IT Department that automatically generates conflict-free faculty, classroom, and laboratory schedules using constraint-based optimization (Google OR-Tools CP-SAT).

**The workflow:**

```
Admin Login → Manage Scheduling Data → Generate Schedule (AI)
           → Review/Edit → Approve → Publish → Print/Download for Distribution
```

**Key design decision:** Only the **Admin/Department Head** has a login account. Faculty are **records, not users** — the system is used per semester by the department, and published schedules are distributed as **printed/PDF hard copies**.

---

## 2. Technology Stack

| Tool | What It Does in Our System |
|------|---------------------------|
| **React + TypeScript** | Frontend — user interface (port 5173) |
| **Tailwind CSS** | Styling |
| **Vite** | Frontend build tool |
| **Laravel 13 + PHP 8.5** | Backend — API, business logic, auth (port 8000) |
| **Laravel Sanctum** | Token-based authentication |
| **Python + FastAPI** | AI scheduling engine (port 8001) |
| **Google OR-Tools CP-SAT** | The constraint solver — the "brain" |
| **PostgreSQL** | Database |

---

## 3. System Architecture

```
┌─────────────────────────────────────────────┐
│  FRONTEND (React + TypeScript, port 5173)   │
│  Admin Dashboard, CRUD pages, Reports       │
└──────────────────┬──────────────────────────┘
                   ↓ HTTP/JSON + Bearer token
┌─────────────────────────────────────────────┐
│  BACKEND (Laravel, port 8000)               │
│  Auth (admin-only), CRUD, approval flow,    │
│  conflict detection, reports                │
└──────────────────┬──────────────────────────┘
                   ↓ POST /generate-schedule/{id}
┌─────────────────────────────────────────────┐
│  AI ENGINE (FastAPI + CP-SAT, port 8001)    │
│  Solves the schedule with 8 constraints     │
└──────────────────┬──────────────────────────┘
                   ↓
┌─────────────────────────────────────────────┐
│  DATABASE (PostgreSQL)                      │
└─────────────────────────────────────────────┘
```

**Why admin-only access?** The system is used per semester by the department. Faculty schedules are printed and distributed, so faculty accounts add no value and only widen the security surface. Faculty remain as structured data (name, availability, qualifications, workload, employment type, subject assignments) — exactly what the solver needs.

---

## 4. System Flows

### 4.1 Schedule Generation
1. Admin selects a section and clicks **Generate Schedule**
2. Laravel archives old drafts for that section
3. Laravel calls the FastAI engine (`POST /generate-schedule/{section_id}`)
4. The engine queries the DB (section, subjects, qualified faculty, rooms, existing committed sessions)
5. CP-SAT solver runs with 8 hard constraints
6. Returns **OPTIMAL** (all placed), **PARTIAL** (best effort), or **INFEASIBLE**
7. Laravel persists the schedule as a **draft** and logs the generation

### 4.2 Approval → Publish → Print
1. Admin **Approves** the draft
2. Admin **Publishes** — a conflict gate checks cross-section faculty/room double-booking; older published schedules for the same section are auto-archived
3. Admin clicks **Print / Download** → print-friendly weekly grid → browser print dialog → **Save as PDF** → hard copies distributed to faculty and students

### 4.3 Unpublish
Published schedules can be reverted to **draft** for corrections, then re-approved and re-published.

### 4.4 Manual Session Editing
Draft/approved sessions can be edited (day, time, room, faculty) with **real-time conflict detection**: room type matching, faculty availability, same-section overlaps, and cross-schedule double-booking.

---

## 5. The 8 Hard Constraints (CP-SAT)

| # | Constraint |
|---|-----------|
| 1 | Faculty qualification — only qualified teachers assigned |
| 2 | Faculty availability — no scheduling outside declared hours |
| 3 | Room type matching — labs in labs, lectures in lecture rooms |
| 4 | Room capacity — students ≤ capacity |
| 5 | Faculty no double-booking |
| 6 | Room no double-booking |
| 7 | Max teaching load (default 24h/week) |
| 8 | Cross-section conflicts — checked against approved/published schedules |

*Future soft constraints:* mandatory lunch break, senior faculty priority, preference weighting.

---

## 6. Features Reference

| Feature | Where | Details |
|---------|-------|---------|
| Admin login | Login page | Sanctum token; **non-admin roles rejected** |
| Users Management | `/admin/users` | **Admin accounts only** — faculty do not have accounts; last-admin deletion guard |
| Faculty Management | `/admin/faculty` | Records with **name, type, max load**; qualifications; availability (days/times) — no login accounts |
| Subjects | `/admin/subjects` | Code, year level, semester, lecture/lab hours, lab room type |
| Rooms | `/admin/rooms` | Type (lecture/computer_lab), capacity, status |
| Sections | `/admin/sections` | Name, year level, semester, student count, preferred days/times, subject assignments |
| AI Generation | Dashboard | One-click per section; OPTIMAL/PARTIAL/INFEASIBLE |
| Approval workflow | Dashboard | Draft → Approved → Published → Archived (+ Unpublish) |
| Print/Download | Dashboard (published schedules) | Print-friendly weekly grid → PDF hard copy |
| Session editing | Dashboard | Real-time conflict detection |
| Reports | `/admin/reports` | Overview, Faculty Load, Room Usage, Sections, Generation Logs |

---

## 7. Database (11 tables)

`users` (admin only) · `faculties` (name stored directly, no login) · `faculty_availabilities` · `subjects` · `faculty_subjects` · `sections` · `section_subjects` · `rooms` · `schedules` · `schedule_sessions` · `schedule_generation_logs`

---

## 8. Users & Roles

| Role | Access |
|------|--------|
| **Administrator / Department Head** | The only login. Full access: manage data, generate, review/edit, approve, publish, unpublish, print, reports |
| **Faculty** | No system access — records used by the scheduler; receive printed schedules |

---

## 9. Verification & Tests

- Backend feature tests (`php artisan test`) run against a dedicated `scheduling_system_testing` database — real data is never touched
- **Section/year-level generation tests** verify the AI-generated schedule is attributed to the correct section, year level, and semester, that other sections' schedules are untouched, and that regeneration archives only the target section's drafts
- TypeScript typecheck clean; admin login, faculty records, reports, and conflict detection verified end-to-end

---

## 10. Defense Talking Points

1. **Constraint-based approach** — CP-SAT gives a mathematical guarantee, not trial-and-error
2. **8 hard constraints** covering real scheduling rules
3. **Best-effort philosophy** — PARTIAL results place what can be placed
4. **Admin-only security model** — deliberate design decision: per-semester use, printed distribution, reduced attack surface
5. **Real-time conflict detection** on manual edits
6. **Publish conflict gate** prevents cross-section double-booking
7. **Unpublish** allows corrections without losing work

**Likely question — "Why can't faculty log in?"**
Answer: The system is operated by the department once per semester. Faculty input (availability, qualifications) is collected by the admin and entered as records. Output (schedules) is distributed as printed/PDF copies. Login accounts would add maintenance and security overhead with no corresponding benefit.
