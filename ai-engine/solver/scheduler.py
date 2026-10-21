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

OBJECTIVES
----------
Scheduling is **lexicographic**, so the primary objective is never traded away:

  1. maximize the number of sessions scheduled;
  2. among timetables that schedule that same maximum AND stay inside the total
     daily span the stage-1 solution already used, minimize the number of
     meeting blocks belonging to the SAME subject that share a day, so a
     subject's multiple weekly meetings (e.g. a lecture and its laboratory)
     are spread over different days (the same-subject-day preference, see
     `_same_subject_day_terms`);
  3. within those, minimize the number of transitions between two DIFFERENT
     subjects that leave the students less than `GAP_PREFERENCE_MINUTES` to
     move (the transition-gap preference).

Stage 2 is reached only by freezing the stage-1 count as a hard constraint (see
`_gap_penalty_terms`), so neither preference can ever cost a session. The
stage-1 total daily span is frozen the same way (see `_span_minutes` and
`_daily_span_vars`), so neither preference can *spread* a section's days to
manufacture a tidier timetable: they may only rearrange classes inside the
space stage 1 already used.

Both soft preferences are optimized in a single solve, lexicographically, by
weighting the same-subject-day count up by more than the largest possible
number of gap terms. Minimizing `W * same_subject_days + gaps` is then exactly
equivalent to minimizing the same-subject-day count first and the gap count
second, so distribution dominates while the gap preference still refines the
remaining ties — and the guard needs no extra solve.

A subject's own lab<->lecture pair is exempt from the transition-gap
preference — that continuity is intentional — and it is exactly what the
same-subject-day preference tries to avoid. When the two disagree the
same-subject-day preference wins: a subject's meetings are spread over
different days whenever the frozen span allows it, even at the cost of a
less tidy different-subject transition.
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


# Minimum transition gap the secondary objective prefers between two
# consecutive classes of DIFFERENT subjects (minutes). This is a SOFT
# preference, never a hard rule: see `_gap_penalty_terms` and the lexicographic
# second stage at the end of `generate_schedule`.
GAP_PREFERENCE_MINUTES = 30


def _gap_penalty_terms(model, session_vars, sessions_meta, threshold):
    """
    One boolean term per undesirable transition between two DIFFERENT subjects
    that leaves the students less than `threshold` minutes to move.

    Soft by construction. The caller adds these to a *second* objective that is
    only optimized after the number of scheduled sessions has been frozen, so
    minimizing them can never cost a session.

    Two sessions of the same subject are that subject's lab<->lecture block: the
    back-to-back continuity is intentional, not a missing break, so those pairs
    are exempt.

    The section's no-overlap rule is already a hard constraint, so two sessions
    sharing a day are ordered. Only the ordering in which the second one starts
    at or after the first ends can hold, so each undesirable transition is
    counted exactly once. A gap of `threshold` minutes or more is not counted.
    """
    terms = []
    idxs = list(session_vars.keys())
    for a in range(len(idxs)):
        for b in range(a + 1, len(idxs)):
            i, j = idxs[a], idxs[b]
            if sessions_meta[i]["subject_id"] == sessions_meta[j]["subject_id"]:
                continue  # one subject's lab<->lecture block: intentional

            v1, v2 = session_vars[i], session_vars[j]
            tag = "{}v{}".format(i, j)

            same_day = model.NewBoolVar("gapsd_" + tag)
            model.Add(v1["day"] == v2["day"]).OnlyEnforceIf(same_day)
            model.Add(v1["day"] != v2["day"]).OnlyEnforceIf(same_day.Not())

            for order, (first, second) in enumerate(((v1, v2), (v2, v1))):
                end_first = first["start"] + first["duration"]

                starts_after = model.NewBoolVar("gapafter_" + tag + "_" + str(order))
                model.Add(second["start"] >= end_first).OnlyEnforceIf(starts_after)
                model.Add(second["start"] < end_first).OnlyEnforceIf(starts_after.Not())

                starts_soon = model.NewBoolVar("gapclose_" + tag + "_" + str(order))
                model.Add(
                    second["start"] < end_first + threshold
                ).OnlyEnforceIf(starts_soon)
                model.Add(
                    second["start"] >= end_first + threshold
                ).OnlyEnforceIf(starts_soon.Not())

                penalty = model.NewBoolVar("gappen_" + tag + "_" + str(order))
                model.AddMultiplicationEquality(
                    penalty,
                    [starts_after, starts_soon, same_day,
                     first["is_scheduled"], second["is_scheduled"]],
                )
                terms.append(penalty)

    return terms


def _same_subject_day_terms(model, session_vars, sessions_meta):
    """
    One boolean term per pair of sessions belonging to the SAME subject that
    share a day.

    Soft by construction, exactly like `_gap_penalty_terms`: the caller only
    minimizes these after the number of scheduled sessions and the total daily
    span have been frozen, so the preference can never cost a session or stretch
    a day.

    A subject can have more than one weekly meeting component (typically a
    lecture and its laboratory, which need different rooms and so stay separate
    sessions with their own types). Spreading those components across different
    days is what this objective buys: minimizing the count of same-subject
    same-day pairs is the same as maximizing the count of same-subject
    different-day pairs. A pair counts only when both sessions are actually
    scheduled, so an unscheduled component is never mistaken for a placement.

    Note the deliberate contrast with `_gap_penalty_terms`, which treats a
    subject's own pair as exempt back-to-back continuity. The two preferences
    can disagree there; the caller's weighting ranks this one ABOVE the gap
    preference, so a subject's meetings are spread over different days even
    when that costs a less tidy different-subject transition. It still cannot
    cost a session or stretch a day: the count and the total daily span are
    both frozen before either preference is optimized.
    """
    terms = []
    idxs = list(session_vars.keys())
    for a in range(len(idxs)):
        for b in range(a + 1, len(idxs)):
            i, j = idxs[a], idxs[b]
            if sessions_meta[i]["subject_id"] != sessions_meta[j]["subject_id"]:
                continue  # different subjects: the gap preference owns this pair

            v1, v2 = session_vars[i], session_vars[j]
            tag = "{}v{}".format(i, j)

            same_day = model.NewBoolVar("ssday_" + tag)
            model.Add(v1["day"] == v2["day"]).OnlyEnforceIf(same_day)
            model.Add(v1["day"] != v2["day"]).OnlyEnforceIf(same_day.Not())

            penalty = model.NewBoolVar("sspen_" + tag)
            model.AddMultiplicationEquality(
                penalty,
                [same_day, v1["is_scheduled"], v2["is_scheduled"]],
            )
            terms.append(penalty)

    return terms


def _span_minutes(chosen, session_vars):
    """
    Total section-day span of a solved timetable, in minutes.

    The sum over days of (latest end - earliest start). That is the scheduled
    minutes PLUS every idle gap the timetable actually contains, so it grows by
    exactly the size of any gap the solver decides to insert — which is the
    quantity the compactness budget in `generate_schedule` needs to freeze.
    """
    by_day = {}
    for idx, st in chosen.items():
        if not st["is_scheduled"]:
            continue
        v = session_vars[idx]
        by_day.setdefault(st["day"], []).append(
            (st["start"], st["start"] + v["duration"])
        )
    return sum(max(e for _, e in items) - min(s for s, _ in items)
               for items in by_day.values())


def _daily_span_vars(model, session_vars, days):
    """
    One span variable per day, so the caller can bound how stretched a section's
    days are: `span[d]` is the day's last end minus its first start, and 0 on a
    day the section does not use. Summing them measures exactly the same
    quantity `_span_minutes` does.

    Only a SCHEDULED class stretches a day. An unscheduled session still has a
    free `day` and `start`, so counting it would let this bound describe a
    timetable other than the one actually reported.
    """
    span_vars = []
    idxs = list(session_vars.keys())
    for d in days:
        on = {}
        for idx in idxs:
            v = session_vars[idx]
            is_d = model.NewBoolVar("on_d{}_{}".format(d, idx))
            model.Add(v["day"] == d).OnlyEnforceIf(is_d)
            model.Add(v["day"] != d).OnlyEnforceIf(is_d.Not())

            on_day = model.NewBoolVar("onused_d{}_{}".format(d, idx))
            model.AddMultiplicationEquality(on_day, [is_d, v["is_scheduled"]])
            on[idx] = on_day

        used = model.NewBoolVar("dayused_{}".format(d))
        model.AddBoolOr(list(on.values())).OnlyEnforceIf(used)
        model.AddBoolOr([b.Not() for b in on.values()]).OnlyEnforceIf(used.Not())

        start_min = model.NewIntVar(0, 24 * 60, "dmin_{}".format(d))
        end_max = model.NewIntVar(0, 24 * 60, "dmax_{}".format(d))
        for idx in idxs:
            v = session_vars[idx]
            model.Add(start_min <= v["start"]).OnlyEnforceIf(on[idx])
            model.Add(end_max >= v["start"] + v["duration"]).OnlyEnforceIf(on[idx])

        model.Add(end_max >= start_min)
        # An unused day contributes no span.
        model.Add(start_min == 0).OnlyEnforceIf(used.Not())
        model.Add(end_max == 0).OnlyEnforceIf(used.Not())

        span = model.NewIntVar(0, 24 * 60, "span_{}".format(d))
        model.Add(span == end_max - start_min)
        span_vars.append(span)
    return span_vars


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

    def snapshot():
        """The current solution, held outside the model so a later solve cannot
        silently change what we report."""
        return {
            idx: {
                "is_scheduled": solver.Value(v["is_scheduled"]),
                "day": solver.Value(v["day"]),
                "start": solver.Value(v["start"]),
                "room": solver.Value(v["room"]),
                "faculty": solver.Value(v["faculty"]),
            }
            for idx, v in session_vars.items()
        }

    # The solution that is actually reported. Everything below only ever replaces
    # it with one that schedules exactly as many sessions.
    chosen = snapshot() if status in (cp_model.OPTIMAL, cp_model.FEASIBLE) else None

    # --- Secondary (soft) objective, lexicographic --------------------------
    # The primary objective (maximize scheduled sessions) stays strictly
    # dominant: its achieved value is frozen as a hard constraint, so the soft
    # preferences are optimized only inside that plateau. They can therefore
    # never trade a session away for a tidier-looking timetable.
    if chosen is not None:
        primary_scheduled_count = sum(1 for s in chosen.values() if s["is_scheduled"])
        gap_terms = _gap_penalty_terms(
            model, session_vars, sessions_meta, GAP_PREFERENCE_MINUTES
        )
        same_subject_terms = _same_subject_day_terms(
            model, session_vars, sessions_meta
        )
        if gap_terms or same_subject_terms:
            model.Add(
                sum(v["is_scheduled"] for v in session_vars.values())
                == primary_scheduled_count
            )
            # Compactness guard (allowance 0). The total daily span stage 1
            # already used is frozen as a HARD budget, so the preference can
            # never buy a tidier transition by stretching a section's day. The
            # stage-1 solution satisfies this bound by construction, so the
            # second solve cannot become infeasible because of it.
            primary_span = _span_minutes(chosen, session_vars)
            model.Add(
                sum(_daily_span_vars(model, session_vars, DAYS)) <= primary_span
            )
            # Both soft preferences in one objective, lexicographically, with
            # the SAME-SUBJECT day distribution ranked ABOVE the transition
            # gaps: the same-subject count is weighted by one more than the
            # largest possible number of gap terms, so removing even a single
            # same-subject same-day pair always beats removing every
            # transition gap at once. Minimizing
            # `W * same_subject_days + gaps` is then identical to minimizing
            # the same-subject-day count first and the gap count second.
            same_subject_weight = len(gap_terms) + 1
            model.Maximize(
                -(same_subject_weight * sum(same_subject_terms) + sum(gap_terms))
            )
            gap_status = solver.Solve(model)
            if gap_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
                chosen = snapshot()
                status = gap_status
            # Otherwise keep the primary solution and status. The equality above
            # means any second-stage solution schedules exactly as many sessions
            # anyway, so falling back cannot distort the primary outcome.

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

    if chosen is not None:
        for idx, v in session_vars.items():
            if chosen[idx]["is_scheduled"]:
                scheduled_count += 1
                day = chosen[idx]["day"]
                start = chosen[idx]["start"]
                end = start + v["duration"]
                room_id = rooms[chosen[idx]["room"]]["id"]
                faculty_id = faculty[chosen[idx]["faculty"]]["id"]
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