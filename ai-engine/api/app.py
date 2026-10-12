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
from fastapi.responses import JSONResponse
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
    """
    Liveness: is this process up, and can it open a database connection at all.

    Deliberately independent of schema compatibility. A liveness probe must not
    fail because a migration has not run yet, or the orchestrator would restart a
    perfectly healthy process forever — use /ready to gate traffic instead.
    """
    try:
        conn = get_connection()
        conn.close()
        return {"status": "ok", "database": "connected"}
    except Exception:
        # The exception is deliberately not repeated. A psycopg2 connection error
        # can carry the host, port, user and database from the connection string,
        # and this endpoint is unauthenticated.
        return {"status": "error", "database": "unavailable"}


# --- Readiness ----------------------------------------------------------------
# The table and columns the department ownership rule reads. Readiness means all
# of them exist, not merely that a connection could be opened: the generation
# query LEFT JOINs `departments` and selects `department_id` from both tables, so
# a database without them cannot serve a single request.

REQUIRED_DEPARTMENT_SCHEMA = (
    ("subjects", "department_id"),
    ("subjects", "classification"),
    ("sections", "department_id"),
)

# Tables the ownership rule reads, alongside the columns above.
REQUIRED_DEPARTMENT_TABLES = (
    "departments",
    "subject_department_access",
)

DEPARTMENT_SCHEMA_QUERY = (
    "SELECT table_name, column_name "
    "FROM information_schema.columns "
    "WHERE table_schema = 'public' "
    "AND ("
    "(table_name = 'subjects' "
    "AND column_name IN ('department_id', 'classification')) "
    "OR (table_name = 'sections' AND column_name = 'department_id')"
    ")"
)


def missing_department_schema(cur):
    """
    Which parts of the department schema are absent, as readable items.

    Read-only: one existence probe per table plus one metadata query, no table
    data. `to_regclass` answers whether a table exists without raising when it
    does not, so a pre-migration database reports cleanly instead of erroring.
    """
    missing = []

    for table in REQUIRED_DEPARTMENT_TABLES:
        cur.execute(f"SELECT to_regclass('public.{table}') AS relation")
        row = cur.fetchone()
        if not row or row.get("relation") is None:
            missing.append(f"{table} table")

    cur.execute(DEPARTMENT_SCHEMA_QUERY)
    present = {(r["table_name"], r["column_name"]) for r in cur.fetchall()}

    for table, column in REQUIRED_DEPARTMENT_SCHEMA:
        if (table, column) not in present:
            missing.append(f"{table}.{column} column")

    return missing


def ownership_is_seeded(cur):
    """
    Whether the reviewed ownership data is actually present: at least one
    department, and at least one subject carrying a review outcome.

    Departments alone are not enough any more. A database with departments but no
    classified subject has nothing to schedule — every subject is `unclassified`,
    which the guard refuses — so advertising readiness would promise a service the
    engine cannot provide. This is the state a half-finished activation leaves
    behind, and it is the one the seeder exists to clear.
    """
    cur.execute("SELECT count(*) AS total FROM departments")
    row = cur.fetchone()
    if not row or not row["total"]:
        return False

    cur.execute(
        "SELECT count(*) AS total FROM subjects "
        "WHERE classification IN ('major', 'minor')"
    )
    row = cur.fetchone()
    return bool(row and row["total"])


@app.get("/ready")
def ready():
    """
    Readiness: can this engine serve generation against this database?

    200 only when a connection opens, the department schema is applied, and
    ownership has been seeded. Otherwise 503, so an orchestrator holds traffic
    back rather than handing the engine a database it cannot honour.

    Nothing here reveals credentials, connection strings or SQL: no exception text
    is ever returned, and this endpoint is unauthenticated. The items reported as
    missing are table and column names, which are what makes the response
    actionable.
    """
    try:
        conn = get_connection()
    except Exception:
        return JSONResponse(
            status_code=503,
            content={
                "status": "not_ready",
                "reason": "database_unavailable",
                "detail": "Could not open a database connection.",
            },
        )

    try:
        cur = conn.cursor()

        missing = missing_department_schema(cur)
        if missing:
            return JSONResponse(
                status_code=503,
                content={
                    "status": "not_ready",
                    "reason": "schema_incompatible",
                    "detail": "The department schema is not applied to this database.",
                    "missing": missing,
                },
            )

        if not ownership_is_seeded(cur):
            return JSONResponse(
                status_code=503,
                content={
                    "status": "not_ready",
                    "reason": "ownership_not_seeded",
                    "detail": (
                        "No subject has a review outcome yet, so every subject is "
                        "unclassified and generation would be refused for all of "
                        "them."
                    ),
                },
            )

        return {
            "status": "ready",
            "schema": "department_ownership",
        }
    except psycopg2.Error:
        # A database failure is a readiness failure. Its text can name tables,
        # columns, parameters or the connection, so report the fact, not the
        # message.
        return JSONResponse(
            status_code=503,
            content={
                "status": "not_ready",
                "reason": "database_error",
                "detail": "The database could not be inspected for readiness.",
            },
        )
    finally:
        conn.close()


@app.post("/generate-schedule/{section_id}")
def generate_schedule_for_section(section_id: int):
    try:
        conn = get_connection()
        cur = conn.cursor()

        # --- Section ---
        cur.execute(
            "SELECT s.id, s.name, s.preferred_days, s.preferred_start_time, s.preferred_end_time, "
            "s.student_count, s.department_id, d.name AS department_name, "
            "s.academic_year, s.semester_name "
            "FROM sections s "
            "LEFT JOIN departments d ON d.id = s.department_id "
            "WHERE s.id = %s",
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
            # Which department owns this section: a major subject may only be
            # placed here when it belongs to the same one (see the ownership
            # guard below). Null means the section may take shared subjects only.
            "department_id": section_row.get("department_id"),
            # The section's own department by name, so an ownership rejection can
            # name both sides rather than only the subject's owner.
            "department_name": section_row.get("department_name"),
            # A fixed midday break keeps 12:00-1:00 PM clear. Read per request so
            # changing it in Settings takes effect on the next generation.
            "lunch": read_lunch_settings(cur),
        }

        # --- Subjects assigned to this section ---
        cur.execute(
            """
            SELECT s.id, s.code, s.lecture_hours, s.lab_hours, s.lab_room_type,
                   s.classification, s.department_id, d.name AS department_name
            FROM subjects s
            JOIN section_subjects ss ON ss.subject_id = s.id
            LEFT JOIN departments d ON d.id = s.department_id
            WHERE ss.section_id = %s
            """,
            (section_id,),
        )
        subjects = [dict(row) for row in cur.fetchall()]
        if not subjects:
            raise HTTPException(
                status_code=400, detail=f"Section {section_id} has no subjects assigned."
            )

        subject_ids = [s["id"] for s in subjects]

        # --- Department authorization ---
        # Which departments may schedule which subject is held in
        # subject_department_access: for a major, its owner plus any department the
        # curriculum grants it to (ELEC201, owned by Electronics, is granted to
        # BIT). A minor needs no rows — being open to everyone is its
        # classification.
        cur.execute(
            "SELECT subject_id, department_id FROM subject_department_access "
            "WHERE subject_id = ANY(%s)",
            (subject_ids,),
        )
        authorized_departments = {}
        for row in cur.fetchall():
            authorized_departments.setdefault(row["subject_id"], set()).add(
                int(row["department_id"])
            )

        # --- Department ownership ---
        # A minor is schedulable anywhere. A major is schedulable only where a
        # department is authorized for it, so it must have an owner and an access
        # row for the section's department. Anything unclassified — including a
        # row from a database that predates the classification column — is
        # schedulable nowhere.
        #
        # The backend refuses such a run before it ever reaches us, so this is the
        # second line of defence, for a direct engine call: it fails loudly rather
        # than quietly dropping a subject, which would hand back a schedule that
        # is missing its classes.
        for candidate in subjects:
            classification = candidate.get("classification") or "unclassified"

            if classification == "minor":
                continue

            section_department_id = section.get("department_id")

            if classification != "major":
                raise HTTPException(
                    status_code=422,
                    detail=(
                        f"{candidate['code']} has not been classified yet, so it cannot "
                        "be scheduled. Classify it as a major (with its owning "
                        "department) or as a minor / shared subject first."
                    ),
                )

            owner = candidate.get("department_id")

            if owner is not None and section_department_id is not None:
                authorized = authorized_departments.get(candidate["id"], set())
                if int(section_department_id) in authorized:
                    continue

            # The names come from the LEFT JOIN, so they are absent whenever the
            # department row cannot be resolved — an unseeded table, or an
            # ownership id whose row has gone. The id is then the honest label;
            # `owner` is read from subjects.department_id, not from the join, so a
            # missing name never turns a major into a shared subject.
            if owner is None:
                owner_label = "no department (the major has no owner)"
            else:
                owner_label = candidate.get("department_name") or f"department #{owner}"

            if section_department_id is None:
                # A section with no department of its own cannot claim anyone's
                # major: only minor subjects are open to it.
                target = f"section {section['name']}, which has no department"
            else:
                section_label = (
                    section.get("department_name")
                    or f"department #{section_department_id}"
                )
                target = f"section {section['name']} ({section_label})"

            raise HTTPException(
                status_code=422,
                detail=(
                    f"{candidate['code']} is a major subject of {owner_label} "
                    f"and cannot be scheduled for {target}."
                ),
            )

        # --- Faculty who can teach any of these subjects ---
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
