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

load_dotenv()

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


def parse_hour(time_value):
    """Safely extract hour from time — handles both datetime.time and string formats."""
    if time_value is None:
        return 0
    if hasattr(time_value, 'hour'):
        return time_value.hour
    # Handle string like "07:00:00" or "07:00"
    s = str(time_value).strip()
    parts = s.split(":")
    return int(parts[0])


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
            "SELECT id, name, preferred_days, preferred_start_time, preferred_end_time, student_count "
            "FROM sections WHERE id = %s",
            (section_id,),
        )
        section_row = cur.fetchone()
        if not section_row:
            raise HTTPException(status_code=404, detail=f"Section {section_id} not found.")

        section = {
            "id": section_row["id"],
            "name": section_row["name"],
            "preferred_days": parse_preferred_days(section_row["preferred_days"]),
            "preferred_start_hour": parse_hour(section_row["preferred_start_time"]),
            "preferred_end_hour": parse_hour(section_row["preferred_end_time"]),
            "student_count": section_row.get("student_count", 30),
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
            SELECT DISTINCT f.id, u.name
            FROM faculties f
            JOIN users u ON u.id = f.user_id
            JOIN faculty_subjects fs ON fs.faculty_id = f.id
            WHERE fs.subject_id = ANY(%s)
              AND f.is_active = true
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

            cur.execute(
                "SELECT DISTINCT day_of_week FROM faculty_availabilities WHERE faculty_id = %s",
                (f["id"],),
            )
            available_days = [r["day_of_week"] for r in cur.fetchall()]

            cur.execute(
                "SELECT max_teaching_load FROM faculties WHERE id = %s", (f["id"],)
            )
            max_load_row = cur.fetchone()
            max_teaching_load = max_load_row["max_teaching_load"] if max_load_row else 24

            # Hours already committed from OTHER sections' approved/published schedules
            cur.execute(
                """
                SELECT COALESCE(SUM(
                    EXTRACT(EPOCH FROM (ss.end_time - ss.start_time)) / 3600
                ), 0) AS total_hours
                FROM schedule_sessions ss
                JOIN schedules sch ON sch.id = ss.schedule_id
                WHERE ss.faculty_id = %s
                  AND sch.section_id != %s
                  AND sch.status IN ('approved', 'published')
                """,
                (f["id"], section_id),
            )
            total_hours_result = cur.fetchone()
            existing_load_hours = float(total_hours_result["total_hours"] or 0)

            faculty.append(
                {
                    "id": f["id"],
                    "name": f["name"],
                    "can_teach_subject_ids": can_teach,
                    "available_days": available_days,
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

        # --- Existing committed sessions from OTHER sections ---
        cur.execute(
            """
            SELECT ss.day_of_week,
                   EXTRACT(HOUR FROM ss.start_time)::int AS start_hour,
                   EXTRACT(HOUR FROM ss.end_time)::int AS end_hour,
                   ss.faculty_id, ss.room_id
            FROM schedule_sessions ss
            JOIN schedules sch ON sch.id = ss.schedule_id
            WHERE sch.section_id != %s
              AND sch.status IN ('approved', 'published')
            """,
            (section_id,),
        )
        existing_sessions = [dict(row) for row in cur.fetchall()]

        cur.close()
        conn.close()

        # --- Run the solver ---
        result = generate_schedule(
            section, subjects, faculty, rooms, existing_sessions
        )
        return result

    except psycopg2.Error as e:
        raise HTTPException(status_code=500, detail=f"Database error: {str(e)}")
    except HTTPException:
        raise
    except Exception as e:
        import traceback

        raise HTTPException(
            status_code=500,
            detail=f"Unexpected error: {type(e).__name__}: {str(e)}\n\n{traceback.format_exc()}",
        )
