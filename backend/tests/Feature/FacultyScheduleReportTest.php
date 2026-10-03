<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleSession;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The per-faculty printable schedule must only ever contain PUBLISHED work.
 *
 * A draft is still editable and an approved schedule is not yet the timetable
 * students and faculty are given, so neither may appear on a sheet that gets
 * handed out. A test covering only the happy path would not catch a leak of
 * unpublished work, so the draft and approved cases are pinned explicitly.
 */
class FacultyScheduleReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Faculty $faculty;
    private Room $room;
    private Subject $subject;
    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Department Head',
            'email' => 'admin@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->faculty = Faculty::create([
            'name' => 'Prof. Schedule',
            'employee_no' => 'EMP-100',
            'faculty_type' => 'full_time',
            'max_teaching_load' => 24,
            'is_active' => true,
        ]);

        $this->room = Room::create([
            'name' => 'R201', 'type' => 'lecture', 'capacity' => 40, 'status' => 'available',
        ]);

        $this->subject = Subject::create([
            'code' => 'PROG2', 'title' => 'Programming 2', 'year_level' => 2,
            'semester_name' => '1st Semester', 'lecture_hours' => 2, 'lab_hours' => 0,
            'is_active' => true,
        ]);

        $this->section = Section::create([
            'name' => 'BSIT 2B',
            'year_level' => 2,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
            'student_count' => 30,
        ]);
    }

    /**
     * A schedule for this section holding one session, at the given status.
     */
    private function makeSchedule(string $status, int $day = 1, string $start = '09:00:00', string $end = '11:00:00'): Schedule
    {
        $schedule = Schedule::create([
            'section_id' => $this->section->id,
            'status' => $status,
        ]);

        ScheduleSession::create([
            'schedule_id' => $schedule->id,
            'subject_id' => $this->subject->id,
            'faculty_id' => $this->faculty->id,
            'room_id' => $this->room->id,
            'session_type' => 'lecture',
            'day_of_week' => $day,
            'start_time' => $start,
            'end_time' => $end,
        ]);

        return $schedule;
    }

    #[Test]
    public function published_sessions_appear_with_section_room_and_time(): void
    {
        $this->makeSchedule('published');

        $response = $this->actingAs($this->admin)
            ->getJson("/api/reports/faculty/{$this->faculty->id}/schedule");

        $response->assertOk();
        $response->assertJsonPath('faculty.name', 'Prof. Schedule');
        $response->assertJsonPath('faculty.max_teaching_load', 24);
        $response->assertJsonPath('total_hours', 2);

        $sessions = $response->json('sessions');
        $this->assertCount(1, $sessions);

        // The fields the printed sheet needs: what, where, and when.
        $this->assertSame('PROG2', $sessions[0]['subject_code']);
        $this->assertSame('BSIT 2B', $sessions[0]['section']);
        $this->assertSame('R201', $sessions[0]['room']);
        $this->assertSame('09:00', $sessions[0]['start_time']);
        $this->assertSame('11:00', $sessions[0]['end_time']);
        $this->assertSame(1, $sessions[0]['day_of_week']);

        // Sections taught, so the sheet can name what the load is for.
        $this->assertSame(['BSIT 2B'], $response->json('distinct_sections'));
        $this->assertSame(2, $response->json('hours_per_day.1'));
    }

    #[Test]
    public function draft_sessions_are_excluded(): void
    {
        $this->makeSchedule('draft');

        $response = $this->actingAs($this->admin)
            ->getJson("/api/reports/faculty/{$this->faculty->id}/schedule");

        $response->assertOk();
        $response->assertJsonPath('total_hours', 0);
        $this->assertCount(0, $response->json('sessions'));
    }

    #[Test]
    public function approved_sessions_are_excluded(): void
    {
        // Approved is not yet the distributed timetable, so it stays off the sheet.
        $this->makeSchedule('approved');

        $response = $this->actingAs($this->admin)
            ->getJson("/api/reports/faculty/{$this->faculty->id}/schedule");

        $response->assertOk();
        $response->assertJsonPath('total_hours', 0);
        $this->assertCount(0, $response->json('sessions'));
    }

    #[Test]
    public function sessions_are_ordered_by_day_then_start_time(): void
    {
        // Inserted out of order on purpose: the sheet must read like a week.
        $this->makeSchedule('published', 3, '13:00:00', '14:00:00');
        $this->makeSchedule('published', 1, '15:00:00', '16:00:00');
        $this->makeSchedule('published', 1, '08:00:00', '09:00:00');

        $sessions = $this->actingAs($this->admin)
            ->getJson("/api/reports/faculty/{$this->faculty->id}/schedule")
            ->json('sessions');

        $this->assertSame(
            [[1, '08:00'], [1, '15:00'], [3, '13:00']],
            array_map(fn ($s) => [$s['day_of_week'], $s['start_time']], $sessions)
        );
    }
}
