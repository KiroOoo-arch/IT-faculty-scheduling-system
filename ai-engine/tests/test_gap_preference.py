"""
Tests for the soft 30-minute transition-gap preference.

The scheduler keeps its existing primary objective — maximize the number of
scheduled sessions — and adds a *secondary*, lexicographic one: among timetables
that schedule the same number of sessions, prefer at least
`GAP_PREFERENCE_MINUTES` (30) minutes of transition time between two consecutive
classes of DIFFERENT subjects. A same-subject lab<->lecture block is exempt,
because its back-to-back continuity is intentional.

Two properties matter most, and each is tested twice: once directly on the
penalty function with a fully pinned layout (exact, search-independent), and
once end-to-end through `generate_schedule` on a scenario that a schedule
without the preference demonstrably gets wrong.

  * the preference is APPLIED — when a layout with a proper gap exists inside
    the same plateau, the solver picks it;
  * the preference is SOFT and strictly subordinate — it must never reduce the
    number of scheduled sessions, even when a zero-gap transition cannot be
    avoided at all.

A third property is the compactness guard. The preference is optimized with the
total daily span the stage-1 (max-sessions) solution already used frozen as a
HARD budget, so it can never make a section's day longer than it already was: it
may rearrange classes inside the space stage 1 used, and nothing more. Those
cases are in `TestCompactnessGuard`.

The remaining cases are regression guards proving that the lunch break, the
half-hour grid, the faculty teaching-load cap and the room rules behave exactly
as they did before the preference was added.
"""

import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.append(str(Path(__file__).resolve().parent.parent / "solver"))

from ortools.sat.python import cp_model  # noqa: E402

import scheduler  # noqa: E402
from scheduler import (  # noqa: E402
    GAP_PREFERENCE_MINUTES,
    _daily_span_vars,
    _gap_penalty_terms,
    _span_minutes,
    generate_schedule,
)
from time_slots import hhmm_to_minutes  # noqa: E402


# ---------------------------------------------------------------------------
# Fixture builders (same shapes api/app.py builds)
# ---------------------------------------------------------------------------

def make_section(start="07:00", end="21:00", days=(1, 2, 3, 4, 5),
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
                 existing_load_hours=0):
    return {
        "id": faculty_id,
        "name": name,
        "can_teach_subject_ids": subject_ids if subject_ids is not None else [1],
        "available_days": available_days if available_days is not None else [1, 2, 3, 4, 5],
        "available_windows": [],
        "max_teaching_load": max_teaching_load,
        "existing_load_hours": existing_load_hours,
    }


def make_room(room_id=1, name="R101", room_type="lecture", capacity=40):
    return {"id": room_id, "name": name, "type": room_type, "capacity": capacity}


def scheduled(result):
    return [s for s in result["sessions"] if s["is_scheduled"]]


def unscheduled(result):
    return [s for s in result["sessions"] if not s["is_scheduled"]]


def bad_transitions(result):
    """
    Consecutive same-day transitions between DIFFERENT subjects that leave less
    than the preferred gap. This is exactly what the secondary objective
    minimizes, so it must be 0 whenever a penalty-free timetable exists.
    """
    by_day = {}
    for s in scheduled(result):
        by_day.setdefault(s["day_of_week"], []).append(s)
    count = 0
    for items in by_day.values():
        items.sort(key=lambda s: s["start_time"])
        for first, second in zip(items, items[1:]):
            gap = hhmm_to_minutes(second["start_time"]) - hhmm_to_minutes(first["end_time"])
            if first["subject_id"] != second["subject_id"] and gap < GAP_PREFERENCE_MINUTES:
                count += 1
    return count


def lecture_subjects(count, hours=2):
    return [make_subject(i, f"S{i}", lecture_hours=hours) for i in range(1, count + 1)]


# ---------------------------------------------------------------------------
# A. The penalty function itself (pinned layouts, no search involved)
# ---------------------------------------------------------------------------

def _penalty_of(subject_a, subject_b, day_a, start_a, day_b, start_b,
                duration=120, scheduled_flags=(1, 1)):
    """
    Sum `_gap_penalty_terms` for two sessions pinned to a known layout.

    Every variable except the penalty booleans is fixed, so any feasible solve
    determines the same penalty; the assertions are exact rather than dependent
    on how CP-SAT happens to search.
    """
    model = cp_model.CpModel()
    meta = [
        {"subject_id": subject_a, "session_type": "lecture",
         "duration": duration, "room_type": "lecture"},
        {"subject_id": subject_b, "session_type": "lecture",
         "duration": duration, "room_type": "lecture"},
    ]
    session_vars = {}
    pinned = ((day_a, start_a, scheduled_flags[0]),
              (day_b, start_b, scheduled_flags[1]))
    for idx, (day, start, is_sched) in enumerate(pinned):
        session_vars[idx] = {
            "day": model.NewIntVarFromDomain(cp_model.Domain.FromValues([day]),
                                             f"s{idx}_day"),
            "start": model.NewIntVarFromDomain(cp_model.Domain.FromValues([start]),
                                               f"s{idx}_start"),
            # The penalty helper never reads room/faculty; constants keep the
            # fixture honest about what is actually exercised.
            "room": model.NewConstant(0),
            "faculty": model.NewConstant(0),
            "is_scheduled": model.NewConstant(is_sched),
            "duration": duration,
            "meta": meta[idx],
        }

    terms = _gap_penalty_terms(model, session_vars, meta, GAP_PREFERENCE_MINUTES)
    if not terms:
        return 0
    solver = cp_model.CpSolver()
    solver.Solve(model)
    return sum(solver.Value(t) for t in terms)


class TestGapPenaltyFunction(unittest.TestCase):
    def test_threshold_is_thirty_minutes(self):
        self.assertEqual(GAP_PREFERENCE_MINUTES, 30)

    def test_zero_gap_between_different_subjects_is_penalized(self):
        """Subject A ends exactly when subject B starts -> one penalty."""
        self.assertEqual(_penalty_of(1, 2, 1, 420, 1, 540), 1)

    def test_exactly_thirty_minutes_is_not_penalized(self):
        """30 minutes is the preferred transition, so it is acceptable."""
        self.assertEqual(_penalty_of(1, 2, 1, 420, 1, 570), 0)

    def test_more_than_thirty_minutes_is_not_penalized(self):
        self.assertEqual(_penalty_of(1, 2, 1, 420, 1, 600), 0)

    def test_same_subject_zero_gap_is_exempt(self):
        """One subject's lab<->lecture block is intentional continuity."""
        self.assertEqual(_penalty_of(7, 7, 1, 420, 1, 540), 0)

    def test_different_days_are_not_penalized(self):
        """There is no transition at all across a day boundary."""
        self.assertEqual(_penalty_of(1, 2, 1, 420, 3, 540), 0)

    def test_unscheduled_session_is_not_penalized(self):
        """Only real transitions count: a dropped session creates none."""
        self.assertEqual(_penalty_of(1, 2, 1, 420, 1, 540, scheduled_flags=(1, 0)), 0)

    def test_pair_is_counted_once_not_once_per_ordering(self):
        """The two orderings of a pair must not both fire."""
        self.assertEqual(_penalty_of(1, 2, 1, 420, 1, 540), 1)

    def test_same_subject_is_exempt_in_both_directions(self):
        self.assertEqual(_penalty_of(3, 3, 1, 540, 1, 420), 0)


# ---------------------------------------------------------------------------
# B. The preference is actually applied
# ---------------------------------------------------------------------------

class TestGapPreferenceIsApplied(unittest.TestCase):
    """
    Each scenario below was checked both with the preference and with it
    effectively disabled. Without it the solver leaves zero-gap
    different-subject transitions in place; with it, the plateau is walked to a
    timetable that has none — as long as the required gaps fit inside the total
    daily span stage 1 already used. `TestCompactnessGuard` covers the cases
    where they do not. So these tests fail if the secondary stage is removed.
    """

    def test_zero_gap_transitions_are_removed_when_spare_time_exists(self):
        """
        Five 2h lectures across five days with a 4h daily window. The sessions
        are cheap to place, so the daily packing is a real choice: without the
        preference one day ends up with two classes back to back.
        """
        result = generate_schedule(
            section=make_section(start="07:00", end="11:00", days=[1, 2, 3, 4, 5]),
            subjects=lecture_subjects(5),
            faculty=[make_faculty(subject_ids=[1, 2, 3, 4, 5])],
            rooms=[make_room()],
        )

        assert len(scheduled(result)) == 5, result
        assert bad_transitions(result) == 0, \
            f"a legal gap layout existed but was not chosen: {scheduled(result)}"

    def test_exempt_same_subject_block_wins_over_a_penalised_pair(self):
        """
        One day, 07:00-11:00, and three 2h sessions: subject 1's lecture, subject
        1's lab, and subject 2's lecture. Only two fit. Keeping subject 1's
        lecture+lab side by side costs nothing (same subject, exempt); pairing
        subject 1 with subject 2 back to back is penalised. The solver must keep
        the exempt block.
        """
        result = generate_schedule(
            section=make_section(start="07:00", end="11:00", days=[1]),
            subjects=[make_subject(1, "PAIR", lecture_hours=2, lab_hours=2,
                                   lab_room_type="computer_lab"),
                      make_subject(2, "OTHER", lecture_hours=2)],
            faculty=[make_faculty(subject_ids=[1, 2])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        )

        placed = scheduled(result)
        assert len(placed) == 2, f"expected exactly two sessions to fit, got {placed}"
        assert {s["subject_id"] for s in placed} == {1}, (
            "the exempt same-subject lab<->lecture block should have been kept "
            f"instead of a penalised different-subject pairing: {placed}"
        )
        assert bad_transitions(result) == 0, placed


# ---------------------------------------------------------------------------
# C. The primary objective stays strictly dominant
# ---------------------------------------------------------------------------

class TestPrimaryObjectiveDominance(unittest.TestCase):
    def test_unavoidable_zero_gaps_do_not_cost_a_session(self):
        """
        Four 2h lectures over two days with an exactly-4h daily window is 8h of
        demand in 8h of capacity: every day is packed solid, so two zero-gap
        transitions are mathematically unavoidable. A preference that could
        override the primary objective would drop sessions to erase them; the
        count must stay at 4 and the unavoidable penalties must simply be
        accepted.
        """
        result = generate_schedule(
            section=make_section(start="07:00", end="11:00", days=[1, 2]),
            subjects=lecture_subjects(4),
            faculty=[make_faculty(subject_ids=[1, 2, 3, 4])],
            rooms=[make_room()],
        )

        assert len(scheduled(result)) == 4, \
            f"a session was sacrificed for the gap preference: {result}"
        assert not unscheduled(result), result
        assert result["status"] in ("OPTIMAL", "FEASIBLE"), result
        # Documents that the penalty really is unavoidable here.
        self.assertEqual(bad_transitions(result), 2)

    def test_full_schedule_survives_the_preference(self):
        """Six sessions with room to spare must all still be placed."""
        subjects = [make_subject(i, f"S{i}", lecture_hours=1, lab_hours=1,
                                 lab_room_type="computer_lab")
                    for i in (1, 2, 3)]
        result = generate_schedule(
            section=make_section(),
            subjects=subjects,
            faculty=[make_faculty(subject_ids=[1, 2, 3])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        )

        assert len(scheduled(result)) == len(subjects) * 2, result
        assert result["status"] == "OPTIMAL", result

    def test_partial_schedule_keeps_the_maximum_it_can_place(self):
        """
        A 6h faculty cap fits three 2h lectures out of four. The preference must
        not lower that to two in exchange for a tidier gap.
        """
        result = generate_schedule(
            section=make_section(),
            subjects=lecture_subjects(4),
            faculty=[make_faculty(subject_ids=[1, 2, 3, 4], max_teaching_load=6)],
            rooms=[make_room()],
        )

        assert len(scheduled(result)) == 3, result
        assert len(unscheduled(result)) == 1, result
        assert result["status"] == "PARTIAL", result


# ---------------------------------------------------------------------------
# D. Existing behaviour is preserved
# ---------------------------------------------------------------------------

class TestExistingBehaviourPreserved(unittest.TestCase):
    def test_lunch_break_is_still_hard_with_the_preference_active(self):
        """No session may be pushed into a 12:00-13:00 break."""
        lunch_start = hhmm_to_minutes("12:00")
        lunch_end = hhmm_to_minutes("13:00")
        result = generate_schedule(
            section=make_section(start="07:00", end="15:00",
                                 lunch={"enabled": True, "start": "12:00",
                                        "end": "13:00"}),
            subjects=lecture_subjects(3),
            faculty=[make_faculty(subject_ids=[1, 2, 3])],
            rooms=[make_room(room_id=1, name="A"), make_room(room_id=2, name="B")],
        )

        assert len(scheduled(result)) == 3, result
        for s in scheduled(result):
            start = hhmm_to_minutes(s["start_time"])
            end = hhmm_to_minutes(s["end_time"])
            assert not (start < lunch_end and end > lunch_start), \
                f"session overlaps the lunch break: {s}"

    def test_half_hour_grid_is_still_used(self):
        """Every emitted start/end stays on a 30-minute boundary."""
        result = generate_schedule(
            section=make_section(start="07:30", end="20:30"),
            subjects=lecture_subjects(2),
            faculty=[make_faculty(subject_ids=[1, 2])],
            rooms=[make_room()],
        )

        assert len(scheduled(result)) == 2, result
        for s in scheduled(result):
            for key in ("start_time", "end_time"):
                minutes = hhmm_to_minutes(s[key])
                assert minutes % 30 == 0, f"{key}={s[key]} is off the 30-minute grid"

    def test_faculty_teaching_load_cap_still_binds(self):
        """
        A 4h cap fits two 2h lectures; a 3h cap fits only one. The preference
        must not push an extra session past the cap.
        """
        subjects = lecture_subjects(2)

        fits = generate_schedule(
            section=make_section(),
            subjects=subjects,
            faculty=[make_faculty(subject_ids=[1, 2], max_teaching_load=4)],
            rooms=[make_room()],
        )
        assert len(scheduled(fits)) == 2, fits

        capped = generate_schedule(
            section=make_section(),
            subjects=subjects,
            faculty=[make_faculty(subject_ids=[1, 2], max_teaching_load=3)],
            rooms=[make_room()],
        )
        assert len(scheduled(capped)) == 1, capped
        assert len(unscheduled(capped)) == 1
        assert unscheduled(capped)[0]["reason"].strip()

    def test_existing_load_hours_still_count_against_the_cap(self):
        """Hours already committed elsewhere still reduce the budget."""
        result = generate_schedule(
            section=make_section(),
            subjects=[make_subject(1, "AAA", lecture_hours=2)],
            faculty=[make_faculty(subject_ids=[1], max_teaching_load=24,
                                  existing_load_hours=23)],  # only 1h left
            rooms=[make_room()],
        )

        assert len(scheduled(result)) == 0, result
        assert len(unscheduled(result)) == 1

    def test_room_capacity_rule_is_unchanged(self):
        """A 50-student section still gets the 60-cap room, never the 30-cap one."""
        result = generate_schedule(
            section=make_section(student_count=50),
            subjects=[make_subject(1, "AAA", lecture_hours=2)],
            faculty=[make_faculty(subject_ids=[1])],
            rooms=[make_room(room_id=1, name="SMALL", capacity=30),
                   make_room(room_id=2, name="BIG", capacity=60)],
        )

        assert len(scheduled(result)) == 1, result
        assert {s["room_id"] for s in scheduled(result)} == {2}, result

    def test_room_type_rule_is_unchanged(self):
        """A lab with no matching room type keeps its structural reason."""
        result = generate_schedule(
            section=make_section(),
            subjects=[make_subject(1, "AAA", lecture_hours=2, lab_hours=3,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(subject_ids=[1])],
            rooms=[make_room(room_id=1, room_type="lecture", capacity=60)],
        )

        lab = [s for s in unscheduled(result) if s["session_type"] == "laboratory"]
        assert len(lab) == 1, result
        assert "No room of type 'computer_lab' exists" in lab[0]["reason"], lab[0]

    def test_result_shape_is_unchanged(self):
        """The public return contract is untouched by the new stage."""
        result = generate_schedule(
            section=make_section(),
            subjects=[make_subject(1, "AAA", lecture_hours=2)],
            faculty=[make_faculty(subject_ids=[1])],
            rooms=[make_room()],
        )

        assert set(result.keys()) == {"status", "message", "sessions"}
        assert result["status"] == "OPTIMAL"
        assert result["message"] is None
        for s in result["sessions"]:
            assert {"subject_id", "session_type", "is_scheduled"} <= set(s.keys())


# ---------------------------------------------------------------------------
# E. The compactness guard: stage 2 may not stretch a section's days
# ---------------------------------------------------------------------------

def total_span(result):
    """
    Total section-day span of a schedule, in minutes: the sum over days of
    (last end - first start). That is the scheduled minutes plus every idle gap
    inside a day — exactly the quantity the guard freezes.
    """
    by_day = {}
    for s in scheduled(result):
        by_day.setdefault(s["day_of_week"], []).append(s)
    total = 0
    for items in by_day.values():
        items.sort(key=lambda s: s["start_time"])
        total += (hhmm_to_minutes(items[-1]["end_time"])
                  - hhmm_to_minutes(items[0]["start_time"]))
    return total


def generate_uncapped(**kwargs):
    """
    The gap preference with the compactness guard neutralised.

    With no span variables the frozen-span constraint degenerates to the
    trivially-true `0 <= primary_span`, leaving exactly the shipped objective:
    minimize the penalties over the whole count plateau, no matter how far a day
    has to be stretched. This is the negative control for the guard.
    """
    with mock.patch.object(scheduler, "_daily_span_vars", lambda *a, **k: []):
        return generate_schedule(**kwargs)


def generate_stage_one_only(**kwargs):
    """Only the primary objective: the count plateau with no gap preference."""
    with mock.patch.object(scheduler, "_gap_penalty_terms", lambda *a, **k: []):
        return generate_schedule(**kwargs)


class TestCompactnessGuard(unittest.TestCase):
    """
    The stage-1 total daily span is frozen (allowance 0), so the gap preference
    can never buy a tidier transition by lengthening a day. Two structural
    invariants follow and are asserted directly:

      * the number of scheduled sessions is still exactly the primary maximum;
      * the reported total span never exceeds the stage-1 span.
    """

    def test_span_helper_measures_scheduled_sessions_only(self):
        """
        `_span_minutes` is the quantity the budget is taken from, so it must sum
        (last end - first start) per day and ignore a session that was dropped:
        an unscheduled session has no place in the timetable.
        """
        session_vars = {
            0: {"duration": 120},
            1: {"duration": 120},
            2: {"duration": 120},   # dropped
            3: {"duration": 60},
        }
        chosen = {
            0: {"is_scheduled": 1, "day": 1, "start": 540},   # 09:00-11:00
            1: {"is_scheduled": 1, "day": 1, "start": 660},   # 11:00-13:00
            2: {"is_scheduled": 0, "day": 1, "start": 420},   # would widen day 1
            3: {"is_scheduled": 1, "day": 3, "start": 900},   # 15:00-16:00
        }
        # day 1: 540..780 -> 240 ; day 3: 900..960 -> 60
        self.assertEqual(_span_minutes(chosen, session_vars), 300)

    def test_daily_span_vars_measure_the_same_span_as_the_helper(self):
        """
        The model's per-day span variables must agree with `_span_minutes`;
        otherwise the frozen budget would bound a different quantity than the one
        the stage-1 solution reports.
        """
        model = cp_model.CpModel()
        session_vars = {
            0: {"day": model.NewConstant(1), "start": model.NewConstant(540),
                "is_scheduled": model.NewConstant(1), "duration": 120},
            1: {"day": model.NewConstant(1), "start": model.NewConstant(660),
                "is_scheduled": model.NewConstant(1), "duration": 120},
            2: {"day": model.NewConstant(1), "start": model.NewConstant(420),
                "is_scheduled": model.NewConstant(0), "duration": 60},
        }
        span_vars = _daily_span_vars(model, session_vars, [1, 2, 3])
        # The variables are only *bounded* by the caller's cap; minimizing them
        # expresses the true span of the pinned timetable, which is what the
        # stage-2 solve has to keep inside that cap.
        model.Minimize(sum(span_vars))

        solver = cp_model.CpSolver()
        solver.Solve(model)
        modelled = sum(solver.Value(s) for s in span_vars)

        chosen = {
            0: {"is_scheduled": 1, "day": 1, "start": 540},
            1: {"is_scheduled": 1, "day": 1, "start": 660},
            2: {"is_scheduled": 0, "day": 1, "start": 420},
        }
        self.assertEqual(modelled, 240)
        self.assertEqual(modelled, _span_minutes(chosen, session_vars))

    def test_count_and_span_invariants_hold_across_scenarios(self):
        """
        (a) the session count is frozen at the primary maximum, and (b) the
        reported total span never exceeds the stage-1 span. Both properties are
        structural — they hold for every cap value — so they are asserted over a
        spread of section shapes rather than one tuned case.
        """
        scenarios = [
            ("one day, five 2h lectures",
             dict(section=make_section(start="07:00", end="12:00", days=[1]),
                  subjects=lecture_subjects(5),
                  faculty=[make_faculty(subject_ids=[1, 2, 3, 4, 5])],
                  rooms=[make_room()])),
            ("three days, six 2h lectures",
             dict(section=make_section(start="07:00", end="12:00", days=[1, 2, 3]),
                  subjects=lecture_subjects(6),
                  faculty=[make_faculty(subject_ids=[1, 2, 3, 4, 5, 6])],
                  rooms=[make_room()])),
            ("five days, five 2h lectures",
             dict(section=make_section(start="07:00", end="11:00", days=[1, 2, 3, 4, 5]),
                  subjects=lecture_subjects(5),
                  faculty=[make_faculty(subject_ids=[1, 2, 3, 4, 5])],
                  rooms=[make_room()])),
            ("wide window, four 2h lectures",
             dict(section=make_section(start="07:00", end="20:00", days=[1, 2, 3, 4, 5]),
                  subjects=lecture_subjects(4),
                  faculty=[make_faculty(subject_ids=[1, 2, 3, 4])],
                  rooms=[make_room()])),
            ("with a 12:00-13:00 lunch break",
             dict(section=make_section(start="07:00", end="15:00",
                                       lunch={"enabled": True, "start": "12:00",
                                              "end": "13:00"}),
                  subjects=lecture_subjects(3),
                  faculty=[make_faculty(subject_ids=[1, 2, 3])],
                  rooms=[make_room(room_id=1, name="A"),
                         make_room(room_id=2, name="B")])),
            ("lecture + lab block",
             dict(section=make_section(start="07:00", end="17:00"),
                  subjects=[make_subject(1, "MIX", lecture_hours=2, lab_hours=3,
                                         lab_room_type="computer_lab"),
                            make_subject(2, "OTH", lecture_hours=2)],
                  faculty=[make_faculty(subject_ids=[1, 2])],
                  rooms=[make_room(room_id=1, room_type="lecture"),
                         make_room(room_id=2, name="LAB1", room_type="computer_lab")])),
            ("partial schedule under a faculty cap",
             dict(section=make_section(),
                  subjects=lecture_subjects(4),
                  faculty=[make_faculty(subject_ids=[1, 2, 3, 4], max_teaching_load=6)],
                  rooms=[make_room()])),
        ]

        for label, kwargs in scenarios:
            with self.subTest(scenario=label):
                stage_one = generate_stage_one_only(**kwargs)
                capped = generate_schedule(**kwargs)
                self.assertEqual(len(scheduled(capped)), len(scheduled(stage_one)))
                self.assertEqual(len(unscheduled(capped)), len(unscheduled(stage_one)))
                self.assertLessEqual(total_span(capped), total_span(stage_one))

    def test_guard_stops_the_preference_from_stretching_a_single_day(self):
        """
        Five 2h lectures in a single 07:00-12:00 day: only two fit. Unguarded, the
        preference separates them by 30 minutes (span 4h30 = 270) to erase the
        transition; the guard keeps them inside the 4h (240) stage 1 already used,
        so the one transition is simply accepted.
        """
        kwargs = dict(
            section=make_section(start="07:00", end="12:00", days=[1]),
            subjects=lecture_subjects(5),
            faculty=[make_faculty(subject_ids=[1, 2, 3, 4, 5])],
            rooms=[make_room()],
        )
        stage_one = generate_stage_one_only(**kwargs)
        capped = generate_schedule(**kwargs)
        unguarded = generate_uncapped(**kwargs)

        self.assertEqual(len(scheduled(capped)), 2)
        self.assertEqual(len(scheduled(unguarded)), 2)
        self.assertEqual(total_span(capped), total_span(stage_one))
        self.assertEqual(total_span(capped), 240)
        self.assertLess(total_span(capped), total_span(unguarded))
        self.assertEqual(bad_transitions(unguarded), 0)
        self.assertEqual(bad_transitions(capped), 1)

    def test_a_gap_that_would_lengthen_a_day_is_refused(self):
        """
        Six 2h lectures over three days with a 5h daily window: two per day, and
        the smallest total span is 12h (3 x 4h). A 30-minute gap inside each day
        would cost 90 extra minutes of span, so the guard refuses it and the three
        back-to-back different-subject transitions stay in place.
        """
        kwargs = dict(
            section=make_section(start="07:00", end="12:00", days=[1, 2, 3]),
            subjects=lecture_subjects(6),
            faculty=[make_faculty(subject_ids=[1, 2, 3, 4, 5, 6])],
            rooms=[make_room()],
        )
        stage_one = generate_stage_one_only(**kwargs)
        capped = generate_schedule(**kwargs)
        unguarded = generate_uncapped(**kwargs)

        self.assertEqual(len(scheduled(capped)), 6)
        self.assertEqual(total_span(capped), total_span(stage_one))
        self.assertEqual(total_span(capped), 720)
        self.assertEqual(bad_transitions(capped), 3)
        # Without the guard the same six sessions are spread to erase them all.
        self.assertEqual(bad_transitions(unguarded), 0)
        self.assertGreater(total_span(unguarded), total_span(capped))

    def test_preference_still_applies_inside_the_frozen_span(self):
        """
        The guard forbids only *new* span; gaps that fit inside the space stage 1
        already used are still preferred. Four 2h lectures over five days,
        07:00-20:00: the minimum total span is 8h (one class per day), and inside
        it every transition can be spaced. Stage 1 leaves three zero-gap
        transitions; the guard removes all three without changing the span.
        """
        kwargs = dict(
            section=make_section(start="07:00", end="20:00", days=[1, 2, 3, 4, 5]),
            subjects=lecture_subjects(4),
            faculty=[make_faculty(subject_ids=[1, 2, 3, 4])],
            rooms=[make_room()],
        )
        stage_one = generate_stage_one_only(**kwargs)
        capped = generate_schedule(**kwargs)

        self.assertEqual(len(scheduled(capped)), 4)
        self.assertEqual(total_span(capped), total_span(stage_one))
        self.assertEqual(total_span(capped), 480)
        self.assertEqual(bad_transitions(stage_one), 3)
        self.assertEqual(bad_transitions(capped), 0)

    def test_back_to_back_different_subjects_are_still_allowed(self):
        """
        The guard must never be read as "no two different subjects may touch".
        When the frozen span leaves no room for a 30-minute gap the classes are
        scheduled back to back and all sessions are still placed — the preference
        is soft and subordinate, not a rule.
        """
        result = generate_schedule(
            section=make_section(start="07:00", end="12:00", days=[1, 2, 3]),
            subjects=lecture_subjects(6),
            faculty=[make_faculty(subject_ids=[1, 2, 3, 4, 5, 6])],
            rooms=[make_room()],
        )
        placed = scheduled(result)

        self.assertEqual(len(placed), 6)
        self.assertFalse(unscheduled(result))
        touching = 0
        for day in sorted({s["day_of_week"] for s in placed}):
            items = sorted((s for s in placed if s["day_of_week"] == day),
                           key=lambda s: s["start_time"])
            touching += sum(1 for a, b in zip(items, items[1:])
                            if a["end_time"] == b["start_time"]
                            and a["subject_id"] != b["subject_id"])
        self.assertEqual(touching, 3)

    def test_same_subject_lab_lecture_block_stays_exempt_under_the_guard(self):
        """
        (d)+(e) A window that fits exactly two of three sessions, one of them
        subject 1's own lecture+lab. Every two-session layout has the same span,
        so the guard cannot interfere; the exempt same-subject block is still the
        one chosen over the penalised different-subject pairing.
        """
        result = generate_schedule(
            section=make_section(start="07:00", end="11:00", days=[1]),
            subjects=[make_subject(1, "PAIR", lecture_hours=2, lab_hours=2,
                                   lab_room_type="computer_lab"),
                      make_subject(2, "OTHER", lecture_hours=2)],
            faculty=[make_faculty(subject_ids=[1, 2])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        )

        placed = scheduled(result)
        self.assertEqual(len(placed), 2)
        self.assertEqual({s["subject_id"] for s in placed}, {1})
        self.assertEqual({s["session_type"] for s in placed}, {"lecture", "laboratory"})
        self.assertEqual(total_span(result), 240)
        self.assertEqual(bad_transitions(result), 0)

    def test_lunch_break_stays_hard_under_the_guard(self):
        """
        (f) The guard constrains only how far a day may stretch; it can never
        push a class into the 12:00-13:00 break, because the break restricts each
        session's start domain.
        """
        lunch_start = hhmm_to_minutes("12:00")
        lunch_end = hhmm_to_minutes("13:00")

        kwargs = dict(
            section=make_section(start="07:00", end="15:00",
                                 lunch={"enabled": True, "start": "12:00",
                                        "end": "13:00"}),
            subjects=lecture_subjects(3),
            faculty=[make_faculty(subject_ids=[1, 2, 3])],
            rooms=[make_room(room_id=1, name="A"), make_room(room_id=2, name="B")],
        )
        stage_one = generate_stage_one_only(**kwargs)
        capped = generate_schedule(**kwargs)

        self.assertEqual(len(scheduled(capped)), len(scheduled(stage_one)))
        self.assertLessEqual(total_span(capped), total_span(stage_one))
        for s in scheduled(capped):
            start = hhmm_to_minutes(s["start_time"])
            end = hhmm_to_minutes(s["end_time"])
            self.assertFalse(start < lunch_end and end > lunch_start,
                             f"session overlaps the lunch break: {s}")

    def test_units_and_contact_hours_are_unchanged_under_the_guard(self):
        """
        (g) The guard changes only *where* classes sit, never how long they are:
        every emitted session keeps the duration its units require, and the total
        scheduled minutes equal the total required minutes.
        """
        subjects = [make_subject(i, f"S{i}", lecture_hours=3) for i in (1, 2, 3)]
        result = generate_schedule(
            section=make_section(start="07:00", end="13:00", days=[1, 2, 3]),
            subjects=subjects,
            faculty=[make_faculty(subject_ids=[1, 2, 3])],
            rooms=[make_room()],
        )

        placed = scheduled(result)
        self.assertEqual(len(placed), 3)
        for s in placed:
            self.assertEqual(
                hhmm_to_minutes(s["end_time"]) - hhmm_to_minutes(s["start_time"]),
                3 * 60,
            )
        self.assertEqual(
            sum(hhmm_to_minutes(s["end_time"]) - hhmm_to_minutes(s["start_time"])
                for s in placed),
            3 * 3 * 60,
        )

        # A lab keeps its own hours too, under the same guard.
        mixed = generate_schedule(
            section=make_section(start="07:00", end="17:00", days=[1, 2]),
            subjects=[make_subject(1, "MIX", lecture_hours=2, lab_hours=3,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(subject_ids=[1])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        )
        durations = {(s["subject_id"], s["session_type"]):
                     hhmm_to_minutes(s["end_time"]) - hhmm_to_minutes(s["start_time"])
                     for s in scheduled(mixed)}
        self.assertEqual(durations, {(1, "lecture"): 120, (1, "laboratory"): 180})
