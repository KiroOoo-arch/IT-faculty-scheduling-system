"""
Arrangement-independent quality invariants for the scheduling solver.

The other test modules pin specific solver outcomes (exact days, exact gaps,
frozen-span equality for a single scenario). Those are valuable, but they can
only complain about one arrangement. This module asks the complementary
question instead:

    For a representative set of inputs, does the timetable the solver returns
    satisfy every hard constraint re-derived independently from the raw inputs,
    and does it stay inside the daily-span budget the solver itself froze?

Every assertion here is a *property* of a valid schedule, so it holds for any
equally optimal arrangement and cannot flake when two solves legitimately
differ. Nothing compares two independently generated timetables, and nothing
depends on a session landing on a particular day, start time, or room.

The hard constraints are re-derived here (not read back out of the solver) from
the same input dicts `api/app.py` builds, so a regression in the solver's own
constraint code cannot hide behind a matching bug in a shared helper:

  * no faculty, room, or section self-overlap;
  * required contact hours / lecture-lab allocation preserved;
  * the midday break never overlapped;
  * classes inside the section window and on the 30-minute grid;
  * room type matches the session type and room capacity seats the section;
  * faculty qualified, available on the day, inside declared windows, and
    within both the remaining and total teaching-load caps;
  * conflicts with existing (cross-section) sessions avoided.

The soft side is checked only where the implementation gives a guarantee that
is independent of the chosen arrangement:

  * the total daily span never exceeds the span stage 1 used for the SAME
    solve (the compactness guard);
  * the preferences never reduce the number of scheduled sessions.

The `TestNegativeControls` class proves these checks are not vacuous: each test
deliberately corrupts a valid schedule and asserts the corresponding violation
is detected. A check that cannot fail is not a check.
"""

import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.append(str(Path(__file__).resolve().parent.parent / "solver"))

import scheduler  # noqa: E402
from scheduler import generate_schedule  # noqa: E402
from time_slots import hhmm_to_minutes, hours_to_minutes  # noqa: E402


# ---------------------------------------------------------------------------
# Fixture builders (same shapes api/app.py builds, matching the other modules)
# ---------------------------------------------------------------------------

def make_section(start="07:00", end="17:00", days=(1, 2, 3, 4, 5),
                 student_count=30, lunch=None):
    data = {
        "id": 1,
        "name": "BSIT 1A",
        "preferred_days": list(days),
        "preferred_start_minutes": hhmm_to_minutes(start),
        "preferred_end_minutes": hhmm_to_minutes(end),
        "student_count": student_count,
    }
    if lunch is not None:
        data["lunch"] = lunch
    return data


def make_subject(subject_id=1, code="PROG1", lecture_hours=2, lab_hours=0,
                 lab_room_type=None):
    return {
        "id": subject_id,
        "code": code,
        "lecture_hours": lecture_hours,
        "lab_hours": lab_hours,
        "lab_room_type": lab_room_type,
    }


def make_faculty(faculty_id=1, name="Prof. Test", subject_ids=None,
                 available_days=None, max_teaching_load=24,
                 existing_load_hours=0, available_windows=None):
    return {
        "id": faculty_id,
        "name": name,
        "can_teach_subject_ids": subject_ids if subject_ids is not None else [1],
        "available_days": available_days if available_days is not None else [1, 2, 3, 4, 5],
        "available_windows": available_windows or [],
        "max_teaching_load": max_teaching_load,
        "existing_load_hours": existing_load_hours,
    }


def make_room(room_id=1, name="R101", room_type="lecture", capacity=40):
    return {"id": room_id, "name": name, "type": room_type, "capacity": capacity}


def scheduled(result):
    return [s for s in result["sessions"] if s["is_scheduled"]]


def unscheduled(result):
    return [s for s in result["sessions"] if not s["is_scheduled"]]


# ---------------------------------------------------------------------------
# Independent re-derivation of the input's constraints
# ---------------------------------------------------------------------------

def _minutes(value, fallback=None):
    if value is None:
        return fallback
    if isinstance(value, str):
        return hhmm_to_minutes(value)
    return int(value)


def _bound(minutes_value, hours_value):
    """Resolve a time that may be minutes (int or 'HH:MM') or whole hours."""
    if minutes_value is not None:
        if isinstance(minutes_value, str):
            return hhmm_to_minutes(minutes_value)
        return int(minutes_value)
    if hours_value is not None:
        return hours_to_minutes(hours_value)
    return None


def as_hhmm(minutes):
    return f"{minutes // 60:02d}:{minutes % 60:02d}"


def read_window(section):
    start = section.get("preferred_start_minutes")
    if start is None:
        start = hours_to_minutes(section.get("preferred_start_hour", 0))
    end = section.get("preferred_end_minutes")
    if end is None:
        end = hours_to_minutes(section.get("preferred_end_hour", 24))
    return start, end


def read_break(section):
    lunch = section.get("lunch")
    if not isinstance(lunch, dict) or not lunch.get("enabled", True):
        return None
    start = _minutes(lunch.get("start_minutes"), _minutes(lunch.get("start")))
    end = _minutes(lunch.get("end_minutes"), _minutes(lunch.get("end")))
    if start is None or end is None or end <= start:
        return None
    return start, end


def expected_sessions(subjects):
    """(subject_id, session_type, duration_minutes) implied by the input."""
    out = []
    for s in subjects:
        if s["lecture_hours"] > 0:
            out.append((s["id"], "lecture", hours_to_minutes(s["lecture_hours"])))
        if s["lab_hours"] > 0:
            out.append((s["id"], "laboratory", hours_to_minutes(s["lab_hours"])))
    return out


def _overlap(a_start, a_end, b_start, b_end):
    return a_start < b_end and b_start < a_end


def validate_hard_constraints(section, subjects, faculty, rooms, existing, result):
    """
    Re-derive every hard constraint from the raw inputs and return a list of
    human-readable violations for `result`. An empty list means the schedule is
    valid for that input. Pure function of its arguments, so the negative
    controls can hand it a deliberately broken schedule.
    """
    violations = []
    window_start, window_end = read_window(section)
    brk = read_break(section)
    student_count = section.get("student_count", 30)
    subjects_by_id = {s["id"]: s for s in subjects}
    faculty_by_id = {f["id"]: f for f in faculty}
    rooms_by_id = {r["id"]: r for r in rooms}

    # --- required contact hours / lecture-lab allocation preserved ---
    got = sorted((s["subject_id"], s["session_type"]) for s in result["sessions"])
    want = sorted((sid, stype) for sid, stype, _ in expected_sessions(subjects))
    if got != want:
        violations.append(f"session allocation changed: got {got}, want {want}")

    placed = []
    for s in scheduled(result):
        missing = [k for k in ("subject_id", "session_type", "day_of_week",
                               "start_time", "end_time", "room_id", "faculty_id")
                   if k not in s]
        if missing:
            violations.append(f"session missing fields {missing}: {s}")
            continue

        start = hhmm_to_minutes(s["start_time"])
        end = hhmm_to_minutes(s["end_time"])
        day = s["day_of_week"]
        subject = subjects_by_id.get(s["subject_id"])
        room = rooms_by_id.get(s["room_id"])
        member = faculty_by_id.get(s["faculty_id"])
        tag = f"{s['session_type']}#{s['subject_id']} d{day} {s['start_time']}-{s['end_time']}"

        # --- inside the section window ---
        if start < window_start or end > window_end:
            violations.append(
                f"outside section window: {tag} (window {window_start}-{window_end})")

        # --- duration equals the declared contact hours ---
        if subject is None:
            violations.append(f"unknown subject: {tag}")
        else:
            hours = (subject["lecture_hours"] if s["session_type"] == "lecture"
                     else subject["lab_hours"])
            if end - start != hours_to_minutes(hours):
                violations.append(
                    f"contact hours wrong: {tag} = {end - start}min, "
                    f"want {hours_to_minutes(hours)}")

        # --- midday break is never overlapped ---
        if brk and _overlap(start, end, brk[0], brk[1]):
            violations.append(f"overlaps lunch break {brk}: {tag}")

        # --- classes start on the 30-minute grid ---
        if start % 30 != 0:
            violations.append(f"start not on the 30-minute grid: {tag}")

        # --- room type matches and room seats the section ---
        if room is None:
            violations.append(f"unknown room {s['room_id']}: {tag}")
        else:
            required = ("lecture" if s["session_type"] == "lecture"
                        else (subject or {}).get("lab_room_type"))
            if required is not None and room["type"] != required:
                violations.append(
                    f"room type mismatch: {tag} in {room['type']}, need {required}")
            if room["capacity"] < student_count:
                violations.append(
                    f"room too small: {tag} seats {room['capacity']} < {student_count}")

        # --- faculty qualification, day availability, declared windows ---
        if member is None:
            violations.append(f"unknown faculty {s['faculty_id']}: {tag}")
        else:
            if s["subject_id"] not in member["can_teach_subject_ids"]:
                violations.append(f"unqualified faculty for subject: {tag}")
            if day not in member["available_days"]:
                violations.append(f"faculty unavailable on day {day}: {tag}")
            windows = member.get("available_windows") or []
            if windows:
                inside = False
                for w in windows:
                    if w["day_of_week"] != day:
                        continue
                    w_start = _bound(w.get("start_minutes"), w.get("start_hour"))
                    w_end = _bound(w.get("end_minutes"), w.get("end_hour"))
                    if w_start is None or w_end is None:
                        continue
                    if w_start <= start and end <= w_end:
                        inside = True
                if not inside:
                    violations.append(f"outside faculty availability window: {tag}")

        placed.append((start, end, day, s, member, room))

    # --- no self-overlap / double-booking between scheduled sessions ---
    for i in range(len(placed)):
        for j in range(i + 1, len(placed)):
            s1, e1, d1, sess1, fac1, room1 = placed[i]
            s2, e2, d2, sess2, fac2, room2 = placed[j]
            if d1 != d2 or not _overlap(s1, e1, s2, e2):
                continue
            violations.append(
                f"section self-overlap: {sess1['subject_id']}/{sess1['session_type']} & "
                f"{sess2['subject_id']}/{sess2['session_type']} on d{d1}")
            if fac1 is not None and fac2 is not None and fac1["id"] == fac2["id"]:
                violations.append(f"faculty double-booked: faculty {fac1['id']} on d{d1}")
            if room1 is not None and room2 is not None and room1["id"] == room2["id"]:
                violations.append(f"room double-booked: room {room1['id']} on d{d1}")

    # --- teaching-load caps (remaining and total) ---
    used_by_faculty = {}
    for start, end, _, _, member, _ in placed:
        if member is not None:
            used_by_faculty[member["id"]] = used_by_faculty.get(member["id"], 0) + (end - start)
    for f in faculty:
        used = used_by_faculty.get(f["id"], 0)
        remaining = hours_to_minutes(f["max_teaching_load"] - f.get("existing_load_hours", 0))
        if used > remaining:
            violations.append(f"teaching load exceeded: faculty {f['id']} used {used} > {remaining}")
        total = used + hours_to_minutes(f.get("existing_load_hours", 0))
        if total > hours_to_minutes(f["max_teaching_load"]):
            violations.append(
                f"total load exceeded: faculty {f['id']} {total} > "
                f"{hours_to_minutes(f['max_teaching_load'])}min")

    # --- conflicts with existing cross-section sessions ---
    for ext in existing or []:
        ext_start = _bound(ext.get("start_minutes"), ext.get("start_hour"))
        ext_end = _bound(ext.get("end_minutes"), ext.get("end_hour"))
        if ext_start is None or ext_end is None:
            continue
        for start, end, day, sess, member, room in placed:
            if day != ext["day_of_week"] or not _overlap(start, end, ext_start, ext_end):
                continue
            if (ext.get("faculty_id") is not None and member is not None
                    and member["id"] == ext["faculty_id"]):
                violations.append(
                    f"clashes with existing faculty {ext['faculty_id']} on d{day}")
            if (ext.get("room_id") is not None and room is not None
                    and room["id"] == ext["room_id"]):
                violations.append(f"clashes with existing room {ext['room_id']} on d{day}")

    return violations


def total_span(result):
    """Total section-day span in minutes: sum over days of last end - first start."""
    by_day = {}
    for s in scheduled(result):
        by_day.setdefault(s["day_of_week"], []).append(s)
    return sum(
        hhmm_to_minutes(items[-1]["end_time"]) - hhmm_to_minutes(items[0]["start_time"])
        for items in by_day.values()
    )


def span_budget_for(**kwargs):
    """
    Run `generate_schedule` and return `(result, frozen_span)`, where
    `frozen_span` is the total daily span stage 1 used for THIS solve and the
    compactness guard therefore froze stage 2 to.

    Capturing the budget from the same solve keeps the span assertion
    arrangement-independent: it is checked against the very solve it
    constrained, never against a second, independently solved timetable.
    """
    captured = {}
    real = scheduler._span_minutes

    def spy(chosen, session_vars):
        value = real(chosen, session_vars)
        captured["span"] = value
        return value

    with mock.patch.object(scheduler, "_span_minutes", spy):
        result = generate_schedule(**kwargs)
    return result, captured.get("span")


def span_violates_budget(result, budget):
    """True when a result stretched a day past the budget it was given."""
    return total_span(result) > budget


def same_subject_day_pairs(result):
    """Count pairs of scheduled sessions of the SAME subject that share a day."""
    pairs = 0
    placed = scheduled(result)
    for i in range(len(placed)):
        for j in range(i + 1, len(placed)):
            if placed[i]["subject_id"] != placed[j]["subject_id"]:
                continue
            if placed[i]["day_of_week"] == placed[j]["day_of_week"]:
                pairs += 1
    return pairs


def run(**kwargs):
    """Solve a scenario, threading the optional existing sessions through."""
    existing = kwargs.pop("existing_sessions", None)
    return generate_schedule(kwargs.pop("section"), kwargs.pop("subjects"),
                             kwargs.pop("faculty"), kwargs.pop("rooms"),
                             existing or None)


# ---------------------------------------------------------------------------
# Representative scenarios (all deterministic, no random seed involved)
# ---------------------------------------------------------------------------

def quality_scenarios():
    return {
        # Two independent subjects, two faculty, one lecture room.
        "two_subjects_two_faculty": dict(
            section=make_section("07:00", "17:00", [1, 2, 3, 4, 5]),
            subjects=[make_subject(1, "PROG1", lecture_hours=2),
                      make_subject(2, "PROG2", lecture_hours=2)],
            faculty=[make_faculty(1, subject_ids=[1]),
                     make_faculty(2, "Prof. Two", subject_ids=[2])],
            rooms=[make_room(1, "R101")],
        ),
        # One subject whose lecture and laboratory need different rooms.
        "lecture_and_laboratory": dict(
            section=make_section("07:00", "17:00", [1, 2, 3, 4, 5]),
            subjects=[make_subject(1, "PROG1", lecture_hours=2, lab_hours=2,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(1, subject_ids=[1])],
            rooms=[make_room(1, "R101"),
                   make_room(2, "LAB1", room_type="computer_lab")],
        ),
        # Three subjects sharing one day with a mandatory midday break.
        "midday_break_kept": dict(
            section=make_section("07:00", "18:00", [1],
                                 lunch={"enabled": True, "start": "12:00", "end": "13:00"}),
            subjects=[make_subject(1, "A", lecture_hours=2),
                      make_subject(2, "B", lecture_hours=3),
                      make_subject(3, "C", lecture_hours=2)],
            faculty=[make_faculty(1, subject_ids=[1, 2, 3])],
            rooms=[make_room(1, "R101")],
        ),
        # A narrow window on a half-hour boundary.
        "half_hour_window": dict(
            section=make_section("07:30", "09:30", [1, 2]),
            subjects=[make_subject(1, "A", lecture_hours=2)],
            faculty=[make_faculty(1, subject_ids=[1])],
            rooms=[make_room(1, "R101")],
        ),
        # Faculty may only teach on two of the section's five days.
        "faculty_day_availability": dict(
            section=make_section("07:00", "17:00", [1, 2, 3, 4, 5]),
            subjects=[make_subject(1, "A", lecture_hours=2),
                      make_subject(2, "B", lecture_hours=2)],
            faculty=[make_faculty(1, subject_ids=[1, 2], available_days=[1, 2])],
            rooms=[make_room(1, "R101")],
        ),
        # Faculty declares daily windows: sessions must sit inside them.
        "faculty_available_windows": dict(
            section=make_section("07:00", "17:00", [1, 2, 3, 4, 5]),
            subjects=[make_subject(1, "A", lecture_hours=2)],
            faculty=[make_faculty(
                1, subject_ids=[1],
                available_windows=[{"day_of_week": d,
                                    "start_minutes": 8 * 60,
                                    "end_minutes": 12 * 60} for d in (1, 2, 3, 4, 5)])],
            rooms=[make_room(1, "R101")],
        ),
        # Remaining teaching load is 4h while the section needs 6h.
        "teaching_load_cap": dict(
            section=make_section("07:00", "17:00", [1, 2, 3, 4, 5]),
            subjects=[make_subject(1, "A", lecture_hours=2),
                      make_subject(2, "B", lecture_hours=2),
                      make_subject(3, "C", lecture_hours=2)],
            faculty=[make_faculty(1, subject_ids=[1, 2, 3],
                                  max_teaching_load=6, existing_load_hours=2)],
            rooms=[make_room(1, "R101")],
        ),
        # The only lecture room is too small for the section.
        "room_capacity_too_small": dict(
            section=make_section("07:00", "17:00", [1, 2, 3, 4, 5], student_count=50),
            subjects=[make_subject(1, "A", lecture_hours=2)],
            faculty=[make_faculty(1, subject_ids=[1])],
            rooms=[make_room(1, "R101", capacity=40)],
        ),
        # A laboratory with no laboratory room available at all.
        "missing_laboratory_room": dict(
            section=make_section("07:00", "17:00", [1, 2, 3, 4, 5]),
            subjects=[make_subject(1, "A", lecture_hours=2, lab_hours=2,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(1, subject_ids=[1])],
            rooms=[make_room(1, "R101")],
        ),
        # An existing session already occupies day 1 in the only room.
        "existing_session_conflict": dict(
            section=make_section("07:00", "17:00", [1, 2, 3, 4, 5]),
            subjects=[make_subject(1, "A", lecture_hours=2)],
            faculty=[make_faculty(1, subject_ids=[1])],
            rooms=[make_room(1, "R101")],
            existing_sessions=[{"day_of_week": 1, "start_minutes": 7 * 60,
                                "end_minutes": 9 * 60, "faculty_id": 1, "room_id": 1}],
        ),
    }


# ---------------------------------------------------------------------------
# Hard constraints: the returned schedule is valid for its own input
# ---------------------------------------------------------------------------

class TestHardConstraints(unittest.TestCase):
    def test_every_scenario_satisfies_all_hard_constraints(self):
        """
        Independently re-derives each constraint from the raw inputs; any
        violation is a real defect regardless of which arrangement the solver
        picked.
        """
        for name, kwargs in quality_scenarios().items():
            with self.subTest(scenario=name):
                result = run(**kwargs)
                self.assertNotEqual(result["status"], "ERROR", result)
                violations = validate_hard_constraints(
                    kwargs["section"], kwargs["subjects"], kwargs["faculty"],
                    kwargs["rooms"], kwargs.get("existing_sessions"), result)
                self.assertEqual(violations, [], f"{name}: {violations}")

    def test_every_unscheduled_session_explains_itself(self):
        """A session left out must carry a non-empty, human-readable reason."""
        for name, kwargs in quality_scenarios().items():
            with self.subTest(scenario=name):
                result = run(**kwargs)
                for s in unscheduled(result):
                    self.assertTrue(s.get("reason"), f"{name}: {s}")

    def test_room_capacity_and_missing_lab_are_reported_not_placed(self):
        """
        The two deliberately under-provisioned scenarios must leave work out
        (never place it in an impossible room) and say why.
        """
        capacity = run(**quality_scenarios()["room_capacity_too_small"])
        self.assertTrue(unscheduled(capacity))
        self.assertTrue(any("large enough" in s["reason"] for s in unscheduled(capacity)))

        missing_lab = run(**quality_scenarios()["missing_laboratory_room"])
        lab = [s for s in unscheduled(missing_lab) if s["session_type"] == "laboratory"]
        self.assertTrue(lab)
        self.assertIn("computer_lab", lab[0]["reason"])


# ---------------------------------------------------------------------------
# Soft preferences: only the guarantees that do not depend on the arrangement
# ---------------------------------------------------------------------------

class TestDailySpanBudget(unittest.TestCase):
    def test_result_never_stretches_past_its_own_frozen_stage_one_span(self):
        """
        The compactness guard freezes the stage-1 daily span as a hard budget.
        The budget is captured from the same solve, so this holds for whichever
        stage-1 arrangement the solver happened to pick.
            """
        scenarios_with_a_budget = 0
        for name, kwargs in quality_scenarios().items():
            with self.subTest(scenario=name):
                result, budget = span_budget_for(**kwargs)
                if budget is None:
                    continue  # no preference terms, so no guarded second stage
                scenarios_with_a_budget += 1
                self.assertLessEqual(
                    total_span(result), budget,
                    f"{name}: span {total_span(result)} exceeded frozen budget {budget}")
        self.assertGreater(scenarios_with_a_budget, 0,
                           "the span check never ran; it would be vacuous")


class TestPreferencesPreservePrimaryOutcome(unittest.TestCase):
    def test_preferences_never_reduce_the_scheduled_count(self):
        """
        The primary objective (number of scheduled sessions) is frozen before
        either preference is optimised, so disabling the preference terms must
        not change that count. This compares the *primary objective value* — a
        unique maximum — not two arrangements.
        """
        for name, kwargs in quality_scenarios().items():
            with self.subTest(scenario=name):
                with_prefs = run(**kwargs)
                with mock.patch.object(scheduler, "_gap_penalty_terms",
                                       lambda *a, **k: []), \
                     mock.patch.object(scheduler, "_same_subject_day_terms",
                                       lambda *a, **k: []):
                    without_prefs = run(**kwargs)
                self.assertEqual(len(scheduled(with_prefs)),
                                 len(scheduled(without_prefs)),
                                 f"{name}: preferences cost a session")


class TestSameSubjectDistribution(unittest.TestCase):
    def test_subject_components_are_spread_when_a_valid_split_exists(self):
        """
        A property, not a placement: when a subject's lecture and laboratory
        can sit on different days inside the frozen span, they must not share a
        day. This mirrors the discriminating scenario in
        test_same_subject_days.py but asserts only the arrangement-independent
        invariant.
        """
        kwargs = dict(
            section=make_section("07:00", "17:00", [1, 2]),
            subjects=[make_subject(1, "PROG101", lecture_hours=3, lab_hours=2,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(1, subject_ids=[1])],
            rooms=[make_room(1, "R101"),
                   make_room(2, "LAB1", room_type="computer_lab")],
        )
        result = run(**kwargs)
        self.assertEqual(len(scheduled(result)), 2, result)
        self.assertEqual(same_subject_day_pairs(result), 0, result)


# ---------------------------------------------------------------------------
# Negative controls: prove each check can actually fail
# ---------------------------------------------------------------------------

class TestNegativeControls(unittest.TestCase):
    """
    Each test corrupts a valid schedule and asserts the corresponding violation
    is detected. Without these, a validator that always returned [] could look
    like a passing suite.
    """

    def _two_session_result(self):
        kwargs = dict(
            section=make_section("07:00", "17:00", [1, 2, 3]),
            subjects=[make_subject(1, "A", lecture_hours=2),
                      make_subject(2, "B", lecture_hours=2)],
            faculty=[make_faculty(1, subject_ids=[1, 2])],
            rooms=[make_room(1, "R101")],
        )
        return kwargs, run(**kwargs)

    def test_checker_accepts_a_valid_schedule(self):
        kwargs, result = self._two_session_result()
        self.assertEqual(len(scheduled(result)), 2, result)
        self.assertEqual(
            validate_hard_constraints(kwargs["section"], kwargs["subjects"],
                                      kwargs["faculty"], kwargs["rooms"], None, result),
            [])

    def test_detects_overlap_and_double_booking(self):
        kwargs, result = self._two_session_result()
        first, second = scheduled(result)
        second["day_of_week"] = first["day_of_week"]
        second["start_time"] = first["start_time"]
        second["end_time"] = first["end_time"]
        second["room_id"] = first["room_id"]
        violations = validate_hard_constraints(
            kwargs["section"], kwargs["subjects"], kwargs["faculty"],
            kwargs["rooms"], None, result)
        joined = " | ".join(violations)
        self.assertIn("self-overlap", joined, violations)
        self.assertIn("room double-booked", joined, violations)
        self.assertIn("faculty double-booked", joined, violations)

    def test_detects_lunch_break_overlap(self):
        kwargs = dict(
            section=make_section("07:00", "18:00", [1],
                                 lunch={"enabled": True, "start": "12:00", "end": "13:00"}),
            subjects=[make_subject(1, "A", lecture_hours=2)],
            faculty=[make_faculty(1, subject_ids=[1])],
            rooms=[make_room(1, "R101")],
        )
        result = run(**kwargs)
        target = scheduled(result)[0]
        target["start_time"], target["end_time"] = "12:30", "14:30"
        violations = validate_hard_constraints(
            kwargs["section"], kwargs["subjects"], kwargs["faculty"],
            kwargs["rooms"], None, result)
        self.assertTrue(any("lunch break" in v for v in violations), violations)

    def test_detects_lost_contact_hours(self):
        kwargs, result = self._two_session_result()
        target = scheduled(result)[0]
        start = hhmm_to_minutes(target["start_time"])
        target["end_time"] = as_hhmm(start + 30)  # 2h lecture shrunk to 30 min
        violations = validate_hard_constraints(
            kwargs["section"], kwargs["subjects"], kwargs["faculty"],
            kwargs["rooms"], None, result)
        self.assertTrue(any("contact hours wrong" in v for v in violations), violations)

    def test_detects_unqualified_or_unknown_faculty(self):
        kwargs, result = self._two_session_result()
        scheduled(result)[0]["faculty_id"] = 999
        violations = validate_hard_constraints(
            kwargs["section"], kwargs["subjects"], kwargs["faculty"],
            kwargs["rooms"], None, result)
        self.assertTrue(any("unknown faculty" in v for v in violations), violations)

    def test_detects_a_span_overrun(self):
        kwargs, result = self._two_session_result()
        span = total_span(result)
        self.assertGreater(span, 0)              # the control is meaningful
        self.assertFalse(span_violates_budget(result, span))
        self.assertTrue(span_violates_budget(result, span - 1))


if __name__ == "__main__":
    unittest.main()
