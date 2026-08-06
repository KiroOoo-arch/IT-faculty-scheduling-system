<?php

namespace Database\Seeders;

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
        $prog2 = Subject::create([
            'code' => 'PROG2', 'title' => 'Programming 2', 'year_level' => 1,
            'semester_name' => '1st Semester', 'lecture_hours' => 2, 'lab_hours' => 3,
            'lab_room_type' => 'computer_lab',
        ]);
        $math1 = Subject::create([
            'code' => 'MATH1', 'title' => 'College Algebra', 'year_level' => 1,
            'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 0,
        ]);

        // Link the section to the subjects it takes
        $section->subjects()->attach([$prog1->id, $prog2->id, $math1->id]);

        // Faculty (each with a User account)
        $reyesUser = User::create(['name' => 'Prof. Reyes', 'email' => 'reyes@example.com', 'password' => bcrypt('password'), 'role' => 'faculty']);
        $reyes = Faculty::create(['user_id' => $reyesUser->id, 'faculty_type' => 'full_time']);
        $reyes->subjects()->attach([$prog1->id, $prog2->id]);
        FacultyAvailability::create(['faculty_id' => $reyes->id, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '15:00']);
        FacultyAvailability::create(['faculty_id' => $reyes->id, 'day_of_week' => 3, 'start_time' => '07:00', 'end_time' => '15:00']);
        FacultyAvailability::create(['faculty_id' => $reyes->id, 'day_of_week' => 5, 'start_time' => '07:00', 'end_time' => '15:00']);

        $santosUser = User::create(['name' => 'Prof. Santos', 'email' => 'santos@example.com', 'password' => bcrypt('password'), 'role' => 'faculty']);
        $santos = Faculty::create(['user_id' => $santosUser->id, 'faculty_type' => 'full_time']);
        $santos->subjects()->attach([$math1->id]);
        foreach ([2, 4] as $day) {
            FacultyAvailability::create(['faculty_id' => $santos->id, 'day_of_week' => $day, 'start_time' => '07:00', 'end_time' => '15:00']);
        }

        $cruzUser = User::create(['name' => 'Prof. Cruz', 'email' => 'cruz@example.com', 'password' => bcrypt('password'), 'role' => 'faculty']);
        $cruz = Faculty::create(['user_id' => $cruzUser->id, 'faculty_type' => 'part_time']);
        $cruz->subjects()->attach([$prog2->id]);
        foreach ([1, 3, 5] as $day) {
            FacultyAvailability::create(['faculty_id' => $cruz->id, 'day_of_week' => $day, 'start_time' => '07:00', 'end_time' => '15:00']);
        }
    }
}

#* DatabaseSeeder
 #* Seeds the database with initial data:
 #* - creates admin and faculty users
 #* - creates rooms, section, and subjects
 #* - assigns subjects to the section
 #* - links faculty to subjects and availability