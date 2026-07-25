"""
Reusable scheduling solver — same CSP logic validated in prototype/scheduler_prototype.py,
but now takes REAL data (queried from PostgreSQL) instead of hardcoded fake data.

This module has no FastAPI/HTTP code in it on purpose — it's a pure function that
takes structured data in and returns a structured result out. That makes it testable
on its own and reusable from a script, a test, or the API layer.
"""

from ortools.sat.python import cp_model


def generate_schedule(section: dict, subjects: list, faculty: list, rooms: list) -> dict:
    """
    Args:
        section: {"id": int, "name": str, "preferred_days": [1,2,3,4,5],
                  "preferred_start_hour": int, "preferred_end_hour": int}
        subjects: [{"id": int, "code": str, "lecture_hours": int, "lab_hours": int,
                     "lab_room_type": str|None}]
        faculty: [{"id": int, "name": str, "can_teach_subject_ids": [int, ...],
                    "available_days": [1,2,3,4,5]}]
        rooms: [{"id": int, "name": str, "type": str, "capacity": int}]

    Returns:
        {"status": "OPTIMAL" | "FEASIBLE" | "INFEASIBLE" | "ERROR",
         "message": str | None,
         "sessions": [{"subject_id", "session_type", "day_of_week",
                        "start_hour", "end_hour", "room_id", "faculty_id"}, ...]}
    """
    DAYS = section["preferred_days"]  # e.g. [1,2,3,4,5], 1=Mon
    START_HOUR = section["preferred_start_hour"]
    END_HOUR = section["preferred_end_hour"]

    # Break each subject into sessions (lecture / lab), same as the prototype.
    sessions_meta = []
    for subj in subjects:
        if subj["lecture_hours"] > 0:
            sessions_meta.append({
                "subject_id": subj["id"],
                "session_type": "lecture",
                "duration": subj["lecture_hours"],
                "room_type": "lecture",
            })
        if subj["lab_hours"] > 0:
            sessions_meta.append({
                "subject_id": subj["id"],
                "session_type": "laboratory",
                "duration": subj["lab_hours"],
                "room_type": subj["lab_room_type"],
            })

    if not sessions_meta:
        return {"status": "ERROR", "message": "Section has no subjects with hours defined.", "sessions": []}

    model = cp_model.CpModel()
    session_vars = {}

    for idx, sess in enumerate(sessions_meta):
        duration = sess["duration"]
        room_type = sess["room_type"]
        subject_id = sess["subject_id"]

        eligible_rooms = [r["id"] for r in rooms if r["type"] == room_type]
        if not eligible_rooms:
            return {
                "status": "INFEASIBLE",
                "message": f"No room of type '{room_type}' exists for subject_id {subject_id} "
                           f"({sess['session_type']}). Add a room of this type or reassign the subject.",
                "sessions": [],
            }

        eligible_faculty = [f["id"] for f in faculty if subject_id in f["can_teach_subject_ids"]]
        if not eligible_faculty:
            return {
                "status": "INFEASIBLE",
                "message": f"No faculty is qualified to teach subject_id {subject_id}. "
                           f"Assign a qualified faculty member first.",
                "sessions": [],
            }

        day_var = model.NewIntVarFromDomain(
            cp_model.Domain.FromValues(DAYS), f"s{idx}_day"
        )
        max_start = END_HOUR - duration
        if max_start < START_HOUR:
            return {
                "status": "INFEASIBLE",
                "message": f"Subject_id {subject_id} needs {duration}h but the section's "
                           f"preferred window ({START_HOUR}:00-{END_HOUR}:00) is too short.",
                "sessions": [],
            }
        start_var = model.NewIntVar(START_HOUR, max_start, f"s{idx}_start")
        room_var = model.NewIntVarFromDomain(
            cp_model.Domain.FromValues([rooms.index(r) for r in rooms if r["id"] in eligible_rooms]),
            f"s{idx}_room",
        )
        faculty_var = model.NewIntVarFromDomain(
            cp_model.Domain.FromValues([faculty.index(f) for f in faculty if f["id"] in eligible_faculty]),
            f"s{idx}_faculty",
        )

        session_vars[idx] = {
            "day": day_var, "start": start_var, "room": room_var, "faculty": faculty_var,
            "duration": duration, "meta": sess,
        }

    # Faculty availability: restrict day_var to each faculty member's available days
    for idx, v in session_vars.items():
        for f_idx, f in enumerate(faculty):
            is_this_faculty = model.NewBoolVar(f"s{idx}_is_f{f_idx}")
            model.Add(v["faculty"] == f_idx).OnlyEnforceIf(is_this_faculty)
            model.Add(v["faculty"] != f_idx).OnlyEnforceIf(is_this_faculty.Not())
            if set(f["available_days"]) != set(DAYS):
                model.AddAllowedAssignments(
                    [v["day"]], [[d] for d in f["available_days"]]
                ).OnlyEnforceIf(is_this_faculty)

    # No double-booking (faculty / room), no section self-overlap
    def overlap_bool(v1, v2, tag):
        end1 = v1["start"] + v1["duration"]
        end2 = v2["start"] + v2["duration"]
        b = model.NewBoolVar(tag)
        model.Add(v1["start"] < end2).OnlyEnforceIf(b)
        model.Add(v2["start"] < end1).OnlyEnforceIf(b)
        model.Add(v1["start"] >= end2).OnlyEnforceIf(b.Not())
        return b

    idxs = list(session_vars.keys())
    for i in range(len(idxs)):
        for j in range(i + 1, len(idxs)):
            v1, v2 = session_vars[idxs[i]], session_vars[idxs[j]]

            same_day = model.NewBoolVar(f"same_day_{i}_{j}")
            model.Add(v1["day"] == v2["day"]).OnlyEnforceIf(same_day)
            model.Add(v1["day"] != v2["day"]).OnlyEnforceIf(same_day.Not())

            same_faculty = model.NewBoolVar(f"same_fac_{i}_{j}")
            model.Add(v1["faculty"] == v2["faculty"]).OnlyEnforceIf(same_faculty)
            model.Add(v1["faculty"] != v2["faculty"]).OnlyEnforceIf(same_faculty.Not())

            same_room = model.NewBoolVar(f"same_room_{i}_{j}")
            model.Add(v1["room"] == v2["room"]).OnlyEnforceIf(same_room)
            model.Add(v1["room"] != v2["room"]).OnlyEnforceIf(same_room.Not())

            overlap = overlap_bool(v1, v2, f"overlap_{i}_{j}")

            model.AddBoolOr([same_day.Not(), same_faculty.Not(), overlap.Not()])
            model.AddBoolOr([same_day.Not(), same_room.Not(), overlap.Not()])
            model.AddBoolOr([same_day.Not(), overlap.Not()])  # section can't be in 2 places at once

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 15.0
    status = solver.Solve(model)

    if status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        return {
            "status": "INFEASIBLE",
            "message": "No valid schedule could be generated with the current faculty, "
                       "room, and time constraints. Try widening faculty availability, "
                       "adding rooms, or expanding the section's preferred time window.",
            "sessions": [],
        }

    result_sessions = []
    for idx, v in session_vars.items():
        day = solver.Value(v["day"])
        start = solver.Value(v["start"])
        end = start + v["duration"]
        room_id = rooms[solver.Value(v["room"])]["id"]
        faculty_id = faculty[solver.Value(v["faculty"])]["id"]
        result_sessions.append({
            "subject_id": v["meta"]["subject_id"],
            "session_type": v["meta"]["session_type"],
            "day_of_week": day,
            "start_hour": start,
            "end_hour": end,
            "room_id": room_id,
            "faculty_id": faculty_id,
        })

    return {
        "status": solver.StatusName(status),
        "message": None,
        "sessions": result_sessions,
    }