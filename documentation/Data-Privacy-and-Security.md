# Data Privacy & Security Statement

**IT Faculty Scheduling System**
*IT Department — Academic Year 2026–2027*

---

## 1. Privacy Notice

This system collects and processes personal information of faculty members **for the sole purpose of academic scheduling** within the IT Department:

| Data Collected | Purpose | Stored In |
|---|---|---|
| Faculty name | Identifying the teacher in schedules and reports | `faculties.name` |
| Employment type (full-time / part-time) | Load computation and scheduling priority | `faculties.faculty_type` |
| Maximum teaching load | Enforcing the teaching-load constraint | `faculties.max_teaching_load` |
| Availability (days and times) | Constraint-based schedule generation | `faculty_availabilities` |
| Subject qualifications | Matching qualified teachers to subjects | `faculty_subjects` |
| Admin account name and email | System authentication and audit | `users` |

**No sensitive personal information** (government IDs, health data, biometrics, contact details of students) is collected. Faculty accounts are not maintained in the system — faculty are records used by the scheduling engine, and their schedules are distributed as printed or PDF copies.

---

## 2. Data Protection & Security Controls

| Control | Implementation |
|---|---|
| **Authentication** | Laravel Sanctum token-based login; tokens revoked on logout and re-issue |
| **Authorization** | Admin-only access — every management route is behind the `EnsureUserIsAdmin` middleware (403 on failure); login explicitly rejects non-admin roles |
| **Password storage** | Bcrypt hashing (never stored in plain text) |
| **Last-admin guard** | The system prevents deleting your own account or the last remaining admin, preventing lockout |
| **Input validation** | All API payloads validated server-side (Laravel validator) |
| **SQL injection prevention** | Eloquent ORM with parameter binding; the AI engine uses parameterized SQL (`psycopg2` placeholders) |
| **XSS prevention** | React escapes all rendered output by default |
| **CSRF protection** | Laravel's built-in CSRF/session protections for web surfaces; API uses bearer tokens, not cookies |
| **Transport** | Deployed on the department's internal network; API access requires a valid token on every request |
| **Audit trail** | Every schedule generation is recorded in `schedule_generation_logs` (who requested it, status, unscheduled reasons) |

---

## 3. Data Retention & Disposal

| Data | Retention | Disposal |
|---|---|---|
| Active schedules | Retained while the semester is in effect | Older published/approved schedules are **auto-archived** when a new one is published |
| Archived schedules | Kept for the academic year as historical reference | Admin may delete draft/archived schedules (cascade-deletes all sessions) |
| Generation logs | Kept as an audit history | Removed with the associated data on cleanup |
| Faculty records | Kept while the faculty member is active in the department | Deleting a faculty record cascade-deletes their qualifications, availability, and schedule sessions |
| Admin accounts | Kept while the account holder is authorized | Deleted on separation/role change; the last-admin guard keeps the system accessible |

---

## 4. Data Subject Rights

Faculty members whose data appears in the system have the right to:

1. **Be informed** — this notice describes what data is kept and why
2. **Access** — faculty may request a copy of their record (name, type, load, availability, qualifications) from the Department Head
3. **Rectification** — faculty may request corrections to their availability, qualifications, or load through the Department Head, who updates the record
4. **Erasure** — faculty may request removal of their record when no longer needed for scheduling
5. **Complaint/redress** — concerns may be raised with the Department Head or the institution's data protection officer

Requests are fulfilled manually by the Administrator/Department Head, consistent with the system's design as a departmental tool.

---

## 5. Terms of Use / Acceptable Use

- Access is restricted to the authorized Administrator/Department Head of the IT Department
- Credentials must not be shared; the administrator is accountable for all actions performed under the account
- Faculty data may be used **only** for scheduling and related academic administration — not for any other purpose
- Printed/PDF schedules distributed to faculty and students should reflect only scheduling information (subject, time, room, faculty name)

---

## 6. Disclaimer

This system is an academic capstone project developed for the IT Department's internal scheduling operations. While it enforces conflict-free scheduling through constraint-based validation, the Department Head remains responsible for reviewing and approving all schedules before publication and distribution. The system's output supplements — not replaces — professional judgment.

---

*Last updated: September 8, 2026*
