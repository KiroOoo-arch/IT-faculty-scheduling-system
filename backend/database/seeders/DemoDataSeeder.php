<?php

namespace Database\Seeders;

use App\Models\Faculty;
use App\Models\FacultyAvailability;
use App\Models\Room;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Demo dataset for the defense/demo: BIT sections across Years 1-3 (A-E),
 * a BSIT curriculum per year with electronics subjects, extra rooms, and the
 * faculty who teach them.
 *
 * Safe to re-run: every record is matched on its natural key (room name,
 * subject code, section name, faculty name) and updated rather than duplicated.
 * The seeder never deletes; existing sections keep their own subject links
 * because attachments are added with syncWithoutDetaching().
 *
 * Semester note: each year level runs one semester, chosen to match the
 * pre-existing BIT-2A (2nd Semester) and BIT-3A (1st Semester) records so the
 * whole grid stays internally consistent with the generation gate, which
 * requires a section's subjects to match its year level AND semester.
 */
class DemoDataSeeder extends Seeder
{
    private const ACADEMIC_YEAR = '2026-2027';

    /** Year level => semester the sections of that year run this term. */
    private const SEMESTER_BY_YEAR = [
        1 => '1st Semester',
        2 => '2nd Semester',
        3 => '1st Semester',
    ];

    /** Sections per year level, written as {year}{letter}: BIT-1A ... BIT-3E. */
    private const SECTION_LETTERS = ['A', 'B', 'C', 'D', 'E'];

    private const STUDENT_COUNT_BY_YEAR = [1 => 40, 2 => 35, 3 => 30];

    public function run(): void
    {
        $rooms = $this->seedRooms();
        $subjects = $this->seedSubjects();
        $sections = $this->seedSections();

        $this->attachSubjectsToSections($sections, $subjects);
        $this->seedFaculty();
    }

    /** Extra rooms so every session type has a room that seats the section. */
    private function seedRooms(): void
    {
        $rooms = [
            ['name' => 'R201', 'type' => 'lecture', 'capacity' => 40, 'status' => 'available'],
            ['name' => 'R202', 'type' => 'lecture', 'capacity' => 40, 'status' => 'available'],
            ['name' => 'R203', 'type' => 'lecture', 'capacity' => 45, 'status' => 'available'],
            ['name' => 'LAB2', 'type' => 'computer_lab', 'capacity' => 40, 'status' => 'available'],
            ['name' => 'LAB3', 'type' => 'computer_lab', 'capacity' => 45, 'status' => 'available'],
            ['name' => 'ELC1', 'type' => 'electronics_lab', 'capacity' => 40, 'status' => 'available'],
        ];

        foreach ($rooms as $room) {
            Room::updateOrCreate(['name' => $room['name']], $room);
        }
    }

    /**
     * @return array<string, Subject> subject code => model
     */
    private function seedSubjects(): array
    {
        $catalogue = [
            // ---- Year 1, 1st Semester ----
            ['code' => 'GE101', 'title' => 'Understanding the Self', 'year_level' => 1, 'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 0, 'lab_room_type' => null],
            ['code' => 'GE102', 'title' => 'Readings in Philippine History', 'year_level' => 1, 'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 0, 'lab_room_type' => null],
            ['code' => 'MATH101', 'title' => 'Mathematics in the Modern World', 'year_level' => 1, 'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 0, 'lab_room_type' => null],
            ['code' => 'PROG101', 'title' => 'Computer Programming 1', 'year_level' => 1, 'semester_name' => '1st Semester', 'lecture_hours' => 2, 'lab_hours' => 3, 'lab_room_type' => 'computer_lab'],
            ['code' => 'ELEC101', 'title' => 'Fundamentals of Electronics', 'year_level' => 1, 'semester_name' => '1st Semester', 'lecture_hours' => 2, 'lab_hours' => 3, 'lab_room_type' => 'electronics_lab'],

            // ---- Year 2, 2nd Semester ----
            ['code' => 'PROG201', 'title' => 'Object-Oriented Programming', 'year_level' => 2, 'semester_name' => '2nd Semester', 'lecture_hours' => 2, 'lab_hours' => 3, 'lab_room_type' => 'computer_lab'],
            ['code' => 'DSA201', 'title' => 'Data Structures and Algorithms', 'year_level' => 2, 'semester_name' => '2nd Semester', 'lecture_hours' => 2, 'lab_hours' => 3, 'lab_room_type' => 'computer_lab'],
            ['code' => 'DB201', 'title' => 'Information Management', 'year_level' => 2, 'semester_name' => '2nd Semester', 'lecture_hours' => 2, 'lab_hours' => 3, 'lab_room_type' => 'computer_lab'],
            ['code' => 'NET201', 'title' => 'Computer Networking 1', 'year_level' => 2, 'semester_name' => '2nd Semester', 'lecture_hours' => 2, 'lab_hours' => 3, 'lab_room_type' => 'computer_lab'],
            ['code' => 'ELEC201', 'title' => 'Electronic Devices and Circuits', 'year_level' => 2, 'semester_name' => '2nd Semester', 'lecture_hours' => 2, 'lab_hours' => 3, 'lab_room_type' => 'electronics_lab'],

            // ---- Year 3, 1st Semester (the curriculum list from the reference sheet) ----
            ['code' => 'TPCC 311', 'title' => 'Mobile Programming', 'year_level' => 3, 'semester_name' => '1st Semester', 'lecture_hours' => 2, 'lab_hours' => 3, 'lab_room_type' => 'computer_lab'],
            ['code' => 'TPCC 312', 'title' => 'System Analysis and Design', 'year_level' => 3, 'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 0, 'lab_room_type' => null],
            ['code' => 'TPCC 313', 'title' => 'Project Study 1 with Intellectual Property Rights', 'year_level' => 3, 'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 0, 'lab_room_type' => null],
            ['code' => 'TPCC 314', 'title' => 'Information Assurance and Security', 'year_level' => 3, 'semester_name' => '1st Semester', 'lecture_hours' => 2, 'lab_hours' => 3, 'lab_room_type' => 'computer_lab'],
            ['code' => 'TACC 311', 'title' => 'Industrial Organization and Management', 'year_level' => 3, 'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 0, 'lab_room_type' => null],
            ['code' => 'TACC 312', 'title' => 'Industrial Psychology', 'year_level' => 3, 'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 0, 'lab_room_type' => null],
            ['code' => 'GE 7', 'title' => 'The Contemporary World', 'year_level' => 3, 'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 0, 'lab_room_type' => null],
            ['code' => 'GEE 3', 'title' => 'Environmental Science', 'year_level' => 3, 'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 0, 'lab_room_type' => null],
            ['code' => 'FL 1', 'title' => 'Foreign Language 1', 'year_level' => 3, 'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 0, 'lab_room_type' => null],
        ];

        $subjects = [];
        foreach ($catalogue as $subject) {
            $subjects[$subject['code']] = Subject::updateOrCreate(
                ['code' => $subject['code']],
                $subject + ['is_active' => true]
            );
        }

        return $subjects;
    }

    /**
     * @return array<string, Section> section name => model
     */
    private function seedSections(): array
    {
        $sections = [];
        foreach (self::SEMESTER_BY_YEAR as $year => $semester) {
            foreach (self::SECTION_LETTERS as $letter) {
                $name = "BIT-{$year}{$letter}";
                $sections[$name] = Section::updateOrCreate(
                    ['name' => $name],
                    [
                        'year_level' => $year,
                        'academic_year' => self::ACADEMIC_YEAR,
                        'semester_name' => $semester,
                        'preferred_days' => [1, 2, 3, 4, 5],
                        'preferred_start_time' => '07:00',
                        // 07:00-19:00 (11 usable hours a day after the 12-1 PM
                        // break) gives the solver real slack: a Year 3 section
                        // carries ~37 session-hours across 14 blocks, which only
                        // just fits a shorter window and leaves sessions unscheduled.
                        'preferred_end_time' => '19:00',
                        'student_count' => self::STUDENT_COUNT_BY_YEAR[$year],
                    ]
                );
            }
        }

        return $sections;
    }

    /**
     * @param array<string, Section> $sections
     * @param array<string, Subject> $subjects
     */
    private function attachSubjectsToSections(array $sections, array $subjects): void
    {
        foreach ($sections as $section) {
            $codes = array_keys(array_filter(
                $subjects,
                fn (Subject $subject) => (int) $subject->year_level === (int) $section->year_level
                    && $subject->semester_name === $section->semester_name
            ));

            $section->subjects()->syncWithoutDetaching(
                array_map(fn (string $code) => $subjects[$code]->id, $codes)
            );
        }
    }

    /**
     * Demo faculty, their subject loads, and a Mon-Fri 07:00-19:00 availability
     * window that matches the section window above (a session must sit inside
     * both, so a narrower faculty window silently caps what the solver can place).
     *
     * Availability is matched on (faculty, day) so a re-run corrects the window
     * instead of duplicating rows; a faculty with other declared days keeps them.
     */
    private function seedFaculty(): void
    {
        $roster = [
            ['name' => 'Engr. Alvin Bacalso', 'subjects' => ['ELEC101', 'ELEC201']],
            ['name' => 'Dr. Marilou Sanchez', 'subjects' => ['GE101', 'GEE 3']],
            ['name' => 'Prof. Noel Abella', 'subjects' => ['GE102', 'GE 7']],
            ['name' => 'Ms. Kristine Dela Cruz', 'subjects' => ['MATH101', 'TPCC 312']],
            ['name' => 'Mr. Jayson Velasco', 'subjects' => ['PROG101', 'PROG201', 'PROG3']],
            ['name' => 'Ms. Andrea Lim', 'subjects' => ['DSA201', 'TPCC 311']],
            ['name' => 'Mr. Ronald Espina', 'subjects' => ['DB201', 'TPCC 314']],
            ['name' => 'Ms. Shiela Marie Ocampo', 'subjects' => ['NET201', 'TPCC 312']],
            ['name' => 'Prof. Eduardo Narciso', 'subjects' => ['TPCC 313', 'FL 1']],
            ['name' => 'Mrs. Grace Villanueva', 'subjects' => ['TACC 311', 'TACC 312']],
            ['name' => 'Mr. Dennis Cabahug', 'subjects' => ['FL 1', 'GE 7']],
            ['name' => 'Ms. Aileen Torregosa', 'subjects' => ['TPCC 311', 'DSA201']],
            ['name' => 'Mr. Philip Andaya', 'subjects' => ['ELEC201', 'PROG101']],
            ['name' => 'Ms. Ruby Salazar', 'subjects' => ['TACC 312', 'GEE 3']],
        ];

        foreach ($roster as $entry) {
            $faculty = Faculty::firstOrCreate(
                ['name' => $entry['name']],
                ['faculty_type' => 'full_time', 'max_teaching_load' => 24, 'is_active' => true]
            );

            $subjectIds = Subject::whereIn('code', $entry['subjects'])->pluck('id')->all();
            $faculty->subjects()->syncWithoutDetaching($subjectIds);

            foreach ([1, 2, 3, 4, 5] as $day) {
                FacultyAvailability::updateOrCreate(
                    ['faculty_id' => $faculty->id, 'day_of_week' => $day],
                    ['start_time' => '07:00', 'end_time' => '19:00']
                );
            }
        }
    }
}
