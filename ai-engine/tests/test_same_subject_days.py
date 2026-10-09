"""
Tests for the soft same-subject-different-day preference.

A subject can have more than one weekly meeting component — typically a lecture
and its laboratory (which need different rooms, so they stay distinct sessions
with their own internal types). The scheduler's soft secondary objective now
also prefers placing those components on DIFFERENT days, so a subject is not
concentrated into one long block (e.g. 3h + 2h back to back on a single day).

The preference is strictly subordinate to everything that already existed:

  * the primary objective (maximize scheduled sessions) is frozen as a hard
    constraint before it is optimized, so it can never cost a session;
  * the stage-1 total daily span is frozen too, so it can never stretch a day;
  * it OUTRANKS the pre-existing transition-gap preference (a single solve
    minimizes `W * same_subject_days + gaps` with `W` larger than the largest
    possible number of gap terms), so a subject's meetings are spread across
    different days even when that costs a less tidy different-subject
    transition. The gap preference still refines whatever ties remain.

The cases mirror the requested A-L checklist: the preference is applied (A, C),
stays soft (D, E), changes nothing hard — contact hours, lunch, faculty
availability/load, lab rooms, published conflicts (F, G, H, I, J) — and never
trades away a session or expands the daily span (K, L).
"""

import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.append(str(Path(__file__).resolve().parent.parent / "solver"))

from ortools.sat.python import cp_model  # noqa: E402

import scheduler  # noqa: E402
from scheduler import _same_subject_day_terms, generate_schedule  # noqa: E402
from time_slots import hhmm_to_minutes  # noqa: E402


# ---------------------------------------------------------------------------
# Fixture builders (same shapes api/app.py builds)
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


def same_subject_pairs(result):
    """
    `(same_day, different_day)` counts over scheduled pairs of the SAME subject.

    This is exactly what the objective minimizes (the same-day half) and what the
    preference is supposed to buy (the different-day half); for a subject with two
    components the two counts are complementary.
    """
    placed = scheduled(result)
    same = different = 0
    for i in range(len(placed)):
        for j in range(i + 1, len(placed)):
            a, b = placed[i], placed[j]
            if a["subject_id"] != b["subject_id"]:
                continue
            if a["day_of_week"] == b["day_of_week"]:
                same += 1
            else:
                different += 1
    return same, different


def by_subject(result):
    groups = {}
    for s in scheduled(result):
        groups.setdefault(s["subject_id"], []).append(s)
    return groups


def bad_transitions(result):
    """
    Consecutive same-day transitions between DIFFERENT subjects that leave less
    than the preferred gap — the quantity the transition-gap preference
    minimizes.
    """
    placed = scheduled(result)
    by_day = {}
    for s in placed:
        by_day.setdefault(s["day_of_week"], []).append(s)
    count = 0
    for items in by_day.values():
        items.sort(key=lambda s: s["start_time"])
        for a, b in zip(items, items[1:]):
            if a["subject_id"] == b["subject_id"]:
                continue
            if hhmm_to_minutes(b["start_time"]) - hhmm_to_minutes(a["end_time"]) < 30:
                count += 1
    return count


def total_span(result):
    """Total section-day span in minutes: sum over days of last end - first start."""
    by_day = {}
    for s in scheduled(result):
        by_day.setdefault(s["day_of_week"], []).append(s)
    total = 0
    for items in by_day.values():
        items.sort(key=lambda s: s["start_time"])
        total += (hhmm_to_minutes(items[-1]["end_time"])
                  - hhmm_to_minutes(items[0]["start_time"]))
    return total


def generate_baseline(**kwargs):
    """
    The scheduler WITHOUT the same-subject-day preference — i.e. exactly the
    shipped behaviour before this change. With the terms removed the weighted
    objective degenerates to the old `-sum(gap_terms)`, so this is a faithful
    control.
    """
    with mock.patch.object(scheduler, "_same_subject_day_terms", lambda *a, **k: []):
        return generate_schedule(**kwargs)


def span_budget_for(**kwargs):
    """
    Run `generate_schedule` and return `(result, frozen_span)`, where
    `frozen_span` is the exact total daily span stage 1 used for THIS solve and
    the compactness guard therefore freezes stage 2 to.

    Capturing the budget from the same solve removes any dependence on a second,
    independently solved timetable agreeing with the first: the guard is checked
    against the very solve it constrained, so the assertion cannot flake when two
    equally optimal stage-1 arrangements differ.
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


# A reusable discriminating scenario: one subject with a 3h lecture and a 2h lab
# over two days. Stage 1 packs both onto day 1; the preference must split them.
def split_pair_kwargs(days=(1, 2)):
    return dict(
        section=make_section(start="07:00", end="17:00", days=days),
        subjects=[make_subject(1, "PROG101", lecture_hours=3, lab_hours=2,
                               lab_room_type="computer_lab")],
        faculty=[make_faculty(subject_ids=[1])],
        rooms=[make_room(room_id=1, room_type="lecture"),
               make_room(room_id=2, name="LAB1", room_type="computer_lab")],
    )


# ---------------------------------------------------------------------------
# The penalty function itself (pinned layouts, no search involved)
# ---------------------------------------------------------------------------

def _pairs_of(subject_a, subject_b, day_a, day_b, scheduled_flags=(1, 1),
              duration=120):
    """
    Sum `_same_subject_day_terms` for two sessions pinned to a known layout.

    Every variable is fixed except the penalty boolean, so the assertion is
    exact rather than dependent on how CP-SAT happens to search.
    """
    model = cp_model.CpModel()
    meta = [
        {"subject_id": subject_a, "session_type": "lecture",
         "duration": duration, "room_type": "lecture"},
        {"subject_id": subject_b, "session_type": "laboratory",
         "duration": duration, "room_type": "computer_lab"},
    ]
    session_vars = {}
    for idx, (day, is_sched) in enumerate(((day_a, scheduled_flags[0]),
                                           (day_b, scheduled_flags[1]))):
        session_vars[idx] = {
            "day": model.NewIntVarFromDomain(cp_model.Domain.FromValues([day]),
                                             f"s{idx}_day"),
            "start": model.NewConstant(420 + idx * duration),
            "room": model.NewConstant(0),
            "faculty": model.NewConstant(0),
            "is_scheduled": model.NewConstant(is_sched),
            "duration": duration,
            "meta": meta[idx],
        }

    terms = _same_subject_day_terms(model, session_vars, meta)
    if not terms:
        return 0
    solver = cp_model.CpSolver()
    solver.Solve(model)
    return sum(solver.Value(t) for t in terms)


class TestSameSubjectDayPenaltyFunction(unittest.TestCase):
    def test_same_subject_same_day_pair_is_penalized(self):
        self.assertEqual(_pairs_of(1, 1, 1, 1), 1)

    def test_same_subject_on_different_days_is_not_penalized(self):
        self.assertEqual(_pairs_of(1, 1, 1, 4), 0)

    def test_unscheduled_component_is_not_counted(self):
        self.assertEqual(_pairs_of(1, 1, 1, 1, scheduled_flags=(1, 0)), 0)
        self.assertEqual(_pairs_of(1, 1, 1, 1, scheduled_flags=(0, 0)), 0)

    def test_different_subjects_produce_no_terms(self):
        """The gap preference owns different-subject pairs, not this one."""
        self.assertEqual(_pairs_of(1, 2, 1, 1), 0)


# ---------------------------------------------------------------------------
# A + C. The preference is actually applied
# ---------------------------------------------------------------------------

class TestSameSubjectPreferenceIsApplied(unittest.TestCase):
    def test_components_move_to_different_days(self):
        """
        (A) A 3h lecture + 2h lab on two days. Stage 1 (and the baseline) put
        both on one day; the preference splits them, with the session count and
        the daily span untouched.
        """
        kwargs = split_pair_kwargs(days=(1, 2))
        baseline = generate_baseline(**kwargs)
        result, budget = span_budget_for(**kwargs)

        self.assertEqual(same_subject_pairs(baseline), (1, 0),
                         f"control should cluster the pair: {scheduled(baseline)}")
        self.assertEqual(same_subject_pairs(result), (0, 1),
                         f"components were not split across days: {scheduled(result)}")
        self.assertEqual(len(scheduled(result)), 2)
        # The split must not stretch a day past the span stage 1 used for the
        # SAME solve. Comparing against `total_span(baseline)` instead assumed
        # two independently solved timetables shared one arrangement.
        self.assertIsNotNone(budget, "the frozen-span guard stage did not run")
        self.assertLessEqual(total_span(result), budget)

    def test_components_split_across_five_days(self):
        kwargs = split_pair_kwargs(days=(1, 2, 3, 4, 5))
        baseline = generate_baseline(**kwargs)
        result = generate_schedule(**kwargs)

        self.assertEqual(same_subject_pairs(baseline), (1, 0))
        self.assertEqual(same_subject_pairs(result), (0, 1))
        self.assertEqual(len(scheduled(result)), 2)

    def test_already_separated_pairs_are_left_alone(self):
        """
        (B) Two subjects that are already spread over different days stay that
        way, and nothing else changes about the timetable: same count, same span,
        no new same-subject same-day pair.
        """
        kwargs = dict(
            section=make_section(start="07:00", end="17:00"),
            subjects=[make_subject(1, "X", lecture_hours=3, lab_hours=2,
                                   lab_room_type="computer_lab"),
                      make_subject(2, "Y", lecture_hours=3, lab_hours=2,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(subject_ids=[1, 2])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        )
        baseline = generate_baseline(**kwargs)
        result, budget = span_budget_for(**kwargs)

        self.assertEqual(same_subject_pairs(baseline), (0, 2),
                         f"control was expected to be already separated: {scheduled(baseline)}")
        self.assertEqual(same_subject_pairs(result), (0, 2),
                         f"an already-separated schedule was disturbed: {scheduled(result)}")
        self.assertEqual(len(scheduled(result)), len(scheduled(baseline)))
        self.assertIsNotNone(budget, "the frozen-span guard stage did not run")
        self.assertLessEqual(total_span(result), budget)

    def test_lecture_and_laboratory_prefer_different_days(self):
        """
        (C) The lecture and laboratory of one subject land on different days,
        while keeping their internal types (so the lab still needs a lab room).
        """
        result = generate_schedule(**split_pair_kwargs(days=(1, 2, 3, 4, 5)))

        placed = scheduled(result)
        self.assertEqual(len(placed), 2)
        types = {s["session_type"] for s in placed}
        self.assertEqual(types, {"lecture", "laboratory"})
        lecture = next(s for s in placed if s["session_type"] == "lecture")
        lab = next(s for s in placed if s["session_type"] == "laboratory")
        self.assertNotEqual(lecture["day_of_week"], lab["day_of_week"])


# ---------------------------------------------------------------------------
# D + E. The preference is soft
# ---------------------------------------------------------------------------

class TestSameSubjectPreferenceIsSoft(unittest.TestCase):
    def test_same_day_lecture_and_lab_is_allowed_when_separation_is_infeasible(self):
        """
        (D) A single-day section cannot separate the two components, so they stay
        on that day — and both are still scheduled. The preference must never
        drop a component to avoid the clustering.
        """
        result = generate_schedule(
            section=make_section(start="07:00", end="12:00", days=[1]),
            subjects=[make_subject(1, "PROG101", lecture_hours=2, lab_hours=2,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(subject_ids=[1])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        )

        self.assertEqual(len(scheduled(result)), 2)
        self.assertEqual(same_subject_pairs(result), (1, 0))
        self.assertEqual({s["day_of_week"] for s in scheduled(result)}, {1})

    def test_back_to_back_different_subjects_are_still_allowed(self):
        """
        (E) The preference is about the SAME subject only. Two different subjects
        filling a window back to back stay back to back; no 30-minute gap is
        manufactured between them.
        """
        result = generate_schedule(
            section=make_section(start="07:00", end="11:00", days=[1]),
            subjects=[make_subject(1, "AAA", lecture_hours=2),
                      make_subject(2, "BBB", lecture_hours=2)],
            faculty=[make_faculty(subject_ids=[1, 2])],
            rooms=[make_room()],
        )

        placed = sorted(scheduled(result), key=lambda s: s["start_time"])
        self.assertEqual(len(placed), 2)
        self.assertEqual({s["subject_id"] for s in placed}, {1, 2})
        self.assertEqual(placed[0]["end_time"], placed[1]["start_time"])
        self.assertEqual(same_subject_pairs(result), (0, 0))


# ---------------------------------------------------------------------------
# F, G, H, I, J. Nothing hard changed
# ---------------------------------------------------------------------------

class TestHardFactsUnchanged(unittest.TestCase):
    def test_contact_hours_are_unchanged(self):
        """
        (F) The preference moves classes between days; it never resizes them or
        merges a subject's 3h + 2h into a single 5h block.
        """
        result = generate_schedule(**split_pair_kwargs(days=(1, 2, 3, 4, 5)))

        durations = {(s["subject_id"], s["session_type"]):
                     hhmm_to_minutes(s["end_time"]) - hhmm_to_minutes(s["start_time"])
                     for s in scheduled(result)}
        self.assertEqual(durations, {(1, "lecture"): 180, (1, "laboratory"): 120})
        self.assertEqual(sum(durations.values()), 5 * 60)

    def test_lunch_break_stays_hard(self):
        """(G) No component is pushed into the 12:00-13:00 break."""
        lunch_start = hhmm_to_minutes("12:00")
        lunch_end = hhmm_to_minutes("13:00")
        result = generate_schedule(
            section=make_section(start="07:00", end="17:00", days=[1, 2],
                                 lunch={"enabled": True, "start": "12:00",
                                        "end": "13:00"}),
            subjects=[make_subject(1, "PROG101", lecture_hours=3, lab_hours=2,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(subject_ids=[1])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        )

        self.assertEqual(len(scheduled(result)), 2)
        for s in scheduled(result):
            start = hhmm_to_minutes(s["start_time"])
            end = hhmm_to_minutes(s["end_time"])
            self.assertFalse(start < lunch_end and end > lunch_start,
                             f"session overlaps the lunch break: {s}")

    def test_faculty_availability_still_binds_and_separation_respects_it(self):
        """
        (H) A faculty available only on days 1 and 2 forces the subject's two
        components onto those days — and the preference still splits them.
        """
        result = generate_schedule(
            section=make_section(start="07:00", end="17:00", days=[1, 2, 3, 4, 5]),
            subjects=[make_subject(1, "PROG101", lecture_hours=2, lab_hours=2,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(subject_ids=[1], available_days=[1, 2])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        )

        placed = scheduled(result)
        self.assertEqual(len(placed), 2)
        self.assertTrue({s["day_of_week"] for s in placed} <= {1, 2}, placed)
        self.assertEqual(same_subject_pairs(result), (0, 1))

    def test_teaching_load_cap_still_binds(self):
        """(H) A 3h cap still fits only one of the two 2h components."""
        result = generate_schedule(
            section=make_section(),
            subjects=[make_subject(1, "PROG101", lecture_hours=2, lab_hours=2,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(subject_ids=[1], max_teaching_load=3)],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        )

        self.assertEqual(len(scheduled(result)), 1)
        self.assertEqual(len(unscheduled(result)), 1)
        self.assertEqual(result["status"], "PARTIAL")

    def test_laboratory_still_requires_a_lab_room(self):
        """(I) The lab component still needs a room of its own type, and gets it."""
        kwargs = split_pair_kwargs(days=(1, 2))
        result = generate_schedule(**kwargs)

        lab = next(s for s in scheduled(result) if s["session_type"] == "laboratory")
        self.assertEqual(lab["room_id"], 2)

        # And a section with no matching lab room keeps the structural reason.
        missing = generate_schedule(
            section=make_section(),
            subjects=[make_subject(1, "PROG101", lecture_hours=2, lab_hours=3,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(subject_ids=[1])],
            rooms=[make_room(room_id=1, room_type="lecture", capacity=60)],
        )
        labs = [s for s in unscheduled(missing) if s["session_type"] == "laboratory"]
        self.assertEqual(len(labs), 1)
        self.assertIn("No room of type 'computer_lab' exists", labs[0]["reason"])

    def test_existing_committed_sessions_still_conflict(self):
        """
        (J) A published/approved session in another section is still a hard
        conflict: the new class avoids its faculty and room on that day/time.
        """
        existing = [{
            "day_of_week": 1,
            "start_minutes": hhmm_to_minutes("07:00"),
            "end_minutes": hhmm_to_minutes("09:00"),
            "faculty_id": 1,
            "room_id": 1,
        }]
        result = generate_schedule(
            section=make_section(start="07:00", end="12:00", days=[1, 2]),
            subjects=[make_subject(1, "PROG101", lecture_hours=2)],
            faculty=[make_faculty(subject_ids=[1])],
            rooms=[make_room(room_id=1)],
            existing_sessions=existing,
        )

        placed = scheduled(result)
        self.assertEqual(len(placed), 1)
        s = placed[0]
        if s["day_of_week"] == 1:
            overlaps = (hhmm_to_minutes(s["start_time"]) < existing[0]["end_minutes"]
                        and existing[0]["start_minutes"] < hhmm_to_minutes(s["end_time"]))
            self.assertFalse(overlaps, f"session overlaps a committed one: {s}")


# ---------------------------------------------------------------------------
# K + L. The primary objective and the daily span are preserved
# ---------------------------------------------------------------------------

def k_l_scenarios():
    return {
        "one subject 3h+2h, two days": split_pair_kwargs(days=(1, 2)),
        "one subject 3h+2h, five days": split_pair_kwargs(days=(1, 2, 3, 4, 5)),
        "two subjects each 3h+2h": dict(
            section=make_section(start="07:00", end="17:00"),
            subjects=[make_subject(1, "X", lecture_hours=3, lab_hours=2,
                                   lab_room_type="computer_lab"),
                      make_subject(2, "Y", lecture_hours=3, lab_hours=2,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(subject_ids=[1, 2])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        ),
        "single day, two components": dict(
            section=make_section(start="07:00", end="12:00", days=[1]),
            subjects=[make_subject(1, "X", lecture_hours=2, lab_hours=2,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(subject_ids=[1])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        ),
        "tight four days with a pair": dict(
            section=make_section(start="07:00", end="11:00", days=[1, 2, 3, 4]),
            subjects=[make_subject(1, "X", lecture_hours=2, lab_hours=2,
                                   lab_room_type="computer_lab"),
                      make_subject(2, "A", lecture_hours=2),
                      make_subject(3, "B", lecture_hours=2)],
            faculty=[make_faculty(subject_ids=[1, 2, 3])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        ),
        "with a lunch break": dict(
            section=make_section(start="07:00", end="17:00", days=[1, 2, 3],
                                 lunch={"enabled": True, "start": "12:00",
                                        "end": "13:00"}),
            subjects=[make_subject(1, "X", lecture_hours=3, lab_hours=2,
                                   lab_room_type="computer_lab"),
                      make_subject(2, "A", lecture_hours=2)],
            faculty=[make_faculty(subject_ids=[1, 2])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        ),
        "partial schedule under a cap": dict(
            section=make_section(),
            subjects=[make_subject(1, "X", lecture_hours=2, lab_hours=2,
                                   lab_room_type="computer_lab"),
                      make_subject(2, "Y", lecture_hours=2, lab_hours=2,
                                   lab_room_type="computer_lab")],
            faculty=[make_faculty(subject_ids=[1, 2], max_teaching_load=6)],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        ),
    }


class TestPriorityOrdering(unittest.TestCase):
    """
    The effective order is count > span > same-subject days > transition gaps.

    The scenario below is the smallest one where the last two disagree: three 2h
    sessions in a single 4h day, so exactly two fit and every layout has the same
    span. Keeping subject 1's lecture and lab together is gap-free but clusters
    one subject; splitting them costs a sub-threshold different-subject
    transition. The split must win.
    """

    def setUp(self):
        self.kwargs = dict(
            section=make_section(start="07:00", end="11:00", days=[1]),
            subjects=[make_subject(1, "PAIR", lecture_hours=2, lab_hours=2,
                                   lab_room_type="computer_lab"),
                      make_subject(2, "OTHER", lecture_hours=2)],
            faculty=[make_faculty(subject_ids=[1, 2])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        )

    def test_distribution_beats_the_gap_score(self):
        """(F) Equal count, equal span, worse gap score -> still split."""
        result, budget = span_budget_for(**self.kwargs)
        baseline = generate_baseline(**self.kwargs)

        self.assertEqual(len(scheduled(result)), 2)
        self.assertEqual(len(scheduled(result)), len(scheduled(baseline)))
        self.assertIsNotNone(budget, "the frozen-span guard stage did not run")
        self.assertLessEqual(total_span(result), budget)
        # The single-day window is exactly 4h and the two 2h sessions fill it, so
        # this span is forced by the constraints, not by the solver's choice.
        self.assertEqual(total_span(result), 240)
        # The shipped preference is what flips the outcome: without the
        # same-subject terms the gap preference keeps subject 1's cluster.
        self.assertEqual(same_subject_pairs(baseline), (1, 0))
        self.assertEqual(same_subject_pairs(result), (0, 0))
        self.assertEqual(bad_transitions(result), 1)

    def test_same_subject_terms_win_the_plateau_not_the_count(self):
        """(M) The count is identical, so nothing was sacrificed for the split."""
        result = generate_schedule(**self.kwargs)
        self.assertEqual(result["status"], "PARTIAL")
        self.assertEqual(len(scheduled(result)), 2)
        self.assertEqual(len(unscheduled(result)), 1)


class TestGapPreferenceStillWorks(unittest.TestCase):
    def test_gap_preference_operates_inside_the_distribution_plateau(self):
        """
        (O) Subordinating the gap preference does not disable it. With enough
        freedom the solver both separates the subject's components AND removes
        every sub-threshold different-subject transition, so the gap score is
        still optimized among the timetables the distribution preference allows.
        """
        result = generate_schedule(
            section=make_section(start="07:00", end="17:00"),
            subjects=[make_subject(1, "X", lecture_hours=2, lab_hours=2,
                                   lab_room_type="computer_lab"),
                      make_subject(2, "A", lecture_hours=2),
                      make_subject(3, "B", lecture_hours=2),
                      make_subject(4, "C", lecture_hours=2)],
            faculty=[make_faculty(subject_ids=[1, 2, 3, 4])],
            rooms=[make_room(room_id=1, room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        )

        self.assertEqual(len(scheduled(result)), 5)
        self.assertEqual(same_subject_pairs(result), (0, 1))
        self.assertEqual(bad_transitions(result), 0)


class TestPrimaryObjectiveAndSpanArePreserved(unittest.TestCase):
    def test_preference_never_reduces_the_maximum_session_count(self):
        """
        (K) Across a spread of shapes, the preference schedules exactly as many
        sessions as the baseline: it may never sacrifice a session for a tidier
        distribution.
        """
        for label, kwargs in k_l_scenarios().items():
            with self.subTest(scenario=label):
                baseline = generate_baseline(**kwargs)
                result = generate_schedule(**kwargs)
                self.assertEqual(
                    len(scheduled(result)), len(scheduled(baseline)),
                    f"{label}: preference cost a session",
                )
                self.assertEqual(len(unscheduled(result)), len(unscheduled(baseline)))

    def test_preference_does_not_expand_the_daily_span(self):
        """
        (L) The stage-1 total daily span stays frozen, so the preference can
        never spread a section's days to separate a subject's components.
        """
        for label, kwargs in k_l_scenarios().items():
            with self.subTest(scenario=label):
                result, budget = span_budget_for(**kwargs)
                self.assertIsNotNone(budget, f"{label}: guard stage did not run")
                self.assertLessEqual(total_span(result), budget,
                                     f"{label}: preference expanded the daily span")

    def test_preference_improves_distribution_without_hurting_anything(self):
        """
        A direct end-to-end check of the requested outcome on the discriminating
        scenario: fewer same-subject same-day pairs than the baseline, the same
        count, the same span.
        """
        kwargs = split_pair_kwargs(days=(1, 2, 3, 4, 5))
        baseline = generate_baseline(**kwargs)
        result, budget = span_budget_for(**kwargs)

        self.assertEqual(same_subject_pairs(baseline)[0], 1)
        self.assertEqual(same_subject_pairs(result)[0], 0)
        self.assertEqual(len(scheduled(result)), len(scheduled(baseline)))
        self.assertIsNotNone(budget, "the frozen-span guard stage did not run")
        self.assertLessEqual(total_span(result), budget)


if __name__ == "__main__":
    unittest.main()
