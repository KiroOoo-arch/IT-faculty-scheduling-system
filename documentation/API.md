# API Reference

## Laravel API (`http://127.0.0.1:8000/api`)

All routes except `POST /login` require `Authorization: Bearer <token>`. Everything except
`/logout` and `/me` additionally requires the `admin` role. 50 routes in total.

### Authentication

| Method | Endpoint | Status | Description |
|---|---|---|---|
| POST | `/api/login` | ✅ Implemented | Admin login, returns a token. **401** wrong credentials, **403** valid non-admin account, **422** malformed body |
| POST | `/api/logout` | ✅ Implemented | Revokes the current token |
| GET | `/api/me` | ✅ Implemented | Current authenticated admin user |
| GET | `/api/login` | ✅ Implemented | Route fallback for an unauthenticated request — returns **401** rather than an HTML error page |

> **Note:** Only the Admin/Department Head can log in. Faculty are records, not login accounts.
> Logging in revokes the account's other tokens, so only one session is active at a time.

### User Management

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/api/users` | ✅ Implemented | List users |
| GET | `/api/users/{id}` | ✅ Implemented | Get one user |
| POST | `/api/users` | ✅ Implemented | Create a user (`name`, `email`, `password`, `role` — `admin` only) |
| PUT | `/api/users/{id}` | ✅ Implemented | Update a user |
| DELETE | `/api/users/{id}` | ✅ Implemented | Delete a user. **422** when deleting your own account, or the last remaining admin |

### Faculty Management

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/api/faculties` | ✅ Implemented | List all faculty with nested `subjects`, `availabilities` |
| GET | `/api/faculties/{id}` | ✅ Implemented | Get one faculty record with nested data |
| POST | `/api/faculties` | ✅ Implemented | Create faculty (`name`, `faculty_type` of `full_time`/`part_time`, `max_teaching_load`) |
| PUT | `/api/faculties/{id}` | ✅ Implemented | Update faculty record |
| DELETE | `/api/faculties/{id}` | ✅ Implemented | Delete faculty with cascade cleanup. **409** if a published schedule still uses them; retry with `?force=1` |
| GET | `/api/faculties/{id}/availability` | ✅ Implemented | List the faculty member's declared day/window rows |
| POST | `/api/faculties/{id}/availability` | ✅ Implemented | Replace availability. Body `{"availability":[{"day_of_week":1,"start_time":"08:00","end_time":"12:00"}]}` — an empty array clears every day. **422** for an inverted or half-specified window |
| POST | `/api/faculties/{id}/subjects` | ✅ Implemented | Attach qualified subjects (`subject_ids`) |
| DELETE | `/api/faculties/{id}/subjects/{subjectId}` | ✅ Implemented | Detach one subject |

### Subject Management

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/api/subjects` | ✅ Implemented | List all subjects with nested `faculties` |
| GET | `/api/subjects/{id}` | ✅ Implemented | Get one subject record |
| POST | `/api/subjects` | ✅ Implemented | Create subject. Validates lab consistency: `lab_hours > 0` requires `lab_room_type` of `computer_lab`, `science_lab`, or `electronics_lab`; `lab_hours = 0` requires `lab_room_type` null. Violations → **422** |
| PUT | `/api/subjects/{id}` | ✅ Implemented | Update subject — same lab consistency validation as create |
| DELETE | `/api/subjects/{id}` | ✅ Implemented | Delete subject. **409** if a published schedule still uses it; retry with `?force=1` |

### Room Management

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/api/rooms` | ✅ Implemented | List all rooms |
| GET | `/api/rooms/{id}` | ✅ Implemented | Get one room |
| POST | `/api/rooms` | ✅ Implemented | Create room. `type` is restricted to `lecture`, `computer_lab`, `science_lab`, `electronics_lab` |
| PUT | `/api/rooms/{id}` | ✅ Implemented | Update room |
| DELETE | `/api/rooms/{id}` | ✅ Implemented | Delete room with cascade cleanup. **409** if a published schedule still uses it; retry with `?force=1` |

> **Room-type vocabulary matters.** The solver matches a session to a room with an exact string
> comparison, so a free-text room type would silently make lab sessions unschedulable. The allowed
> values are mirrored in `frontend/src/constants/roomTypes.ts` and `SubjectController::LAB_ROOM_TYPES`.

### Section Management

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/api/sections` | ✅ Implemented | List all sections with nested `subjects` |
| GET | `/api/sections/{id}` | ✅ Implemented | Get one section |
| POST | `/api/sections` | ✅ Implemented | Create section. Validates subject assignments: every `subject_ids` entry must match the section's year level and semester, otherwise **422** naming the offending subject codes |
| PUT | `/api/sections/{id}` | ✅ Implemented | Update section with subject sync — same validation against the effective (post-update) values |
| DELETE | `/api/sections/{id}` | ✅ Implemented | Delete section with cascade cleanup. **409** if a published schedule still uses it; retry with `?force=1` |

> **Published-reference guard (409):** deleting a faculty, subject, room, or section still referenced
> by a **published** schedule returns **HTTP 409**
> `{requires_confirmation: true, published_schedule_ids, sessions_at_risk, published_sessions_at_risk}`.
> Re-send the same DELETE with `?force=1` to confirm and cascade.

### Schedule Management

| Method | Endpoint | Status | Description |
|---|---|---|---|
| POST | `/api/schedules/generate/{section}` | ✅ Implemented | Generate a draft via the AI engine. **422** if any assigned subject mismatches the section's year level or semester (the engine is never called). **422** carrying the engine's reason when the engine answers 4xx (no subjects, no qualified faculty, no available rooms). **502** only when the engine is unreachable or faults |
| GET | `/api/schedules` | ✅ Implemented | List schedules. `?show_archived=true` includes archived ones |
| GET | `/api/schedules/{id}` | ✅ Implemented | Get one schedule with sessions |
| PATCH | `/api/schedules/{id}/approve` | ✅ Implemented | Approve a draft. **422** for any other status |
| PATCH | `/api/schedules/{id}/publish` | ✅ Implemented | Publish an approved schedule. **422** if not approved, or if the cross-section conflict check finds a clash |
| PATCH | `/api/schedules/{id}/unpublish` | ✅ Implemented | Revert a published schedule to draft; clears `approved_by`/`approved_at` |
| PATCH | `/api/schedules/{id}/reject` | ✅ Implemented | Reject a draft. **422** for any other status |
| DELETE | `/api/schedules/{id}` | ✅ Implemented | Delete a schedule and its sessions. Every state except **published** is deletable; a published schedule answers **422** naming its status — unpublish it first |

### Schedule Session Management

| Method | Endpoint | Status | Description |
|---|---|---|---|
| PUT | `/api/schedules/sessions/{id}` | ✅ Implemented | Update a session's day/time/room/faculty. Validates the **full resulting state** and returns **422** with the conflicts if the change would clash, or if the schedule is not `draft`/`approved` |

### Settings

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/api/settings` | ✅ Implemented | The midday break (`lunch_start`, `lunch_end`, `lunch_enabled`) |
| PUT | `/api/settings` | ✅ Implemented | Update the break. **422** if the break would end before it starts |

### Reports

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/api/reports/faculty-workload` | ✅ Implemented | Assigned hours vs max load per faculty member, with subject codes |
| GET | `/api/reports/room-utilization` | ✅ Implemented | Booked hours per week per room |
| GET | `/api/reports/section-summary` | ✅ Implemented | Sessions, hours, faculty count per section |
| GET | `/api/reports/schedule-status` | ✅ Implemented | Draft / approved / published / archived counts |
| GET | `/api/reports/conflicts` | ⚠️ Misnamed | **Returns schedule generation logs**, not conflicts (section, status, message, unscheduled sessions, requester, timestamp). The UI tab consuming it is labelled "Generation Logs". Scheduled to be renamed `/reports/generation-logs` |
| GET | `/api/reports/faculty/{faculty}/schedule` | ✅ Implemented | A faculty member's published sessions (drives the faculty printable). **404** for an unknown faculty |

### Schedule Distribution (Print/PDF)

> **Faculty Portal removed by design decision** — faculty do not log into the system; they are
> scheduling records, not users. Published schedules are distributed as printed/PDF hard copies via
> the Print/Download button (see FR-017).

---

## AI Engine API (`http://127.0.0.1:8001`, FastAPI)

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/health` | ✅ Implemented | Health check + DB connectivity |
| POST | `/generate-schedule/{section_id}` | ✅ Implemented | Solve a section's timetable with CP-SAT |

### Errors

| Status | Condition |
|---|---|
| 404 | Unknown section |
| 400 | Section has no subjects assigned |
| 400 | No faculty can teach any of the section's subjects |
| 400 | No available rooms |

Laravel surfaces those `400`s as **422** carrying the engine's own message. An unreachable engine
stays **502**.

### Example response — `POST /generate-schedule/1`

```json
{
  "status": "OPTIMAL",
  "message": null,
  "sessions": [
    {
      "subject_id": 1,
      "session_type": "lecture",
      "is_scheduled": true,
      "day_of_week": 1,
      "start_time": "10:00",
      "end_time": "12:00",
      "start_hour": 10,
      "end_hour": 12,
      "room_id": 1,
      "faculty_id": 1
    },
    {
      "subject_id": 2,
      "session_type": "laboratory",
      "is_scheduled": false,
      "reason": "No room of type 'computer_lab' exists for this laboratory session."
    }
  ]
}
```

`start_time`/`end_time` are the authoritative wall-clock form (a half-hour start such as `07:30`
survives); `start_hour`/`end_hour` are whole-hour projections kept for older readers. `status` is one
of `OPTIMAL`, `FEASIBLE`, `PARTIAL`, `INFEASIBLE`, `ERROR`.

---

## Integration Flow

1. **Frontend** calls the Laravel API with a Bearer token
2. **Laravel** handles authentication, business logic, and CRUD operations
3. **Laravel** calls the FastAPI AI engine for schedule generation
4. **FastAPI** queries the database directly and runs the CP-SAT solver
5. **FastAPI** returns placements (or per-session reasons) to Laravel
6. **Laravel** persists the schedule and sessions — it is the only writer
7. **Frontend** displays the result

---

## Notes

- All endpoints require a Bearer token except `POST /api/login`
- All endpoints are admin-only — faculty are records, not users; non-admin logins are rejected
- Schedule generation uses the Google OR-Tools CP-SAT solver, budgeted at 15 seconds per section
- Conflict detection runs in real time on manual session edits, against other sections' `draft`,
  `approved` and `published` sessions in the **same academic year and semester**
- The publish conflict gate prevents cross-section double-booking before a timetable goes live
- Generation is **per-section, serialized per term, and draft-aware**: runs for one academic term are
  serialized by a cache lock (`409` when contended), a new draft treats every other section's
  `draft`/`approved`/`published` sessions in that term as live bookings, and a plan that would clash
  is refused with `422` before anything is written

**Last Updated:** October 10, 2026 — synchronized with the cross-section conflict protection
(term-scoped generation lock, pre-write conflict gate, draft-aware manual-edit checks), the
50-route API, the corrected engine error mapping (`4xx` → 422), and the login failure codes
(`401`/`403`/`422`).
