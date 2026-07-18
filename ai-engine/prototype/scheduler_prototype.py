"""
Minimal OR-Tools CSP prototype for the Faculty/Room Scheduling System.

Goal: prove the core scheduling logic works BEFORE building Laravel/React/DB.
Uses a small hardcoded dataset (no database connection).

Run with:
    pip install ortools --break-system-packages
    python scheduler_prototype.py
"""

from ortools.sat.python import cp_model

# ---------------------------------------------------------------------------
# 1. FAKE DATA (hardcoded, tiny, easy to verify by hand)
# ---------------------------------------------------------------------------

# Days and time slots we allow (1-hour slots, Mon-Fri, 7am-3pm = 8 slots/day)
DAYS = ["Mon", "Tue", "Wed", "Thu", "Fri"]
HOURS = list(range(7, 15))  # 7 -> 15 (7am to 3pm), 8 slots per day

# Build a flat list of (day, hour) slots -> index
SLOTS = [(d, h) for d in DAYS for h in HOURS]
SLOT_INDEX = {slot: i for i, slot in enumerate(SLOTS)}

ROOMS = [
    {"id": "R101", "type": "lecture", "capacity": 40},
    {"id": "LAB1", "type": "computer_lab", "capacity": 30},
]

FACULTY = [
    {
        "id": "F1",
        "name": "Prof. Reyes",
        "can_teach": ["PROG1", "PROG2"],
        "available_days": ["Mon", "Tue", "Wed", "Thu", "Fri"],
    },
    {
        "id": "F2",
        "name": "Prof. Santos",
        "can_teach": ["MATH1"],
        "available_days": ["Mon", "Tue", "Wed", "Thu", "Fri"],
    },
    {
        "id": "F3",
        "name": "Prof. Cruz",
        "can_teach": ["PROG1", "PROG2", "MATH1"],
        "available_days": ["Mon", "Wed", "Fri"],  # part-time, only MWF
    },
]

# Each subject has lecture hours and (optionally) laboratory hours.
# required_room lists which room TYPE is needed for each component.
SUBJECTS = [
    {"id": "PROG1", "lecture_hours": 2, "lab_hours": 3, "lab_room_type": "computer_lab"},
    {"id": "PROG2", "lecture_hours": 2, "lab_hours": 3, "lab_room_type": "computer_lab"},
    {"id": "MATH1", "lecture_hours": 3, "lab_hours": 0, "lab_room_type": None},
]

SECTION = {"id": "BSIT1A", "subjects": ["PROG1", "PROG2", "MATH1"]}

# Break each subject into "sessions" that each need ONE contiguous block.
# e.g. PROG1 -> a 2-hour lecture session + a 3-hour lab session
SESSIONS = []
for subj in SUBJECTS:
    subj_id = subj["id"]
    if subj["lecture_hours"] > 0:
        SESSIONS.append({
            "session_id": f"{subj_id}_LEC",
            "subject": subj_id,
            "duration": subj["lecture_hours"],
            "room_type": "lecture",
        })
    if subj["lab_hours"] > 0:
        SESSIONS.append({
            "session_id": f"{subj_id}_LAB",
            "subject": subj_id,
            "duration": subj["lab_hours"],
            "room_type": subj["lab_room_type"],
        })

# ---------------------------------------------------------------------------
# 2. BUILD THE CP MODEL
# ---------------------------------------------------------------------------

model = cp_model.CpModel()

# --- Decision variables ---
# For each session: which day it starts, which hour it starts, which room, which faculty.
# We only allow sessions to start at an hour where (start + duration) still fits in that day.

session_vars = {}  # session_id -> dict of vars

for sess in SESSIONS:
    subj_id = sess["subject"]
    duration = sess["duration"]
    room_type = sess["room_type"]

    # Which rooms are eligible for this session
    eligible_rooms = [r["id"] for r in ROOMS if r["type"] == room_type]
    if not eligible_rooms:
        raise ValueError(f"No room of type '{room_type}' exists for session {sess['session_id']}")

    # Which faculty are eligible (must be able to teach the subject)
    eligible_faculty = [f["id"] for f in FACULTY if subj_id in f["can_teach"]]
    if not eligible_faculty:
        raise ValueError(f"No faculty can teach '{subj_id}'")

    day_var = model.NewIntVar(0, len(DAYS) - 1, f"{sess['session_id']}_day")
    # start_hour must leave room for the full duration within HOURS
    max_start_hour = HOURS[-1] - duration + 1
    start_hour_var = model.NewIntVar(HOURS[0], max_start_hour, f"{sess['session_id']}_start")
    room_var = model.NewIntVarFromDomain(
        cp_model.Domain.FromValues([ROOMS.index(r) for r in ROOMS if r["id"] in eligible_rooms]),
        f"{sess['session_id']}_room",
    )
    faculty_var = model.NewIntVarFromDomain(
        cp_model.Domain.FromValues([FACULTY.index(f) for f in FACULTY if f["id"] in eligible_faculty]),
        f"{sess['session_id']}_faculty",
    )

    session_vars[sess["session_id"]] = {
        "day": day_var,
        "start": start_hour_var,
        "room": room_var,
        "faculty": faculty_var,
        "duration": duration,
        "meta": sess,
    }

# --- Constraint: part-time faculty availability ---
# Prof. Cruz (F3) only available Mon/Wed/Fri -> restrict day_var when faculty_var == F3's index
cruz_index = [f["id"] for f in FACULTY].index("F3")
cruz_allowed_days = [DAYS.index(d) for d in FACULTY[cruz_index]["available_days"]]

for sid, v in session_vars.items():
    # If faculty assigned is Cruz, day must be in his allowed set.
    is_cruz = model.NewBoolVar(f"{sid}_is_cruz")
    model.Add(v["faculty"] == cruz_index).OnlyEnforceIf(is_cruz)
    model.Add(v["faculty"] != cruz_index).OnlyEnforceIf(is_cruz.Not())
    allowed_day_var = model.NewBoolVar(f"{sid}_day_allowed")
    model.AddAllowedAssignments([v["day"]], [[d] for d in cruz_allowed_days]).OnlyEnforceIf(is_cruz)

# --- Constraint: no faculty double-booking ---
# --- Constraint: no room double-booking ---
# We use interval variables per (day) combo isn't trivial with day as a var,
# so we instead compare every pair of sessions and forbid overlap when day/room/faculty match.

def sessions_overlap(v1, v2):
    """Returns a BoolVar that is true if v1 and v2's time ranges overlap (same day assumed)."""
    # overlap if start1 < end2 AND start2 < end1
    end1 = v1["start"] + v1["duration"]
    end2 = v2["start"] + v2["duration"]
    b = model.NewBoolVar("overlap")
    model.Add(v1["start"] < end2).OnlyEnforceIf(b)
    model.Add(v2["start"] < end1).OnlyEnforceIf(b)
    model.Add(v1["start"] >= end2).OnlyEnforceIf(b.Not())
    return b

session_ids = list(session_vars.keys())
for i in range(len(session_ids)):
    for j in range(i + 1, len(session_ids)):
        v1 = session_vars[session_ids[i]]
        v2 = session_vars[session_ids[j]]

        same_day = model.NewBoolVar(f"same_day_{i}_{j}")
        model.Add(v1["day"] == v2["day"]).OnlyEnforceIf(same_day)
        model.Add(v1["day"] != v2["day"]).OnlyEnforceIf(same_day.Not())

        same_faculty = model.NewBoolVar(f"same_fac_{i}_{j}")
        model.Add(v1["faculty"] == v2["faculty"]).OnlyEnforceIf(same_faculty)
        model.Add(v1["faculty"] != v2["faculty"]).OnlyEnforceIf(same_faculty.Not())

        same_room = model.NewBoolVar(f"same_room_{i}_{j}")
        model.Add(v1["room"] == v2["room"]).OnlyEnforceIf(same_room)
        model.Add(v1["room"] != v2["room"]).OnlyEnforceIf(same_room.Not())

        overlap = sessions_overlap(v1, v2)

        # If same day AND same faculty AND overlapping time -> forbidden
        model.AddBoolOr([same_day.Not(), same_faculty.Not(), overlap.Not()])
        # If same day AND same room AND overlapping time -> forbidden
        model.AddBoolOr([same_day.Not(), same_room.Not(), overlap.Not()])

        # Also: the whole section (BSIT1A) can't have two of its own sessions
        # overlapping regardless of room/faculty, since students can't be in two places.
        model.AddBoolOr([same_day.Not(), overlap.Not()])

# ---------------------------------------------------------------------------
# 3. SOLVE
# ---------------------------------------------------------------------------

solver = cp_model.CpSolver()
solver.parameters.max_time_in_seconds = 10.0
status = solver.Solve(model)

print(f"\nSolver status: {solver.StatusName(status)}\n")

if status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
    print(f"Schedule for section {SECTION['id']}:\n")
    for sid, v in session_vars.items():
        day = DAYS[solver.Value(v["day"])]
        start = solver.Value(v["start"])
        duration = v["duration"]
        end = start + duration
        room = ROOMS[solver.Value(v["room"])]["id"]
        faculty = FACULTY[solver.Value(v["faculty"])]["name"]
        print(f"  {sid:12s} | {day} {start:02d}:00-{end:02d}:00 | Room: {room:6s} | Faculty: {faculty}")
else:
    print("No feasible schedule found with the current data/constraints.")
    print("This is expected sometimes with tiny datasets — try loosening a constraint")
    print("(e.g. add another room, or widen faculty availability) and re-run.")
