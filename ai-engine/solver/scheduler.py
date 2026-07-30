"""
Reusable scheduling solver — same CSP logic validated in prototype/scheduler_prototype.py,
now extended to do BEST-EFFORT scheduling: instead of failing the whole run when a
schedule can't be found, it maximizes how many sessions it CAN place, and reports a
reason for any session it couldn't.

This module has no FastAPI/HTTP code in it on purpose — it's a pure function that
takes structured data in and returns a structured result out.
"""

from ortools.sat.python import cp_model


def generate_schedule(section: dict, subjects: list, faculty: list, rooms: list,
                       existing_sessions: list | None = None) -> dict:
    """
    Returns:
        {"status": "OPTIMAL" | "FEASIBLE" | "PARTIAL" | "INFEASIBLE" | "ERROR",
         "message": str | None,
         "sessions": [
             {"subject_id", "session_type", "is_scheduled": True,
              "day_of_week", "start_hour", "end_hour", "room_id", "faculty_id"},
             {"subject_id", "session_type", "is_scheduled": False, "reason": str},
             ...
         ]}
    """
    existing_sessions = existing_sessions or []
    DAYS = section["preferred_days"]
    START_HOUR = section["preferred_start_hour"]
    END_HOUR = section["preferred_end_hour"]

    # Break each subject into sessions (lecture / lab)
    sessions_meta = []
    for subj in subjects:
        if subj["lecture_hours"] > 0:
            sessions_meta.append({
                "subject_id": subj["id"], "session_type": "lecture",
                "duration": subj["lecture_hours"], "room_type": "lecture",
            })
        if subj["lab_hours"] > 0:
            sessions_meta.append({
                "subject_id": subj["id"], "session_type": "laboratory",
                "duration": subj["lab_hours"], "room_type": subj["lab_room_type"],
            })

    if not sessions_meta:
        return {"status": "ERROR", "message": "Section has no subjects with hours defined.", "sessions": []}

    model = cp_model.CpModel()
    session_vars = {}       # idx -> vars, only for structurally-possible sessions
    unschedulable = {}      # idx -> reason, for sessions that can NEVER be placed

    # --- First pass: figure out which sessions are even structurally possible ---
    for idx, sess in enumerate(sessions_meta):
        duration = sess["duration"]
        room_type = sess["room_type"]
        subject_id = sess["subject_id"]

        eligible_rooms = [r["id"] for r in rooms if r["type"] == room_type]
        eligible_faculty = [f["id"] for f in faculty if subject_id in f["can_teach_subject_ids"]]
        max_start = END_HOUR - duration

        if not eligible_rooms:
            unschedulable[idx] = (f"No room of type '{room_type}' exists for this "
                                   f"{sess['session_type']} session.")
        elif not eligible_faculty:
            unschedulable[idx] = "No qualified faculty available to teach this subject."
        elif max_start < START_HOUR:
            unschedulable[idx] = (f"Session needs {duration}h but the section's preferred "
                                   f"window ({START_HOUR}:00-{END_HOUR}:00) is too short.")
        else:
            day_var = model.NewIntVarFromDomain(cp_model.Domain.FromValues(DAYS), f"s{idx}_day")
            start_var = model.NewIntVar(START_HOUR, max_start, f"s{idx}_start")
            room_var = model.NewIntVarFromDomain(
                cp_model.Domain.FromValues([rooms.index(r) for r in rooms if r["id"] in eligible_rooms]),
                f"s{idx}_room",
            )
            faculty_var = model.NewIntVarFromDomain(
                cp_model.Domain.FromValues([faculty.index(f) for f in faculty if f["id"] in eligible_faculty]),
                f"s{idx}_faculty",
            )
            is_scheduled = model.NewBoolVar(f"s{idx}_scheduled")

            session_vars[idx] = {
                "day": day_var, "start": start_var, "room": room_var, "faculty": faculty_var,
                "is_scheduled": is_scheduled, "duration": duration, "meta": sess,
            }

    # --- Faculty availability: restrict day_var when a session IS scheduled with that faculty ---
    for idx, v in session_vars.items():
        for f_idx, f in enumerate(faculty):
            is_this_faculty = model.NewBoolVar(f"s{idx}_is_f{f_idx}")
            model.Add(v["faculty"] == f_idx).OnlyEnforceIf(is_this_faculty)
            model.Add(v["faculty"] != f_idx).OnlyEnforceIf(is_this_faculty.Not())
            if set(f["available_days"]) != set(DAYS):
                model.AddAllowedAssignments(
                    [v["day"]], [[d] for d in f["available_days"]]
                ).OnlyEnforceIf([is_this_faculty, v["is_scheduled"]])

    # --- Teaching load: only counts hours from sessions that ARE scheduled ---
    for f_idx, f in enumerate(faculty):
        existing_load = f.get("existing_load_hours", 0)
        max_load = f.get("max_teaching_load", 24)
        remaining_capacity = max(0, int(round(max_load - existing_load)))

        weighted_terms = []
        for idx, v in session_vars.items():
            is_this_faculty = model.NewBoolVar(f"load_s{idx}_f{f_idx}")
            model.Add(v["faculty"] == f_idx).OnlyEnforceIf(is_this_faculty)
            model.Add(v["faculty"] != f_idx).OnlyEnforceIf(is_this_faculty.Not())

            counts = model.NewBoolVar(f"load_counts_s{idx}_f{f_idx}")
            model.AddMultiplicationEquality(counts, [is_this_faculty, v["is_scheduled"]])
            weighted_terms.append(counts * v["duration"])

        if weighted_terms:
            model.Add(sum(weighted_terms) <= remaining_capacity)

    # --- No double-booking (faculty / room), no section self-overlap — only when BOTH scheduled ---
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
            both_scheduled = [v1["is_scheduled"], v2["is_scheduled"]]

            model.AddBoolOr([same_day.Not(), same_faculty.Not(), overlap.Not()] + [b.Not() for b in both_scheduled])
            model.AddBoolOr([same_day.Not(), same_room.Not(), overlap.Not()] + [b.Not() for b in both_scheduled])
            model.AddBoolOr([same_day.Not(), overlap.Not()] + [b.Not() for b in both_scheduled])

    # --- Cross-schedule conflicts: only when THIS session is scheduled ---
    for ext in existing_sessions:
        ext_day = ext["day_of_week"]
        ext_start = ext["start_hour"]
        ext_end = ext["end_hour"]
        ext_faculty_id = ext.get("faculty_id")
        ext_room_id = ext.get("room_id")

        for idx, v in session_vars.items():
            same_day = model.NewBoolVar(f"ext_same_day_{idx}_{id(ext)}")
            model.Add(v["day"] == ext_day).OnlyEnforceIf(same_day)
            model.Add(v["day"] != ext_day).OnlyEnforceIf(same_day.Not())

            end_var = v["start"] + v["duration"]
            overlap = model.NewBoolVar(f"ext_overlap_{idx}_{id(ext)}")
            model.Add(v["start"] < ext_end).OnlyEnforceIf(overlap)
            model.Add(ext_start < end_var).OnlyEnforceIf(overlap)
            model.Add(v["start"] >= ext_end).OnlyEnforceIf(overlap.Not())

            if ext_faculty_id is not None:
                faculty_ids_in_model = [f["id"] for f in faculty]
                if ext_faculty_id in faculty_ids_in_model:
                    f_idx = faculty_ids_in_model.index(ext_faculty_id)
                    same_faculty = model.NewBoolVar(f"ext_same_fac_{idx}_{id(ext)}")
                    model.Add(v["faculty"] == f_idx).OnlyEnforceIf(same_faculty)
                    model.Add(v["faculty"] != f_idx).OnlyEnforceIf(same_faculty.Not())
                    model.AddBoolOr([same_day.Not(), same_faculty.Not(), overlap.Not(), v["is_scheduled"].Not()])

            if ext_room_id is not None:
                room_ids_in_model = [r["id"] for r in rooms]
                if ext_room_id in room_ids_in_model:
                    r_idx = room_ids_in_model.index(ext_room_id)
                    same_room = model.NewBoolVar(f"ext_same_room_{idx}_{id(ext)}")
                    model.Add(v["room"] == r_idx).OnlyEnforceIf(same_room)
                    model.Add(v["room"] != r_idx).OnlyEnforceIf(same_room.Not())
                    model.AddBoolOr([same_day.Not(), same_room.Not(), overlap.Not(), v["is_scheduled"].Not()])

    # --- Objective: maximize number of sessions successfully scheduled ---
    if session_vars:
        model.Maximize(sum(v["is_scheduled"] for v in session_vars.values()))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 15.0
    status = solver.Solve(model)

    result_sessions = []

    # Sessions that were never even possible (structural reasons, e.g. no room/faculty)
    for idx, reason in unschedulable.items():
        result_sessions.append({
            "subject_id": sessions_meta[idx]["subject_id"],
            "session_type": sessions_meta[idx]["session_type"],
            "is_scheduled": False,
            "reason": reason,
        })

    scheduled_count = 0

    if status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        for idx, v in session_vars.items():
            if solver.Value(v["is_scheduled"]):
                scheduled_count += 1
                day = solver.Value(v["day"])
                start = solver.Value(v["start"])
                end = start + v["duration"]
                room_id = rooms[solver.Value(v["room"])]["id"]
                faculty_id = faculty[solver.Value(v["faculty"])]["id"]
                result_sessions.append({
                    "subject_id": v["meta"]["subject_id"],
                    "session_type": v["meta"]["session_type"],
                    "is_scheduled": True,
                    "day_of_week": day,
                    "start_hour": start,
                    "end_hour": end,
                    "room_id": room_id,
                    "faculty_id": faculty_id,
                })
            else:
                result_sessions.append({
                    "subject_id": v["meta"]["subject_id"],
                    "session_type": v["meta"]["session_type"],
                    "is_scheduled": False,
                    "reason": "Could not fit given room, faculty, or time conflicts with "
                              "other sessions. Try widening availability or adding resources.",
                })
    else:
        # Solver couldn't even run/find anything — treat every structurally-possible
        # session as unscheduled with a generic reason.
        for idx, v in session_vars.items():
            result_sessions.append({
                "subject_id": v["meta"]["subject_id"],
                "session_type": v["meta"]["session_type"],
                "is_scheduled": False,
                "reason": "Solver could not find any valid placement within the time limit.",
            })

    total = len(sessions_meta)

    if scheduled_count == total:
        overall_status = solver.StatusName(status) if status in (cp_model.OPTIMAL, cp_model.FEASIBLE) else "OPTIMAL"
        message = None
    elif scheduled_count > 0:
        overall_status = "PARTIAL"
        message = f"{scheduled_count} of {total} sessions scheduled. See individual reasons for the rest."
    else:
        overall_status = "INFEASIBLE"
        message = "No sessions could be scheduled. See individual reasons."

    return {
        "status": overall_status,
        "message": message,
        "sessions": result_sessions,
    }