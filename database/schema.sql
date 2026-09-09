-- ⚠️ HISTORICAL — DO NOT RUN
-- This file represents the original database design and is preserved for
-- historical/reference purposes only. It has been superseded by Laravel
-- migrations and seeders (see documentation/Implementation_Status.md §6).
-- The current system does not provide faculty user accounts or faculty login;
-- faculty are scheduling records/entities, not system users.

-- ==================================================
-- AI Faculty Scheduling System
-- Database Schema
-- ==================================================

-- ============================================================================
-- AI-Assisted Faculty, Classroom, and Laboratory Scheduling System
-- Database Schema (PostgreSQL)
-- ============================================================================
-- Maps directly to Requirements.md sections A-I.
-- Run with: psql -U <user> -d <database> -f schema.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- A. Users & Roles (Requirements A)
-- ----------------------------------------------------------------------------
CREATE TABLE users (
    id            SERIAL PRIMARY KEY,
    name          VARCHAR(150) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(20) NOT NULL CHECK (role IN ('department_head', 'faculty')),
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------------------
-- Academic calendar
-- ----------------------------------------------------------------------------
CREATE TABLE academic_years (
    id         SERIAL PRIMARY KEY,
    year_start SMALLINT NOT NULL,
    year_end   SMALLINT NOT NULL,
    is_active  BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE (year_start, year_end)
);

CREATE TABLE semesters (
    id               SERIAL PRIMARY KEY,
    academic_year_id INTEGER NOT NULL REFERENCES academic_years(id) ON DELETE CASCADE,
    name             VARCHAR(20) NOT NULL CHECK (name IN ('1st Semester', '2nd Semester', 'Summer')),
    is_active        BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE (academic_year_id, name)
);

-- ----------------------------------------------------------------------------
-- B. Faculty Management (Requirements B)
-- ----------------------------------------------------------------------------
CREATE TABLE faculty (
    id                SERIAL PRIMARY KEY,
    user_id           INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    employee_no       VARCHAR(30) UNIQUE,
    faculty_type      VARCHAR(20) NOT NULL CHECK (faculty_type IN ('full_time', 'part_time', 'evening')),
    max_teaching_load SMALLINT NOT NULL DEFAULT 24,  -- hours/week, adjustable
    is_active         BOOLEAN NOT NULL DEFAULT TRUE,
    created_at        TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Faculty availability: which days/time-ranges each faculty member can teach.
-- Supports the "part-time / evening only Tue-Thu 6-9pm" style constraints.
CREATE TABLE faculty_availability (
    id          SERIAL PRIMARY KEY,
    faculty_id  INTEGER NOT NULL REFERENCES faculty(id) ON DELETE CASCADE,
    day_of_week SMALLINT NOT NULL CHECK (day_of_week BETWEEN 1 AND 7), -- 1=Mon ... 7=Sun
    start_time  TIME NOT NULL,
    end_time    TIME NOT NULL,
    CHECK (end_time > start_time)
);

-- ----------------------------------------------------------------------------
-- C. Subject Management (Requirements C)
-- ----------------------------------------------------------------------------
CREATE TABLE subjects (
    id             SERIAL PRIMARY KEY,
    code           VARCHAR(20) NOT NULL UNIQUE,      -- e.g. "PROG1"
    title          VARCHAR(150) NOT NULL,             -- e.g. "Programming 1"
    year_level     SMALLINT NOT NULL CHECK (year_level BETWEEN 1 AND 4),
    semester_name  VARCHAR(20) NOT NULL CHECK (semester_name IN ('1st Semester', '2nd Semester', 'Summer')),
    lecture_hours  SMALLINT NOT NULL DEFAULT 0,
    lab_hours      SMALLINT NOT NULL DEFAULT 0,
    lab_room_type  VARCHAR(30),  -- NULL if no lab component; else e.g. 'computer_lab'
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    CHECK (lecture_hours > 0 OR lab_hours > 0)
);

-- Which faculty are qualified to teach which subjects (Requirements B, G)
CREATE TABLE faculty_subjects (
    faculty_id INTEGER NOT NULL REFERENCES faculty(id) ON DELETE CASCADE,
    subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    PRIMARY KEY (faculty_id, subject_id)
);

-- ----------------------------------------------------------------------------
-- D. Student Section Management (Requirements D)
-- ----------------------------------------------------------------------------
CREATE TABLE sections (
    id                    SERIAL PRIMARY KEY,
    name                  VARCHAR(30) NOT NULL,       -- e.g. "BSIT 1A"
    year_level            SMALLINT NOT NULL CHECK (year_level BETWEEN 1 AND 4),
    academic_year_id      INTEGER NOT NULL REFERENCES academic_years(id),
    semester_id           INTEGER NOT NULL REFERENCES semesters(id),
    preferred_days        SMALLINT[] NOT NULL,        -- e.g. {1,2,3,4,5} for Mon-Fri
    preferred_start_time  TIME NOT NULL,
    preferred_end_time    TIME NOT NULL,
    UNIQUE (name, academic_year_id, semester_id),
    CHECK (preferred_end_time > preferred_start_time)
);

-- Which subjects a given section takes this semester (their curriculum slice)
CREATE TABLE section_subjects (
    section_id INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
    subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    PRIMARY KEY (section_id, subject_id)
);

-- ----------------------------------------------------------------------------
-- E. Classroom & Laboratory Management (Requirements E)
-- ----------------------------------------------------------------------------
CREATE TABLE rooms (
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(30) NOT NULL UNIQUE,   -- e.g. "R101", "LAB1"
    type       VARCHAR(30) NOT NULL,          -- 'lecture', 'computer_lab', 'electronics_lab', etc.
    capacity   SMALLINT NOT NULL,
    status     VARCHAR(20) NOT NULL DEFAULT 'available' CHECK (status IN ('available', 'under_maintenance', 'inactive'))
);

-- ----------------------------------------------------------------------------
-- F/G. Generated Schedules (Requirements F, G)
-- ----------------------------------------------------------------------------
CREATE TABLE schedules (
    id           SERIAL PRIMARY KEY,
    section_id   INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
    status       VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'approved', 'published', 'rejected')),
    generated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    approved_by  INTEGER REFERENCES users(id),
    approved_at  TIMESTAMP
);

-- Individual class sessions that make up a schedule.
-- One row per (subject, lecture-or-lab) block, mirroring the "sessions"
-- concept already used in ai-engine/prototype/scheduler_prototype.py
CREATE TABLE schedule_sessions (
    id           SERIAL PRIMARY KEY,
    schedule_id  INTEGER NOT NULL REFERENCES schedules(id) ON DELETE CASCADE,
    subject_id   INTEGER NOT NULL REFERENCES subjects(id),
    faculty_id   INTEGER NOT NULL REFERENCES faculty(id),
    room_id      INTEGER NOT NULL REFERENCES rooms(id),
    session_type VARCHAR(10) NOT NULL CHECK (session_type IN ('lecture', 'laboratory')),
    day_of_week  SMALLINT NOT NULL CHECK (day_of_week BETWEEN 1 AND 7),
    start_time   TIME NOT NULL,
    end_time     TIME NOT NULL,
    CHECK (end_time > start_time)
);

-- ----------------------------------------------------------------------------
-- H. AI Recommendation / Failure Explanation Log (Requirements H)
-- ----------------------------------------------------------------------------
-- Stores why a generation attempt failed or what the solver suggested,
-- so the frontend can display it to the Department Head.
CREATE TABLE schedule_generation_logs (
    id           SERIAL PRIMARY KEY,
    section_id   INTEGER NOT NULL REFERENCES sections(id) ON DELETE CASCADE,
    status       VARCHAR(20) NOT NULL CHECK (status IN ('success', 'infeasible', 'error')),
    message      TEXT,           -- human-readable explanation, e.g. "No Computer Lab available..."
    requested_by INTEGER REFERENCES users(id),
    created_at   TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------------------
-- Audit trail (Non-functional: Reliability)
-- ----------------------------------------------------------------------------
CREATE TABLE audit_log (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER REFERENCES users(id),
    action     VARCHAR(100) NOT NULL,   -- e.g. "schedule.approved", "faculty.updated"
    entity     VARCHAR(50),             -- e.g. "schedule", "faculty"
    entity_id  INTEGER,
    details    JSONB,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ----------------------------------------------------------------------------
-- Indexes for the columns most queried by the scheduling engine
-- ----------------------------------------------------------------------------
CREATE INDEX idx_faculty_availability_faculty ON faculty_availability(faculty_id);
CREATE INDEX idx_faculty_subjects_faculty     ON faculty_subjects(faculty_id);
CREATE INDEX idx_faculty_subjects_subject     ON faculty_subjects(subject_id);
CREATE INDEX idx_schedule_sessions_schedule   ON schedule_sessions(schedule_id);
CREATE INDEX idx_schedule_sessions_faculty    ON schedule_sessions(faculty_id, day_of_week);
CREATE INDEX idx_schedule_sessions_room       ON schedule_sessions(room_id, day_of_week);
CREATE INDEX idx_sections_academic_semester   ON sections(academic_year_id, semester_id);