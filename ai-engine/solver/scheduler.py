"""
Reusable scheduling solver — same CSP logic validated in prototype/scheduler_prototype.py,
now extended to do BEST-EFFORT scheduling: instead of failing the whole run when a
schedule can't be found, it maximizes how many sessions it CAN place, and reports a
reason for any session it couldn't.

This module has no FastAPI/HTTP code in it on purpose — it's a pure function that
takes structured data in and returns a structured result out.

TIME UNIT
---------
The solver reasons in **minutes since midnight** and only ever starts a session on
a `SLOT_MINUTES` (30-minute) boundary. This is what makes a half-hour offset
possible at all: an earlier revision worked in whole hours, so a section window of
07:30-20:30 was rounded down to 07:00-20:00 before the solver saw it, and 7:30 AM
or 8:30 PM could never be produced. See solver/time_slots.py for the conversion
helpers, which are the single place minutes are handled.

THE LUNCH BREAK
---------------
When a break is supplied (see `_read_break`), it is a **hard constraint**: no
session may overlap it. Because every session has a fixed duration, the starts
that would collide with the break form one contiguous run, so the break is applied
as a restriction on each session's start domain rather than as extra booleans.
"""

from ortools.sat.python import cp_model

from time_slots import (
    SLOT_MINUTES,
    allowed_starts,
    exclude_break,
    hhmm_to_minutes,
    hours_to_minutes,
    minutes_to_hhmm,
)


def _as_minutes(value, is_hours: bool):
    """
    Normalise one time value to minutes.

    The unit has to be stated by the caller rather than sniffed from the value:
    `450` is a legal minute count and `7` is a legal hour count, and an int alone
    cannot tell them apart. Getting this wrong double-converts a value that was
    already in minutes (450 became 27000), which is exactly the class of silent
    time bug this refactor exists to remove.

    Accepts an int/float in the stated unit, or an `'HH:MM'` string.
    """
    if value is None:
        return None
    if is_hours:
        return hours_to_minutes(value)
    if isinstance(value, str):
        return hhmm_to_minutes(value)
    return int(value)


def _read_window(section: dict):
    """Section's preferred window in minutes, tolerating the older hour-based keys."""
    start = _as_minutes(section.get("preferred_start_minutes"), False)
    if start is None:
        start = _as_minutes(section.get("preferred_start_hour", 0), True)
    end = _as_minutes(section.get("preferred_end_minutes"), False)
    if end is None:
        end = _as_minutes(section.get("preferred_end_hour", 24), True)

    return start, end


def _read_break(section: dict):
    """
    The break as `(start_minutes, end_minutes)` or `None`.

    Accepts either a nested `lunch` mapping or flat `lunch_*` keys, so the API
    layer can pass through whatever it read from the settings table. A missing or
    inverted break is treated as "no break" rather than an error, so a bad setting
    can never make every section unschedulable.
    """
    lunch = section.get("lunch")
    if isinstance(lunch, dict):
        if not lunch.get("enabled", True):
            return None
        start = _as_minutes(lunch.get("start_minutes"), False)
        if start is None:
            start = _as_minutes(lunch.get("start"), False)
        end = _as_minutes(lunch.get("end_minutes"), False)
        if end is None:
            end = _as_minutes(lunch.get("end"), False)
    else:
        if not section.get("lunch_enabled", False):
            return None
        start = _as_minutes(section.get("lunch_start_minutes"), False)
        if start is None:
            start = _as_minutes(section.get("lunch_start"), False)
        end = _as_minutes(section.get("lunch_end_minutes"), False)
        if end is None:
            end = _as_minutes(section.get("lunch_end"), False)

    if start is None or end is None:
        return None

    if end <= start:
        return None

    return start, end


def generate_schedule(section: dict, subjects: list, faculty: list, rooms: list,
                       existing_sessions: list | None = None) -> dict:
    """
    Returns:
        {"status": "OPTIMAL" | "FEASIBLE" | "PARTIAL" | "INFEASIBLE" | "ERROR",
         "message": str | None,
         "sessions": [
             {"subject_id", "session_type", "is_scheduled": True,
              "day_of_week", "start_time", "end_time", "room_id", "faculty_id"},
             {"subject_id", "session_type", "is_scheduled": False, "reason": str},
             ...
         ]}
    """
    existing_sessions = existing_sessions or []
    DAYS = section["preferred_days"]
    START_MIN, END_MIN = _read_window(section)
    BREAK = _read_break(section)

    # Break each subject into sessions (lecture / lab). Durations are minutes.
    sessions_meta = []
    for subj in subjects:
        if subj["lecture_hours"] > 0:
            sessions_meta.append({
                "subject_id": subj["id"], "session_type": "lecture",
                "duration": hours_to_minutes(subj["lecture_hours"]), "room_type": "lecture",
            })
        if subj["lab_hours"] > 0:
            sessions_meta.append({
                "subject_id": subj["id"], "session_type": "laboratory",
                "duration": hours_to_minutes(subj["lab_hours"]),
                "room_type": subj["lab_room_type"],
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

        # A room has to match the session's type AND seat the whole section.
        # Keep the two failure causes apart so the reason we report back to the
        # admin is actionable: claiming the room type does not exist when it
        # does, and is merely too small, sends them looking for a room they
        # already have.
        student_count = section.get("student_count", 30)
        rooms_of_type = [r for r in rooms if r["type"] == room_type]
        eligible_rooms = [r["id"] for r in rooms_of_type if r["capacity"] >= student_count]
        eligible_faculty = [f["id"] for f in faculty if subject_id in f["can_teach_subject_ids"]]
        max_start = END_MIN - duration

        # Every legal start: inside the section window, on the 30-minute grid,
        # and clear of the lunch break. The break is applied here, to the start
        # domain, so it holds as a hard constraint without extra booleans.
        starts = allowed_starts(START_MIN, max_start, SLOT_MINUTES)
        if BREAK:
            starts = exclude_break(starts, duration, BREAK[0], BREAK[1])

        if not rooms_of_type:
            unschedulable[idx] = (f"No room of type '{room_type}' exists for this "
                                   f"{sess['session_type']} session.")
        elif not eligible_rooms:
            largest = max(r["capacity"] for r in rooms_of_type)
            unschedulable[idx] = (f"No '{room_type}' room is large enough: the biggest "
                                   f"seats {largest}, but this section has "
                                   f"{student_count} students.")
        elif not eligible_faculty:
            unschedulable[idx] = "No qualified faculty available to teach this subject."
        elif max_start < START_MIN:
            unschedulable[idx] = (f"Session needs {duration // 60}h but the section's preferred "
                                   f"window ({minutes_to_hhmm(START_MIN)}-{minutes_to_hhmm(END_MIN)}) "
                                   f"is too short.")
        elif not starts:
            unschedulable[idx] = (f"Session needs {duration // 60}h and the section's preferred "
                                   f"window ({minutes_to_hhmm(START_MIN)}-{minutes_to_hhmm(END_MIN)}) "
                                   f"leaves no room outside the "
                                   f"{minutes_to_hhmm(BREAK[0])}-{minutes_to_hhmm(BREAK[1])} break.")
        else:
            day_var = model.NewIntVarFromDomain(cp_model.Domain.FromValues(DAYS), f"s{idx}_day")
            start_var = model.NewIntVarFromDomain(
                cp_model.Domain.FromValues(starts), f"s{idx}_start"
            )
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
                if f["available_days"]:
                    model.AddAllowedAssignments(
                        [v["day"]], [[d] for d in f["available_days"]]
                    ).OnlyEnforceIf([is_this_faculty, v["is_scheduled"]])
                else:
                    # Declared availability with no usable window left: this
                    # faculty cannot host the session on any day.
                    model.Add(v["is_scheduled"] == 0).OnlyEnforceIf(is_this_faculty)

            # Declared hours are a hard boundary, exactly as the API's session
            # validator enforces them: the (day, start) pair must sit inside one
            # declared window, so a window ends the session rather than merely
            # banning the day.
            if f.get("available_windows"):
                allowed_slots = []
                for w in f["available_windows"]:
                    if w["day_of_week"] not in DAYS:
                        continue
                    w_start = _as_minutes(w.get("start_minutes"), False)
                    if w_start is None:
                        w_start = _as_minutes(w.get("start_hour"), True)
                    w_end = _as_minutes(w.get("end_minutes"), False)
                    if w_end is None:
                        w_end = _as_minutes(w.get("end_hour"), True)
                    for start in allowed_starts(w_start, w_end - v["duration"], SLOT_MINUTES):
                        # A declared availability window is where the session may
                        # sit; the break is still a hard constraint inside it, so
                        # the session is never pushed into the lunch hour.
                        if BREAK and not exclude_break([start], v["duration"], BREAK[0], BREAK[1]):
                            continue
                        allowed_slots.append([w["day_of_week"], start])
                if allowed_slots:
                    model.AddAllowedAssignments(
                        [v["day"], v["start"]], allowed_slots
                    ).OnlyEnforceIf([is_this_faculty, v["is_scheduled"]])
                else:
                    model.Add(v["is_scheduled"] == 0).OnlyEnforceIf(is_this_faculty)

    # --- Teaching load: only counts hours from sessions that ARE scheduled ---
    for f_idx, f in enumerate(faculty):
        existing_load = f.get("existing_load_hours", 0)
        max_load = f.get("max_teaching_load", 24)
        # `existing_load_hours` is hours, so this conversion is correct; the
        # previous code compared it against hour-durations and is now minutes.
        remaining_capacity = max(0, hours_to_minutes(max_load - existing_load))

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
        ext_start = _as_minutes(ext.get("start_minutes"), False)
        if ext_start is None:
            ext_start = _as_minutes(ext.get("start_hour"), True)
        ext_end = _as_minutes(ext.get("end_minutes"), False)
        if ext_end is None:
            ext_end = _as_minutes(ext.get("end_hour"), True)

        # An existing session with no usable time cannot be reasoned about. Skip
        # it rather than building a constraint with `None`, which CP-SAT rejects
        # with a TypeError and which would fail the whole generation request over
        # one malformed row.
        if ext_start is None or ext_end is None:
            continue
        ext_faculty_id = ext.get("faculty_id")
        ext_room_id = ext.get("room_id")

        for idx, v in session_vars.items():
            same_day = model.NewBoolVar(f"ext_same_day_{idx}_{id(ext)}")
            model.Add(v["day"] == ext_day).OnlyEnforceIf(same_day)
            model.Add(v["day"] != ext_day).OnlyEnforceIf(same_day.Not())

            end_var = v["start"] + v["duration"]

            # `overlap` is only true when the two clock intervals really do
            # overlap. When they don't, the candidate sits either fully before
            # or fully after the existing session — expressing only the "after"
            # half (start >= ext_end) made the whole model infeasible whenever an
            # existing session ended after the section's window, even on a free
            # day, because there was no way to satisfy it inside the window.
            overlap = model.NewBoolVar(f"ext_overlap_{idx}_{id(ext)}")
            model.Add(v["start"] < ext_end).OnlyEnforceIf(overlap)
            model.Add(ext_start < end_var).OnlyEnforceIf(overlap)

            ends_before = model.NewBoolVar(f"ext_ends_before_{idx}_{id(ext)}")
            starts_after = model.NewBoolVar(f"ext_starts_after_{idx}_{id(ext)}")
            model.Add(end_var <= ext_start).OnlyEnforceIf(ends_before)
            model.Add(v["start"] >= ext_end).OnlyEnforceIf(starts_after)
            model.AddBoolOr([overlap, ends_before, starts_after])

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
                    # The authoritative form is wall-clock HH:MM, so Laravel can
                    # store it verbatim and a half-hour start survives the trip.
                    "start_time": minutes_to_hhmm(start),
                    "end_time": minutes_to_hhmm(end),
                    # start_hour/end_hour stay for any older reader; they are
                    # whole-hour projections and lose the half hour by design.
                    "start_hour": start // 60,
                    "end_hour": -(-end // 60),
                    "room_id": room_id,
                    "faculty_id": faculty_id,
                })
            else:
                result_sessions.append({
                    "subject_id": v["meta"]["subject_id"],
                    "session_type": v["meta"]["session_type"],
                    "is_scheduled": False,
                    "reason": "No free slot: every day and time this session could use is "
                              "already taken by the section's other classes, the faculty's "
                              "availability, the midday break, or a room already in use. "
                              "Widening the section window or faculty availability, or "
                              "adding a room of the right type, may make room for it.",
                })
    else:
        # Solver couldn't even run/find anything — treat every structurally-possible
        # session as unscheduled with a generic reason.
        for idx, v in session_vars.items():
            result_sessions.append({
                "subject_id": v["meta"]["subject_id"],
                "session_type": v["meta"]["session_type"],
                "is_scheduled": False,
                "reason": "The scheduling engine ran out of time before it could place this "
                          "session. Try generating again; if it persists, widen the section "
                          "window or faculty availability.",
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