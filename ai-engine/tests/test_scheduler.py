"""
Real, deterministic tests for the FastAPI + OR-Tools CP-SAT scheduler.

These tests exercise ai-engine/solver/scheduler.py::generate_schedule directly
as a pure function (no database, no HTTP). Every fixture is controlled data
matching the exact structures the FastAPI layer (api/app.py) builds before
calling the solver:

  section:  {id, name, preferred_days: [int], preferred_start_hour: int,
             preferred_end_hour: int, student_count: int}
  subjects: [{id, code, lecture_hours, lab_hours, lab_room_type}]
  faculty:  [{id, name, can_teach_subject_ids: [int], available_days: [int],
              max_teaching_load: int, existing_load_hours: float}]
  rooms:    [{id, name, type, capacity}]
  existing_sessions (optional):
            [{day_of_week, start_hour, end_hour, faculty_id, room_id}]

Covers the behaviors the project documents: valid scheduling, faculty
qualification, day-level availability, room type matching, room capacity,
faculty/room no-double-booking, max teaching load, cross-section conflicts,
preferred scheduling window, and partial/infeasible/ERROR outcomes.
"""

import sys
import unittest
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parent.parent / "solver"))

from scheduler import generate_schedule  # noqa: E402


# ---------------------------------------------------------------------------
# Fixture builders (controlled, deterministic data - mirrors api/app.py shapes)
# ---------------------------------------------------------------------------

def make_section(**overrides):
    base = {
        "id": 1,
        "name": "BSIT 1A",
        "preferred_days": [1, 2, 3, 4, 5],
        "preferred_start_hour": 7,
        "preferred_end_hour": 21,
        "student_count": 30,
    }
    base.update(overrides)
    return base


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
        "max_teaching_load": max_teaching_load,
        "existing_load_hours": existing_load_hours,
    }


def make_room(room_id=1, name="R101", room_type="lecture", capacity=40):
    return {"id": room_id, "name": name, "type": room_type, "capacity": capacity}


def scheduled(result):
    return [s for s in result["sessions"] if s["is_scheduled"]]


def unscheduled(result):
    return [s for s in result["sessions"] if not s["is_scheduled"]]


def by_type(result, session_type):
    return [s for s in result["sessions"] if s["session_type"] == session_type]


# ---------------------------------------------------------------------------
# A. Valid scheduling
# ---------------------------------------------------------------------------

class TestValidScheduling(unittest.TestCase):
    def test_valid_problem_schedules_all_sessions_and_reports_optimal(self):
        """A well-provisioned problem schedules every session with full details."""
        subject = make_subject(lecture_hours=2, lab_hours=3, lab_room_type="computer_lab")
        result = generate_schedule(
            section=make_section(),
            subjects=[subject],
            faculty=[make_faculty(subject_ids=[subject["id"]])],
            rooms=[make_room(room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        )

        assert result["status"] == "OPTIMAL", result
        assert result["message"] is None
        assert len(scheduled(result)) == 2  # one lecture + one lab
        assert not unscheduled(result)

        for s in scheduled(result):
            assert {"subject_id", "session_type", "day_of_week", "start_hour",
                    "end_hour", "room_id", "faculty_id"} <= set(s.keys())
            assert s["end_hour"] - s["start_hour"] > 0
            assert s["faculty_id"] == 1  # only one faculty exists

    def test_lecture_and_lab_sessions_are_both_created(self):
        """A subject with both lecture and lab hours yields both session types."""
        subject = make_subject(lecture_hours=2, lab_hours=2, lab_room_type="computer_lab")
        result = generate_schedule(
            section=make_section(),
            subjects=[subject],
            faculty=[make_faculty(subject_ids=[subject["id"]])],
            rooms=[make_room(room_type="lecture"),
                   make_room(room_id=2, name="LAB1", room_type="computer_lab")],
        )

        assert result["status"] == "OPTIMAL"
        lecture = by_type(result, "lecture")
        lab = by_type(result, "laboratory")
        assert len(lecture) == 1 and lecture[0]["is_scheduled"]
        assert len(lab) == 1 and lab[0]["is_scheduled"]

    def test_subject_without_hours_is_skipped(self):
        """A subject with zero hours produces no sessions at all -> ERROR."""
        subject = make_subject(lecture_hours=0, lab_hours=0)
        result = generate_schedule(
            section=make_section(),
            subjects=[subject],
            faculty=[make_faculty(subject_ids=[subject["id"]])],
            rooms=[make_room()],
        )

        assert result["status"] == "ERROR"
        assert result["sessions"] == []

    def test_full_schedule_status_is_optimal_or_feasible(self):
        """Any fully-placed schedule must report OPTIMAL or FEASIBLE."""
        subject = make_subject(lecture_hours=1)
        result = generate_schedule(
            section=make_section(),
            subjects=[subject],
            faculty=[make_faculty(subject_ids=[subject["id"]])],
            rooms=[make_room()],
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        assert len(scheduled(result)) == 1


# ---------------------------------------------------------------------------
# B. Faculty qualification
# ---------------------------------------------------------------------------

class TestFacultyQualification(unittest.TestCase):
    def test_unqualified_faculty_is_never_assigned(self):
        """Two faculty: only F2 is qualified. F1 must never be assigned."""
        subject = make_subject(subject_id=1, lecture_hours=2)
        faculty = [
            make_faculty(faculty_id=1, name="Unqualified", subject_ids=[999]),
            make_faculty(faculty_id=2, name="Qualified", subject_ids=[1]),
        ]
        result = generate_schedule(
            section=make_section(), subjects=[subject], faculty=faculty,
            rooms=[make_room()],
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        assigned = {s["faculty_id"] for s in scheduled(result)}
        assert 1 not in assigned, "Unqualified faculty was assigned!"
        assert assigned == {2}

    def test_no_qualified_faculty_leaves_session_unscheduled_with_reason(self):
        """If nobody can teach the subject, the session is unschedulable."""
        subject = make_subject(subject_id=1, lecture_hours=2)
        faculty = [make_faculty(faculty_id=1, subject_ids=[999])]
        result = generate_schedule(
            section=make_section(), subjects=[subject], faculty=faculty,
            rooms=[make_room()],
        )

        assert result["status"] == "INFEASIBLE"
        uns = unscheduled(result)
        assert len(uns) == 1
        assert "faculty" in uns[0]["reason"].lower()


# ---------------------------------------------------------------------------
# C. Faculty day-level availability
# ---------------------------------------------------------------------------

class TestFacultyAvailability(unittest.TestCase):
    def test_faculty_only_scheduled_on_available_days(self):
        """Faculty available Mon/Wed/Fri only: no session on another day."""
        subject = make_subject(subject_id=1, lecture_hours=1)
        faculty = [make_faculty(faculty_id=1, subject_ids=[1], available_days=[1, 3, 5])]
        result = generate_schedule(
            section=make_section(), subjects=[subject], faculty=faculty,
            rooms=[make_room()],
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        for s in scheduled(result):
            assert s["day_of_week"] in (1, 3, 5), \
                f"Assigned on unavailable day {s['day_of_week']}"

    def test_faculty_lands_on_the_only_available_day(self):
        """Section allows two days, faculty only one: session lands on that day."""
        subject = make_subject(subject_id=1, lecture_hours=1)
        faculty = [make_faculty(faculty_id=1, subject_ids=[1], available_days=[2])]
        section = make_section(preferred_days=[2, 4])
        result = generate_schedule(
            section=section, subjects=[subject], faculty=faculty,
            rooms=[make_room()],
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        assert all(s["day_of_week"] == 2 for s in scheduled(result))

    def test_faculty_unavailable_on_all_section_days_yields_unscheduled(self):
        """Faculty only free Saturday; section is Mon-Fri: cannot be placed."""
        subject = make_subject(subject_id=1, lecture_hours=1)
        faculty = [make_faculty(faculty_id=1, subject_ids=[1], available_days=[6])]
        section = make_section(preferred_days=[1, 2, 3, 4, 5])
        result = generate_schedule(
            section=section, subjects=[subject], faculty=faculty,
            rooms=[make_room()],
        )

        assert result["status"] == "INFEASIBLE"
        assert len(scheduled(result)) == 0


# ---------------------------------------------------------------------------
# D. Room type matching
# ---------------------------------------------------------------------------

class TestRoomTypeMatching(unittest.TestCase):
    def test_lab_session_requires_matching_lab_room(self):
        """Lab session must use the computer lab; lecture session the lecture room."""
        subject = make_subject(subject_id=1, lecture_hours=2, lab_hours=2,
                               lab_room_type="computer_lab")
        rooms = [
            make_room(room_id=1, name="R101", room_type="lecture"),
            make_room(room_id=2, name="LAB1", room_type="computer_lab"),
        ]
        result = generate_schedule(
            section=make_section(), subjects=[subject],
            faculty=[make_faculty(subject_ids=[1])], rooms=rooms,
        )

        assert result["status"] == "OPTIMAL"
        lab = by_type(result, "laboratory")[0]
        lecture = by_type(result, "lecture")[0]
        assert lab["room_id"] == 2
        assert lecture["room_id"] == 1

    def test_missing_lab_room_makes_lab_session_unschedulable_with_reason(self):
        """No computer lab exists -> the lab session is structurally unschedulable."""
        subject = make_subject(subject_id=1, lecture_hours=0, lab_hours=2,
                               lab_room_type="computer_lab")
        rooms = [make_room(room_id=1, name="R101", room_type="lecture")]
        result = generate_schedule(
            section=make_section(), subjects=[subject],
            faculty=[make_faculty(subject_ids=[1])], rooms=rooms,
        )

        assert result["status"] == "INFEASIBLE"
        uns = unscheduled(result)
        assert len(uns) == 1
        assert "room" in uns[0]["reason"].lower()

    def test_wrong_room_type_cannot_host_lab_session(self):
        """Only a lecture room exists but a science lab is required: no fallback."""
        subject = make_subject(subject_id=1, lecture_hours=0, lab_hours=2,
                               lab_room_type="science_lab")
        rooms = [make_room(room_id=1, name="R101", room_type="lecture")]
        result = generate_schedule(
            section=make_section(), subjects=[subject],
            faculty=[make_faculty(subject_ids=[1])], rooms=rooms,
        )

        assert result["status"] == "INFEASIBLE"
        assert all(not s["is_scheduled"] for s in result["sessions"])


# ---------------------------------------------------------------------------
# E. Room capacity
# ---------------------------------------------------------------------------

class TestRoomCapacity(unittest.TestCase):
    def test_room_below_section_capacity_is_not_selected(self):
        """A 50-student section must get the 60-cap room, not the 30-cap one."""
        subject = make_subject(subject_id=1, lecture_hours=2)
        rooms = [
            make_room(room_id=1, name="SMALL", room_type="lecture", capacity=30),
            make_room(room_id=2, name="BIG", room_type="lecture", capacity=60),
        ]
        section = make_section(student_count=50)
        result = generate_schedule(
            section=section, subjects=[subject],
            faculty=[make_faculty(subject_ids=[1])], rooms=rooms,
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        assert {s["room_id"] for s in scheduled(result)} == {2}, \
            "Solver selected a room below the section's student count!"

    def test_no_room_big_enough_leaves_session_unschedulable(self):
        """All rooms too small for a 60-student section -> unschedulable."""
        subject = make_subject(subject_id=1, lecture_hours=2)
        rooms = [
            make_room(room_id=1, name="A", room_type="lecture", capacity=40),
            make_room(room_id=2, name="B", room_type="lecture", capacity=50),
        ]
        section = make_section(student_count=60)
        result = generate_schedule(
            section=section, subjects=[subject],
            faculty=[make_faculty(subject_ids=[1])], rooms=rooms,
        )

        assert result["status"] == "INFEASIBLE"
        assert len(scheduled(result)) == 0
        assert "room" in unscheduled(result)[0]["reason"].lower()


# ---------------------------------------------------------------------------
# F. Faculty no-double-booking
# ---------------------------------------------------------------------------

class TestFacultyNoDoubleBooking(unittest.TestCase):
    def test_single_faculty_sessions_never_overlap(self):
        """One faculty teaching three 2-hour sessions: no two overlap on a day."""
        subjects = [
            make_subject(subject_id=i, code=f"S{i}", lecture_hours=2)
            for i in (1, 2, 3)
        ]
        faculty = [make_faculty(faculty_id=1, subject_ids=[1, 2, 3])]
        rooms = [make_room(room_id=1), make_room(room_id=2, name="R102")]
        result = generate_schedule(
            section=make_section(), subjects=subjects, faculty=faculty, rooms=rooms,
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        sched = scheduled(result)
        assert len(sched) == 3

        for i in range(len(sched)):
            for j in range(i + 1, len(sched)):
                a, b = sched[i], sched[j]
                if a["day_of_week"] == b["day_of_week"]:
                    assert a["end_hour"] <= b["start_hour"] or b["end_hour"] <= a["start_hour"], \
                        f"Faculty double-booked: {a} vs {b}"

    def test_two_sessions_for_one_faculty_do_not_overlap(self):
        """Two subjects, one faculty: same-day placements must not overlap."""
        subjects = [
            make_subject(subject_id=1, code="A", lecture_hours=3),
            make_subject(subject_id=2, code="B", lecture_hours=2),
        ]
        faculty = [make_faculty(faculty_id=1, subject_ids=[1, 2], max_teaching_load=10)]
        result = generate_schedule(
            section=make_section(), subjects=subjects, faculty=faculty,
            rooms=[make_room(room_id=1), make_room(room_id=2, name="R102")],
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        sched = scheduled(result)
        assert len(sched) == 2
        a, b = sched
        if a["day_of_week"] == b["day_of_week"]:
            assert a["end_hour"] <= b["start_hour"] or b["end_hour"] <= a["start_hour"]


# ---------------------------------------------------------------------------
# G. Room no-double-booking
# ---------------------------------------------------------------------------

class TestRoomNoDoubleBooking(unittest.TestCase):
    def test_same_room_never_hosts_overlapping_sessions(self):
        """Two sessions, one room, different faculty: no overlapping use of the room."""
        subjects = [
            make_subject(subject_id=1, code="A", lecture_hours=3),
            make_subject(subject_id=2, code="B", lecture_hours=3),
        ]
        result = generate_schedule(
            section=make_section(),
            subjects=subjects,
            faculty=[
                make_faculty(faculty_id=1, subject_ids=[1]),
                make_faculty(faculty_id=2, subject_ids=[2]),
            ],
            rooms=[make_room(room_id=1)],
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        sched = scheduled(result)
        assert len(sched) == 2
        a, b = sched
        if a["room_id"] == b["room_id"] and a["day_of_week"] == b["day_of_week"]:
            assert a["end_hour"] <= b["start_hour"] or b["end_hour"] <= a["start_hour"], \
                f"Room double-booked: {a} vs {b}"


# ---------------------------------------------------------------------------
# H. Maximum teaching load
# ---------------------------------------------------------------------------

class TestMaxTeachingLoad(unittest.TestCase):
    def test_total_assigned_hours_respect_max_teaching_load(self):
        """Max load 4h: two 3h subjects -> only 3h can be assigned, rest unscheduled."""
        subjects = [
            make_subject(subject_id=1, code="A", lecture_hours=3),
            make_subject(subject_id=2, code="B", lecture_hours=3),
        ]
        faculty = [make_faculty(faculty_id=1, subject_ids=[1, 2], max_teaching_load=4)]
        rooms = [make_room(room_id=1), make_room(room_id=2, name="R102")]
        result = generate_schedule(
            section=make_section(), subjects=subjects, faculty=faculty, rooms=rooms,
        )

        total_hours = sum(s["end_hour"] - s["start_hour"] for s in scheduled(result))
        assert total_hours <= 4, f"Assigned {total_hours}h to a faculty with max 4h"

        assert result["status"] == "PARTIAL"
        assert len(unscheduled(result)) == 1

    def test_existing_load_blocks_session_exceeding_remaining_capacity(self):
        """22h existing of 24h max: a 3h new session exceeds remaining 2h -> blocked."""
        subject = make_subject(subject_id=1, code="A", lecture_hours=3)
        faculty = [make_faculty(faculty_id=1, subject_ids=[1], max_teaching_load=24,
                                existing_load_hours=22)]
        result = generate_schedule(
            section=make_section(), subjects=[subject], faculty=faculty,
            rooms=[make_room()],
        )

        assert result["status"] == "INFEASIBLE"
        assert len(scheduled(result)) == 0

    def test_session_fitting_remaining_capacity_is_scheduled(self):
        """22h existing of 24h max: a 2h session fits remaining capacity."""
        subject = make_subject(subject_id=1, code="A", lecture_hours=2)
        faculty = [make_faculty(faculty_id=1, subject_ids=[1], max_teaching_load=24,
                                existing_load_hours=22)]
        result = generate_schedule(
            section=make_section(), subjects=[subject], faculty=faculty,
            rooms=[make_room()],
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        assert len(scheduled(result)) == 1

    def test_zero_remaining_capacity_faculty_never_scheduled(self):
        """Faculty with existing load == max load cannot take anything."""
        subject = make_subject(subject_id=1, code="A", lecture_hours=2)
        faculty = [make_faculty(faculty_id=1, subject_ids=[1], max_teaching_load=10,
                                existing_load_hours=10)]
        result = generate_schedule(
            section=make_section(), subjects=[subject], faculty=faculty,
            rooms=[make_room()],
        )

        assert result["status"] == "INFEASIBLE"
        assert len(scheduled(result)) == 0


# ---------------------------------------------------------------------------
# I. Cross-section conflicts (existing committed sessions)
# ---------------------------------------------------------------------------

class TestCrossSectionConflicts(unittest.TestCase):
    def test_faculty_conflict_with_existing_session_forces_alternative(self):
        """Faculty busy Tue 10-12 elsewhere: new session must avoid that block."""
        subject = make_subject(subject_id=1, code="A", lecture_hours=2)
        faculty = [make_faculty(faculty_id=1, subject_ids=[1])]
        rooms = [make_room(room_id=1), make_room(room_id=2, name="R102")]
        existing = [{"day_of_week": 2, "start_hour": 10, "end_hour": 12,
                     "faculty_id": 1, "room_id": 1}]
        result = generate_schedule(
            section=make_section(), subjects=[subject], faculty=faculty,
            rooms=rooms, existing_sessions=existing,
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        for s in scheduled(result):
            if s["day_of_week"] == 2:
                assert s["end_hour"] <= 10 or s["start_hour"] >= 12, \
                    f"Faculty conflict with committed session: {s}"

    def test_room_conflict_with_existing_session_forces_alternative(self):
        """Room 1 occupied Wed 8-11 elsewhere: new session must not overlap in it."""
        subject = make_subject(subject_id=1, code="A", lecture_hours=3)
        faculty = [make_faculty(faculty_id=1, subject_ids=[1])]
        rooms = [make_room(room_id=1), make_room(room_id=2, name="R102")]
        existing = [{"day_of_week": 3, "start_hour": 8, "end_hour": 11,
                     "faculty_id": 999, "room_id": 1}]
        result = generate_schedule(
            section=make_section(), subjects=[subject], faculty=faculty,
            rooms=rooms, existing_sessions=existing,
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        for s in scheduled(result):
            if s["room_id"] == 1 and s["day_of_week"] == 3:
                assert s["end_hour"] <= 8 or s["start_hour"] >= 11, \
                    f"Room conflict with committed session: {s}"

    def test_blocked_everywhere_by_existing_sessions_leaves_unscheduled(self):
        """Faculty booked all day, every section day: the session can't fit."""
        subject = make_subject(subject_id=1, code="A", lecture_hours=2)
        faculty = [make_faculty(faculty_id=1, subject_ids=[1])]
        rooms = [make_room(room_id=1), make_room(room_id=2, name="R102")]
        existing = [
            {"day_of_week": d, "start_hour": 7, "end_hour": 21,
             "faculty_id": 1, "room_id": 1}
            for d in (1, 2, 3, 4, 5)
        ]
        result = generate_schedule(
            section=make_section(), subjects=[subject], faculty=faculty,
            rooms=rooms, existing_sessions=existing,
        )

        assert result["status"] in ("PARTIAL", "INFEASIBLE")
        assert len(scheduled(result)) == 0

    def test_unrelated_existing_session_does_not_block(self):
        """A committed session for other faculty AND another room must not constrain."""
        subject = make_subject(subject_id=1, code="A", lecture_hours=2)
        faculty = [make_faculty(faculty_id=1, subject_ids=[1])]
        rooms = [make_room(room_id=1)]
        existing = [{"day_of_week": 2, "start_hour": 10, "end_hour": 12,
                     "faculty_id": 42, "room_id": 99}]
        result = generate_schedule(
            section=make_section(), subjects=[subject], faculty=faculty,
            rooms=rooms, existing_sessions=existing,
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        assert len(scheduled(result)) == 1


# ---------------------------------------------------------------------------
# J. Section preferred scheduling window
# ---------------------------------------------------------------------------

class TestPreferredWindow(unittest.TestCase):
    def test_all_sessions_within_preferred_window(self):
        """Every session starts and ends inside the 9:00-17:00 window."""
        subject = make_subject(subject_id=1, code="A", lecture_hours=2)
        section = make_section(preferred_start_hour=9, preferred_end_hour=17)
        result = generate_schedule(
            section=section, subjects=[subject],
            faculty=[make_faculty(subject_ids=[1])],
            rooms=[make_room()],
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        for s in scheduled(result):
            assert s["start_hour"] >= 9, f"Session starts before window: {s}"
            assert s["end_hour"] <= 17, f"Session ends after window: {s}"

    def test_sessions_on_preferred_days_only(self):
        """Section prefers Mon/Wed only: no session lands on other days."""
        subject = make_subject(subject_id=1, code="A", lecture_hours=1)
        section = make_section(preferred_days=[1, 3])
        result = generate_schedule(
            section=section, subjects=[subject],
            faculty=[make_faculty(subject_ids=[1], available_days=[1, 2, 3, 4, 5])],
            rooms=[make_room()],
        )

        assert result["status"] in ("OPTIMAL", "FEASIBLE")
        assert all(s["day_of_week"] in (1, 3) for s in scheduled(result))

    def test_session_longer_than_window_is_unschedulable(self):
        """A 5-hour session cannot fit a 7:00-10:00 (3h) window."""
        subject = make_subject(subject_id=1, code="A", lecture_hours=5)
        section = make_section(preferred_days=[1, 2, 3, 4, 5],
                               preferred_start_hour=7, preferred_end_hour=10)
        result = generate_schedule(
            section=section, subjects=[subject],
            faculty=[make_faculty(subject_ids=[1])],
            rooms=[make_room()],
        )

        assert result["status"] == "INFEASIBLE"
        uns = unscheduled(result)
        assert len(uns) == 1
        assert "window" in uns[0]["reason"].lower()


# ---------------------------------------------------------------------------
# K. Unscheduled / infeasible / partial behavior
# ---------------------------------------------------------------------------

class TestUnschedulableBehavior(unittest.TestCase):
    def test_mixed_feasibility_yields_partial_with_reasons(self):
        """One schedulable subject + one with no lab room: PARTIAL with reasons."""
        good = make_subject(subject_id=1, code="GOOD", lecture_hours=2)
        bad = make_subject(subject_id=2, code="BAD", lecture_hours=0,
                           lab_hours=2, lab_room_type="science_lab")
        rooms = [make_room(room_id=1, name="R101", room_type="lecture")]
        faculty = [make_faculty(faculty_id=1, subject_ids=[1, 2])]
        result = generate_schedule(
            section=make_section(), subjects=[good, bad], faculty=faculty,
            rooms=rooms,
        )

        assert result["status"] == "PARTIAL"
        assert result["message"] is not None
        good_lecture = [s for s in result["sessions"]
                        if s["subject_id"] == 1 and s["session_type"] == "lecture"][0]
        bad_lab = [s for s in result["sessions"]
                   if s["subject_id"] == 2 and s["session_type"] == "laboratory"][0]
        assert good_lecture["is_scheduled"] is True
        assert bad_lab["is_scheduled"] is False
        assert bad_lab.get("reason")

    def test_everything_blocked_yields_infeasible_with_reasons(self):
        """Two structurally impossible subjects: INFEASIBLE, both explained."""
        s1 = make_subject(subject_id=1, code="A", lecture_hours=0, lab_hours=2,
                          lab_room_type="science_lab")
        s2 = make_subject(subject_id=2, code="B", lecture_hours=2)
        rooms = [make_room(room_id=1, name="R101", room_type="lecture")]
        # Faculty is qualified for nothing teachable here: s1 has no lab room,
        # and s2's lecture has no qualified faculty.
        faculty = [make_faculty(faculty_id=1, subject_ids=[3])]
        result = generate_schedule(
            section=make_section(), subjects=[s1, s2], faculty=faculty,
            rooms=rooms,
        )

        assert result["status"] == "INFEASIBLE"
        assert len(scheduled(result)) == 0
        reasons = [s.get("reason", "") for s in result["sessions"]]
        assert any("room" in r.lower() for r in reasons)
        assert any("faculty" in r.lower() for r in reasons)

    def test_unscheduled_entries_carry_a_nonempty_reason(self):
        """Every unscheduled session must explain itself."""
        subject = make_subject(subject_id=1, code="A", lecture_hours=2,
                               lab_hours=2, lab_room_type="computer_lab")
        rooms = [make_room(room_id=1, name="R101", room_type="lecture")]
        faculty = [make_faculty(faculty_id=1, subject_ids=[1])]
        result = generate_schedule(
            section=make_section(), subjects=[subject], faculty=faculty,
            rooms=rooms,
        )

        for s in unscheduled(result):
            assert isinstance(s.get("reason"), str) and s["reason"].strip(), \
                f"Unscheduled session without a reason: {s}"

    def test_result_shape_is_stable(self):
        """Whatever the outcome, the top-level result keys are always the same."""
        subject = make_subject(subject_id=1, code="A", lecture_hours=2)
        ok = generate_schedule(
            section=make_section(), subjects=[subject],
            faculty=[make_faculty(subject_ids=[1])], rooms=[make_room()],
        )
        bad = generate_schedule(
            section=make_section(), subjects=[subject],
            faculty=[make_faculty(subject_ids=[999])], rooms=[make_room()],
        )
        for res in (ok, bad):
            assert set(res.keys()) == {"status", "message", "sessions"}
            assert isinstance(res["sessions"], list)
            for s in res["sessions"]:
                assert "subject_id" in s and "session_type" in s and "is_scheduled" in s
