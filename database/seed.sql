-- ============================================================================
-- Sample seed data — mirrors the fake dataset already validated in
-- ai-engine/prototype/scheduler_prototype.py, so you can sanity-check
-- the schema against data you already know produces a working schedule.
-- Run AFTER schema.sql: psql -U <user> -d <database> -f seed.sql
-- ============================================================================

-- Academic calendar
INSERT INTO academic_years (year_start, year_end, is_active) VALUES (2026, 2027, TRUE);
INSERT INTO semesters (academic_year_id, name, is_active) VALUES (1, '1st Semester', TRUE);

-- Users (1 department head, 3 faculty)
INSERT INTO users (name, email, password_hash, role) VALUES
    ('Engr. Dela Cruz', 'head@school.edu', 'placeholder_hash', 'department_head'),
    ('Prof. Reyes', 'reyes@school.edu', 'placeholder_hash', 'faculty'),
    ('Prof. Santos', 'santos@school.edu', 'placeholder_hash', 'faculty'),
    ('Prof. Cruz', 'cruz@school.edu', 'placeholder_hash', 'faculty');

-- Faculty (mirrors F1, F2, F3 in the prototype)
INSERT INTO faculty (user_id, employee_no, faculty_type, max_teaching_load) VALUES
    (2, 'EMP-001', 'full_time', 24),   -- Prof. Reyes
    (3, 'EMP-002', 'full_time', 24),   -- Prof. Santos
    (4, 'EMP-003', 'part_time', 12);   -- Prof. Cruz

-- Faculty availability
-- Reyes & Santos: Mon-Fri, 7am-3pm (full-time)
INSERT INTO faculty_availability (faculty_id, day_of_week, start_time, end_time)
SELECT 1, d, '07:00', '15:00' FROM generate_series(1, 5) AS d;   -- Reyes
INSERT INTO faculty_availability (faculty_id, day_of_week, start_time, end_time)
SELECT 2, d, '07:00', '15:00' FROM generate_series(1, 5) AS d;   -- Santos

-- Cruz: part-time, Mon/Wed/Fri only (mirrors "force_cruz" test case)
INSERT INTO faculty_availability (faculty_id, day_of_week, start_time, end_time) VALUES
    (3, 1, '07:00', '15:00'),  -- Mon
    (3, 3, '07:00', '15:00'),  -- Wed
    (3, 5, '07:00', '15:00');  -- Fri

-- Subjects (mirrors PROG1, PROG2, MATH1)
INSERT INTO subjects (code, title, year_level, semester_name, lecture_hours, lab_hours, lab_room_type) VALUES
    ('PROG1', 'Programming 1', 1, '1st Semester', 2, 3, 'computer_lab'),
    ('PROG2', 'Programming 2', 1, '1st Semester', 2, 3, 'computer_lab'),
    ('MATH1', 'College Algebra', 1, '1st Semester', 3, 0, NULL);

-- Faculty qualifications (who can teach what)
INSERT INTO faculty_subjects (faculty_id, subject_id) VALUES
    (1, 1), (1, 2),   -- Reyes: PROG1, PROG2
    (2, 3),           -- Santos: MATH1
    (3, 1), (3, 2), (3, 3);  -- Cruz: all three (part-time backup)

-- Rooms (mirrors R101, LAB1)
INSERT INTO rooms (name, type, capacity, status) VALUES
    ('R101', 'lecture', 40, 'available'),
    ('LAB1', 'computer_lab', 30, 'available');

-- Section (mirrors BSIT1A)
INSERT INTO sections (name, year_level, academic_year_id, semester_id, preferred_days, preferred_start_time, preferred_end_time) VALUES
    ('BSIT 1A', 1, 1, 1, ARRAY[1,2,3,4,5], '07:00', '15:00');

-- Section's subjects for the semester
INSERT INTO section_subjects (section_id, subject_id) VALUES
    (1, 1), (1, 2), (1, 3);

-- NOTE: schedules / schedule_sessions rows are intentionally NOT seeded here —
-- those get created by the AI engine when it generates a schedule, not by
-- static seed data. This keeps the seed representing "input" data only,
-- matching how the real system will actually be used.