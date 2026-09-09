# API Reference

## Laravel API (`http://127.0.0.1:8000/api`)

### Authentication

| Method | Endpoint | Status | Description |
|---|---|---|---|
| POST | `/api/login` | ✅ Implemented | Admin login with email/password, returns token. Non-admin roles are rejected. |
| POST | `/api/logout` | ✅ Implemented | User logout, revokes token |
| GET | `/api/me` | ✅ Implemented | Get current authenticated admin user |

> **Note:** Only the Admin/Department Head can log in. Faculty are records, not login accounts.

### Faculty Management

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/api/faculties` | ✅ Implemented | List all faculty with nested `subjects`, `availabilities` |
| GET | `/api/faculties/{id}` | ✅ Implemented | Get one faculty record with nested data |
| POST | `/api/faculties` | ✅ Implemented | Create new faculty record (`name` required; no login account) |
| PUT | `/api/faculties/{id}` | ✅ Implemented | Update faculty record |
| DELETE | `/api/faculties/{id}` | ✅ Implemented | Delete faculty with cascade cleanup |

### Subject Management

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/api/subjects` | ✅ Implemented | List all subjects with nested `faculties` |
| GET | `/api/subjects/{id}` | ✅ Implemented | Get one subject record |
| POST | `/api/subjects` | ✅ Implemented | Create new subject |
| PUT | `/api/subjects/{id}` | ✅ Implemented | Update subject |
| DELETE | `/api/subjects/{id}` | ✅ Implemented | Delete subject |

### Room Management

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/api/rooms` | ✅ Implemented | List all rooms |
| GET | `/api/rooms/{id}` | ✅ Implemented | Get one room |
| POST | `/api/rooms` | ✅ Implemented | Create new room |
| PUT | `/api/rooms/{id}` | ✅ Implemented | Update room |
| DELETE | `/api/rooms/{id}` | ✅ Implemented | Delete room with cascade cleanup |

### Section Management

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/api/sections` | ✅ Implemented | List all sections with nested `subjects` |
| GET | `/api/sections/{id}` | ✅ Implemented | Get one section |
| POST | `/api/sections` | ✅ Implemented | Create new section |
| PUT | `/api/sections/{id}` | ✅ Implemented | Update section with subject sync |
| DELETE | `/api/sections/{id}` | ✅ Implemented | Delete section with cascade cleanup |

### Schedule Management

| Method | Endpoint | Status | Description |
|---|---|---|---|
| POST | `/api/schedules/generate/{section}` | ✅ Implemented | Generate schedule for section via AI engine |
| GET | `/api/schedules` | ✅ Implemented | List all schedules |
| GET | `/api/schedules/{id}` | ✅ Implemented | Get one schedule with sessions |
| PATCH | `/api/schedules/{id}/approve` | ✅ Implemented | Approve draft schedule |
| PATCH | `/api/schedules/{id}/publish` | ✅ Implemented | Publish approved schedule (with conflict gate) |
| PATCH | `/api/schedules/{id}/unpublish` | ✅ Implemented | Unpublish schedule (revert to draft) |
| DELETE | `/api/schedules/{id}` | ✅ Implemented | Delete schedule |

### Schedule Session Management

| Method | Endpoint | Status | Description |
|---|---|---|---|
| PATCH | `/api/schedule-sessions/{id}` | ✅ Implemented | Update session with conflict detection |

### Schedule Distribution (Print/PDF)

> **Faculty Portal removed by design decision** — faculty do not log into the system; they are scheduling records, not users. Published schedules are distributed as printed/PDF hard copies via the Print/Download button (see FR-017).

### Reports

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/api/reports/overview` | ✅ Implemented | Schedule status overview, faculty/room/section counts |
| GET | `/api/reports/workload` | ✅ Implemented | Faculty workload distribution |
| GET | `/api/reports/room-utilization` | ✅ Implemented | Room utilization rates |
| GET | `/api/reports/sections` | ✅ Implemented | Section schedules and session counts |
| GET | `/api/reports/generation-logs` | ✅ Implemented | Schedule generation history |

---

## AI Engine API (`http://127.0.0.1:8001`, FastAPI)

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/health` | ✅ Implemented | Health check + DB connectivity |
| POST | `/generate-schedule/{section_id}` | ✅ Implemented | Generate schedule using CP-SAT solver |

### Example response — `POST /generate-schedule/1`

```json
{
  "status": "OPTIMAL",
  "message": null,
  "sessions": [
    {
      "subject_id": 1,
      "session_type": "lecture",
      "day_of_week": 1,
      "start_hour": 10,
      "end_hour": 12,
      "room_id": 1,
      "faculty_id": 1
    }
  ]
}
```

---

## Integration Flow

1. **Frontend** calls Laravel API with Bearer token
2. **Laravel** handles authentication, business logic, and CRUD operations
3. **Laravel** calls FastAPI AI engine for schedule generation
4. **FastAPI** queries database directly and runs CP-SAT solver
5. **FastAPI** returns result to Laravel
6. **Laravel** persists schedule to database
7. **Frontend** displays result

---

## Notes

- All endpoints require authentication via Bearer token (except `/api/login`)
- All endpoints are admin-only — faculty are records, not users; non-admin logins are rejected at the login endpoint
- Schedule generation uses Google OR-Tools CP-SAT solver
- Conflict detection runs in real-time on manual session edits
- Publish conflict gate prevents cross-section double-booking

**Last Updated:** September 8, 2026
