# API Reference

## Laravel API (`http://127.0.0.1:8000/api`)

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/api/faculties` | ✅ Implemented | List all faculty, with nested `user`, `subjects`, `availabilities` |
| GET | `/api/faculties/{id}` | ✅ Implemented | Get one faculty record, same nested data |
| GET | `/api/subjects` | ✅ Implemented | List all subjects, with nested `faculties` who teach each one |
| GET | `/api/subjects/{id}` | ✅ Implemented | Get one subject record, same nested data |
| POST | `/api/login` | Planned, not implemented | — |
| POST | `/api/faculties` | Planned, not implemented | — |
| PUT | `/api/faculties/{id}` | Planned, not implemented | — |
| DELETE | `/api/faculties/{id}` | Planned, not implemented | — |
| POST | `/api/subjects` | Planned, not implemented | — |
| PUT/DELETE | `/api/subjects/{id}` | Planned, not implemented | — |

## AI Engine API (`http://127.0.0.1:8001`, FastAPI)

| Method | Endpoint | Status | Description |
|---|---|---|---|
| GET | `/health` | ✅ Implemented | Confirms the service is running and can connect to Postgres. Returns `{"status": "ok", "database": "connected"}` |
| POST | `/generate-schedule/{section_id}` | ✅ Implemented | Queries the given section's subjects, eligible faculty (with qualifications + availability), and available rooms directly from Postgres, then runs the CP-SAT solver. Returns a schedule (`status: OPTIMAL`/`FEASIBLE` + `sessions[]`) or an explanation of infeasibility (`status: INFEASIBLE` + `message`) |

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

## Notes

Laravel and the AI Engine are currently **separate services that do not call each other** — both connect independently to the same PostgreSQL database. A user/frontend currently must call the AI Engine directly; Laravel does not yet proxy or trigger this call. This is a known integration gap, tracked in `Implementation_Status.md`.