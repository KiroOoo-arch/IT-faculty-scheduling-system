"""
FastAPI service exposing the AI scheduling engine over HTTP.
"""

import json
import os
import sys
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parent.parent / "solver"))

import psycopg2
import psycopg2.extras
from fastapi import FastAPI, HTTPException
from dotenv import load_dotenv
from scheduler import generate_schedule
from time_slots import hhmm_to_minutes

# Load .env from the ai-engine root, regardless of CWD
load_dotenv(Path(__file__).resolve().parent.parent / ".env")

app = FastAPI(title="Faculty Scheduling AI Engine")

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": os.getenv("DB_PORT", "5432"),
    "dbname": os.getenv("DB_DATABASE", "scheduling_system"),
    "user": os.getenv("DB_USERNAME", "postgres"),
    "password": os.getenv("DB_PASSWORD", ""),
}


def get_connection():
    return psycopg2.connect(**DB_CONFIG, cursor_factory=psycopg2.extras.RealDictCursor)


def parse_minutes(time_value):
    """
    Minutes since midnight from a TIME column or a string.

    This replaced `parse_hour()`, which returned `int(value.split(":")[0])` and so
    silently dropped the minutes: a section window of 07:30-20:30 became
    07:00-20:00 before the solver saw it. Keeping the minutes is what makes a
    7:30 AM start and an 8:30 PM end possible at all.
    """
    return hhmm_to_minutes(time_value)


def parse_preferred_days(days_value):
    """Safely parse preferred_days from JSON string or list."""
    if days_value is None:
        return [1, 2, 3, 4, 5]
    if isinstance(days_value, list):
        return days_value
    if isinstance(days_value, str):
        try:
            return json.loads(days_value)
        except json.JSONDecodeError:
            return [1, 2, 3, 4, 5]
    return [1, 2, 3, 4, 5]


def read_lunch_settings(cur):
    """
    The midday break from the `settings` table, as a dict the solver understands.

    Missing rows fall back to the documented 12:00-1:00 PM break. A break that
    fails to parse is reported as disabled rather than raising, so a bad settings
    row can never make every section unschedulable.
    """
    defaults = {"enabled": True, "start": "12:00", "end": "13:00"}
    try:
        cur.execute("SELECT key, value FROM settings WHERE key IN ('lunch_start', 'lunch_end', 'lunch_enabled')")
        stored = {row["key"]: row["value"] for row in cur.fetchall()}
    except psycopg2.Error:
        # The settings table may not exist yet (pre-migration database); the
        # default break is still the intended behaviour.
        return defaults

    start = stored.get("lunch_start", defaults["start"])
    end = stored.get("lunch_end", defaults["end"])
    enabled = str(stored.get("lunch_enabled", "true")).strip().lower() not in ("false", "0", "no", "off", "")

    if hhmm_to_minutes(end) <= hhmm_to_minutes(start):
        return {"enabled": False, "start": start, "end": end}

    return {"enabled": enabled, "start": start, "end": end}


@app.get("/health")
def health():
    try:
        conn = get_connection()
        conn.close()
        return {"status": "ok", "database": "connected"}
    except Exception as e:
        return {"status": "error", "database": str(e)}


@app.post("/generate-schedule/{section_id}")
def generate_schedule_for_section(section_id: int):
    try:
        conn = get_connection()
        cur = conn.cursor()

        # --- Section ---
        cur.execute(
            "SELECT id, name, preferred_days, preferred_start_time, preferred_end_time, student_count, "
            "academic_year, semester_name "
            "FROM sections WHERE id = %s",
            (section_id,),
        )
        section_row = cur.fetchone()
        if not section_row:
            raise HTTPException(status_code=404, detail=f"Section {section_id} not found.")

        # The term scopes every cross-section constraint below: a schedule for a
        # different academic year or semester belongs to a different timetable
        # and must not constrain this one.
        academic_year = section_row["academic_year"]
        semester_name = section_row["semester_name"]

        section = {
            "id": section_row["id"],
            "name": section_row["name"],
            "preferred_days": parse_preferred_days(section_row["preferred_days"]),
            "preferred_start_minutes": parse_minutes(section_row["preferred_start_time"]),
            "preferred_end_minutes": parse_minutes(section_row["preferred_end_time"]),
            "student_count": section_row.get("student_count", 30),
            # A fixed midday break keeps 12:00-1:00 PM clear. Read per request so
            # changing it in Settings takes effect on the next generation.
            "lunch": read_lunch_settings(cur),
        }

        # --- Subjects assigned to this section ---
        cur.execute(
            """
            SELECT s.id, s.code, s.lecture_hours, s.lab_hours, s.lab_room_type
            FROM subjects s
            JOIN section_subjects ss ON ss.subject_id = s.id
            WHERE ss.section_id = %s
            """,
            (section_id,),
        )
        subjects = [dict(row) for row in cur.fetchall()]
        if not subjects:
            raise HTTPException(
                status_code=400, detail=f"Section {section_id} has no subjects assigned."
            )

        # --- Faculty who can teach any of these subjects ---
        subject_ids = [s["id"] for s in subjects]
        cur.execute(
            """
            SELECT DISTINCT f.id, f.name
            FROM faculties f
            JOIN faculty_subjects fs ON fs.faculty_id = f.id
            WHERE fs.subject_id = ANY(%s)
              AND f.is_active = true
              AND f.name IS NOT NULL
            """,
            (subject_ids,),
        )
        faculty_rows = [dict(row) for row in cur.fetchall()]

        faculty = []
        for f in faculty_rows:
            cur.execute(
                "SELECT subject_id FROM faculty_subjects WHERE faculty_id = %s",
                (f["id"],),
            )
            can_teach = [r["subject_id"] for r in cur.fetchall()]

            # Declared availability: the days AND the hour windows behind them.
            # The API's session validator (ScheduleSessionController) requires a
            # session to fit inside one declared window on its day, so the solver
            # has to work from the same windows, not just the day list.
            cur.execute(
                "SELECT day_of_week, start_time, end_time "
                "FROM faculty_availabilities WHERE faculty_id = %s",
                (f["id"],),
            )
            availability_rows = cur.fetchall()

            available_windows = []
            for row in availability_rows:
                if row["start_time"] is None or row["end_time"] is None:
                    continue
                window_start = parse_minutes(row["start_time"])
                window_end = parse_minutes(row["end_time"])
                if window_end <= window_start:
                    continue
                available_windows.append({
                    "day_of_week": row["day_of_week"],
                    "start_minutes": window_start,
                    "end_minutes": window_end,
                })

            # Only a faculty with no declared availability at all falls back to
            # the section's days — the same condition the validator uses.
            if availability_rows:
                available_days = sorted(
                    {w["day_of_week"] for w in available_windows}
                )
            else:
                available_windows = []
                available_days = section["preferred_days"]

            cur.execute(
                "SELECT max_teaching_load FROM faculties WHERE id = %s", (f["id"],)
            )
            max_load_row = cur.fetchone()
            max_teaching_load = max_load_row["max_teaching_load"] if max_load_row else 24

            # Hours already committed by OTHER sections in the same term. Drafts
            # are included: a sibling's draft is a live booking while it is being
            # planned, so this section must not be handed the same faculty hours
            # that another draft (or an approved/published schedule) already
            # holds. The target section is always excluded, so its own previous
            # draft never constrains its regeneration.
            cur.execute(
                """
                SELECT COALESCE(SUM(
                    EXTRACT(EPOCH FROM (ss.end_time - ss.start_time)) / 3600
                ), 0) AS total_hours
                FROM schedule_sessions ss
                JOIN schedules sch ON sch.id = ss.schedule_id
                JOIN sections sec ON sec.id = sch.section_id
                WHERE ss.faculty_id = %s
                  AND sch.section_id != %s
                  AND sch.status IN ('draft', 'approved', 'published')
                  AND sec.academic_year = %s
                  AND sec.semester_name = %s
                """,
                (f["id"], section_id, academic_year, semester_name),
            )
            total_hours_result = cur.fetchone()
            existing_load_hours = float(total_hours_result["total_hours"] or 0)

            faculty.append(
                {
                    "id": f["id"],
                    "name": f["name"],
                    "can_teach_subject_ids": can_teach,
                    "available_days": available_days,
                    "available_windows": available_windows,
                    "max_teaching_load": max_teaching_load,
                    "existing_load_hours": existing_load_hours,
                }
            )

        if not faculty:
            raise HTTPException(
                status_code=400,
                detail="No faculty found who can teach any subject in this section.",
            )

        # --- Rooms ---
        cur.execute(
            "SELECT id, name, type, capacity FROM rooms WHERE status = 'available'"
        )
        rooms = [dict(row) for row in cur.fetchall()]
        if not rooms:
            raise HTTPException(status_code=400, detail="No available rooms found.")

        # --- Existing sessions from OTHER sections in the SAME term ---
        # Drafts count: two sections planned in one sitting are both still
        # drafts, so excluding them let the engine hand the same faculty member
        # or room to both, and the clash only surfaced at publish time. Approved
        # and published schedules stay included, and the target section is
        # excluded so regenerating it is not blocked by its own old draft.
        cur.execute(
            """
            SELECT ss.day_of_week,
                   to_char(ss.start_time, 'HH24:MI') AS start_time,
                   to_char(ss.end_time, 'HH24:MI') AS end_time,
                   ss.faculty_id, ss.room_id
            FROM schedule_sessions ss
            JOIN schedules sch ON sch.id = ss.schedule_id
            JOIN sections sec ON sec.id = sch.section_id
            WHERE sch.section_id != %s
              AND sch.status IN ('draft', 'approved', 'published')
              AND sec.academic_year = %s
              AND sec.semester_name = %s
            """,
            (section_id, academic_year, semester_name),
        )
        # Map the columns onto the solver's contract here rather than passing the
        # raw rows through: the query returns wall-clock strings, while the solver
        # needs `start_minutes`/`end_minutes`. Passing the rows verbatim silently
        # produced `None` times, which CP-SAT rejects with a TypeError.
        existing_sessions = []
        for row in cur.fetchall():
            existing_sessions.append({
                "day_of_week": row["day_of_week"],
                "start_minutes": parse_minutes(row["start_time"]),
                "end_minutes": parse_minutes(row["end_time"]),
                "faculty_id": row["faculty_id"],
                "room_id": row["room_id"],
            })

        cur.close()
        conn.close()

        # --- Run the solver ---
        result = generate_schedule(
            section, subjects, faculty, rooms, existing_sessions
        )
        return result

    except psycopg2.Error as e:
        print(f"DATABASE ERROR: {e}")
        raise HTTPException(status_code=500, detail=f"Database error: {str(e)}")
    except HTTPException:
        raise
    except Exception as e:
        import traceback
        tb = traceback.format_exc()
        print("=" * 60)
        print("PYTHON ERROR TRACEBACK:")
        print(tb)
        print("=" * 60)
        raise HTTPException(
            status_code=500,
            detail=f"Unexpected error: {type(e).__name__}: {str(e)}\n\n{tb}",
        )
