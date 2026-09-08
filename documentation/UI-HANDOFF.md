# UI Handoff — Faculty Login Removal + Print/Download Flow

> **Context:** The backend now enforces admin-only access. Faculty are records, not login accounts.
> This document lists the frontend changes needed to match. All backend endpoints are ready.

---

## 1. Why faculty login was removed (design decision)

- The system is used **per semester** by the department — faculty accounts add no value.
- Published schedules are distributed as **printed/PDF hard copies** (browser print → Save as PDF).
- Removing faculty login reduces the security surface and simplifies the role model.

---

## 2. Frontend changes needed

### 2.1 Remove the Faculty Dashboard
- Delete `frontend/src/pages/FacultyDashboard.tsx`
- In `frontend/src/App.tsx`:
  - Remove the `FacultyDashboard` import
  - The `Dashboard` component currently does:
    `return user.role === 'admin' ? <AdminDashboard /> : <FacultyDashboard />`
  - Change it to always render `<AdminDashboard />` (only admin accounts exist now)

### 2.2 Users Page — admin accounts only
- `frontend/src/pages/admin/UsersPage.tsx`
- Remove the role selector / `role: 'faculty'` defaults — all created users are admins
- Backend now validates `role` as `in:admin` only; sending `faculty` returns a 422

### 2.3 Faculty Page — name field instead of user linking
- `frontend/src/pages/admin/FacultyPage.tsx`
- The backend no longer requires `user_id`. It now expects:
  - `name` (required, string) — the faculty member's name
  - `faculty_type` (required: `full_time` | `part_time`)
  - `max_teaching_load` (required, integer ≥ 1)
- Replace any user-account dropdown/linking with a plain **Name** text field
- `GET /api/faculties` returns `name` directly on each record (no nested `user` object needed)

### 2.4 Print / Download button (NEW feature)
- Where: schedule list in `AdminDashboard.tsx`, on schedules with `status === 'published'` (optionally also `approved` for review copies)
- Behavior:
  1. Open a print-friendly view of the schedule (modal or route)
  2. Render a clean weekly grid from `GET /api/schedules/{id}` (already returns section + sessions with subject, faculty, room)
  3. Call `window.print()`; the user picks a printer or **Save as PDF**
- Print CSS requirements:
  - `@media print`: hide nav, sidebar, buttons, headers — show only the grid
  - Landscape orientation (`@page { size: A4 landscape; }`)
  - Visible table borders, black-on-white, no shadows/colors
  - Header block: department name, section name, semester, academic year
- Grid layout: day columns (Mon–Sat) × time rows; each cell shows subject code/title, faculty name, room name, session type

---

## 3. Backend contract summary (for reference)

| Change | Detail |
|---|---|
| Login | `POST /api/login` — non-admin roles are rejected with a validation error |
| Faculty create/update | `POST/PUT /api/faculties` — `name` required, `user_id` optional/nullable |
| Faculty list | `GET /api/faculties` — returns `name` on the record |
| Removed endpoints | `GET /api/my-schedule`, `POST /api/admin/create-faculty` |
| User create/update | `role` accepts `admin` only |
| User delete | Cannot delete yourself or the last admin (422) |
| All routes | Admin-only via `EnsureUserIsAdmin` middleware |

---

## 4. Testing checklist for the UI work

- [ ] Login with admin → lands on Admin Dashboard
- [ ] Faculty page: create a faculty with just a name (no user account)
- [ ] Faculty page: existing faculty show their names correctly
- [ ] Users page: role selector removed / fixed to admin
- [ ] Published schedule shows Print/Download button
- [ ] Print view renders a clean weekly grid; browser print preview looks correct
- [ ] Save as PDF produces a readable hard copy
