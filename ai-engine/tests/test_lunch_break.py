"""
Tests for the half-hour time model and the midday break.

Covers the behaviour added when the solver moved from whole hours to minutes:
a section window of 07:30-20:30 is now honoured to the minute, and a configured
12:00-1:00 PM break is a hard constraint rather than something the solver may
schedule straight through.
"""

import sys
import unittest
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parent.parent / "solver"))

from scheduler import generate_schedule  # noqa: E402
from time_slots import (  # noqa: E402
    allowed_starts,
    exclude_break,
    hhmm_to_minutes,
    minutes_to_hhmm,
)


def subject(hours=2):
    return {"id": 1, "code": "PROG1", "lecture_hours": hours,
            "lab_hours": 0, "lab_room_type": None}


def faculty():
    return [{"id": 1, "name": "Prof A", "can_teach_subject_ids": [1],
             "available_days": [1, 2, 3, 4, 5], "available_windows": [],
             "max_teaching_load": 24, "existing_load_hours": 0}]


def rooms():
    return [{"id": 1, "name": "R101", "type": "lecture", "capacity": 40}]


def section(start="07:30", end="20:30", lunch=None):
    data = {
        "id": 1, "name": "BSIT 1A", "preferred_days": [1],
        "preferred_start_minutes": hhmm_to_minutes(start),
        "preferred_end_minutes": hhmm_to_minutes(end),
        "student_count": 30,
    }
    if lunch is not None:
        data["lunch"] = lunch
    return data


def placed(result):
    return [s for s in result["sessions"] if s["is_scheduled"]]


class TestTimeSlots(unittest.TestCase):
    def test_half_hour_is_kept(self):
        """The old parse_hour() truncated 07:30 to 7; minutes must survive."""
        self.assertEqual(hhmm_to_minutes("07:30"), 450)
        self.assertEqual(hhmm_to_minutes("20:30"), 1230)
        self.assertEqual(hhmm_to_minutes("12:00:00"), 720)
        self.assertEqual(minutes_to_hhmm(1230), "20:30")

    def test_exclude_break_drops_colliding_starts(self):
        starts = allowed_starts(450, 1230, 30)
        kept = exclude_break(starts, 120, 720, 780)
        self.assertNotIn(720, kept)   # 12:00-14:00 spans the break
        self.assertNotIn(660, kept)   # 11:00-13:00 spans it too
        self.assertIn(600, kept)      # 10:00-12:00 ends exactly at noon
        self.assertIn(780, kept)      # 13:00-15:00 starts when it ends


class TestHalfHourWindows(unittest.TestCase):
    def test_window_starting_at_half_past_seven(self):
        """07:30-09:30 with a 2h lecture can only start at 07:30."""
        result = generate_schedule(section("07:30", "09:30"), [subject()], faculty(), rooms())
        self.assertTrue(placed(result), result)
        self.assertEqual(placed(result)[0]["start_time"], "07:30")
        self.assertEqual(placed(result)[0]["end_time"], "09:30")

    def test_window_ending_at_half_past_eight(self):
        """18:30-20:30 with a 2h lecture ends at 20:30, not 20:00."""
        result = generate_schedule(section("18:30", "20:30"), [subject()], faculty(), rooms())
        self.assertTrue(placed(result), result)
        self.assertEqual(placed(result)[0]["start_time"], "18:30")
        self.assertEqual(placed(result)[0]["end_time"], "20:30")


class TestLunchBreak(unittest.TestCase):
    def test_session_cannot_span_the_break(self):
        """A window that only fits across noon is refused, with a clear reason."""
        lunch = {"enabled": True, "start": "12:00", "end": "13:00"}
        result = generate_schedule(section("11:30", "13:30", lunch), [subject()], faculty(), rooms())
        self.assertFalse(placed(result), result)
        reason = result["sessions"][0].get("reason", "")
        self.assertIn("break", reason.lower())

    def test_break_can_be_disabled(self):
        """With the break off, the same window schedules straight through noon."""
        lunch = {"enabled": False, "start": "12:00", "end": "13:00"}
        result = generate_schedule(section("11:30", "13:30", lunch), [subject()], faculty(), rooms())
        self.assertTrue(placed(result), result)
        self.assertEqual(placed(result)[0]["start_time"], "11:30")
        self.assertEqual(placed(result)[0]["end_time"], "13:30")

    def test_no_session_overlaps_the_break(self):
        """Across a full day, nothing may sit inside 12:00-13:00."""
        lunch = {"enabled": True, "start": "12:00", "end": "13:00"}
        subjects = [subject(2), dict(subject(3), id=2), dict(subject(2), id=3)]
        staff = [dict(faculty()[0], can_teach_subject_ids=[1, 2, 3])]
        result = generate_schedule(section(lunch=lunch), subjects, staff, rooms())
        for s in placed(result):
            start = hhmm_to_minutes(s["start_time"])
            end = hhmm_to_minutes(s["end_time"])
            self.assertFalse(start < 780 and 720 < end,
                             f"{s['start_time']}-{s['end_time']} overlaps the break")


if __name__ == "__main__":
    unittest.main()