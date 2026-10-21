<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\FacultyAvailability;
use App\Models\Room;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        $adminUser = User::create([
            'name' => 'Department Head',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        // Rooms
        $r101 = Room::create(['name' => 'R101', 'type' => 'lecture', 'capacity' => 40]);
        $lab1 = Room::create(['name' => 'LAB1', 'type' => 'computer_lab', 'capacity' => 30]);

        // Section
        $section = Section::create([
            'name' => 'BSIT 1A',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3, 4, 5],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '15:00',
        ]);

        // Subjects
        $prog1 = Subject::create([
            'code' => 'PROG1', 'title' => 'Programming 1', 'year_level' => 1,
            'semester_name' => '1st Semester', 'lecture_hours' => 2, 'lab_hours' => 3,
            'lab_room_type' => 'computer_lab',
        ]);
        // A reviewed major of the IT department, like PROG1. The demo set uses
        // codes from the approved curriculum on purpose: a code on no list would
        // stay unclassified, and an unclassified subject cannot be scheduled, so
        // the demo section could not be generated for at all.
        $prog3 = Subject::create([
            'code' => 'PROG3', 'title' => 'Programming 3', 'year_level' => 1,
            'semester_name' => '1st Semester', 'lecture_hours' => 2, 'lab_hours' => 3,
            'lab_room_type' => 'computer_lab',
        ]);
        $math1 = Subject::create([
            'code' => 'MATH1', 'title' => 'College Algebra', 'year_level' => 1,
            'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 0,
        ]);

        // Link the section to the subjects it takes
        $section->subjects()->attach([$prog1->id, $prog3->id, $math1->id]);

        // Faculty are records, not users — no login accounts (admin manages everything)
        $reyes = Faculty::create(['name' => 'Prof. Reyes', 'faculty_type' => 'full_time']);
        $reyes->subjects()->attach([$prog1->id, $prog3->id]);
        FacultyAvailability::create(['faculty_id' => $reyes->id, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '15:00']);
        FacultyAvailability::create(['faculty_id' => $reyes->id, 'day_of_week' => 3, 'start_time' => '07:00', 'end_time' => '15:00']);
        FacultyAvailability::create(['faculty_id' => $reyes->id, 'day_of_week' => 5, 'start_time' => '07:00', 'end_time' => '15:00']);

        $santos = Faculty::create(['name' => 'Prof. Santos', 'faculty_type' => 'full_time']);
        $santos->subjects()->attach([$math1->id]);
        foreach ([2, 4] as $day) {
            FacultyAvailability::create(['faculty_id' => $santos->id, 'day_of_week' => $day, 'start_time' => '07:00', 'end_time' => '15:00']);
        }

        $cruz = Faculty::create(['name' => 'Prof. Cruz', 'faculty_type' => 'part_time']);
        $cruz->subjects()->attach([$prog3->id]);
        foreach ([1, 3, 5] as $day) {
            FacultyAvailability::create(['faculty_id' => $cruz->id, 'day_of_week' => $day, 'start_time' => '07:00', 'end_time' => '15:00']);
        }

        // Departments, classification and access. Runs last because it claims
        // sections by name prefix, and the section above is created here. The
        // subjects above are deliberately left unclassified on creation and
        // classified by this pass, which is exactly what an activation does.
        $this->call(DepartmentSeeder::class);

        // The demo section is the IT programme's, so it can take the IT major
        // subjects attached above. Its name ('BSIT 1A') predates the BIT-
        // convention the seeder matches on, so it is claimed explicitly rather
        // than by widening that prefix match.
        $section->forceFill([
            'department_id' => Department::where('code', 'BIT')->value('id'),
        ])->save();
    }
} #* DatabaseSeeder
 #* Seeds the database with initial data:
 #* - creates the admin (Department Head) account — the only login
 #* - creates faculty records (no login accounts)
 #* - creates rooms, section, and subjects
 #* - assigns subjects to the section
 #* - links faculty to subjects and availability