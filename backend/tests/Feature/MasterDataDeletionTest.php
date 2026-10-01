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
 * Master data cleanup on delete.
 *
 * schedule_sessions references subjects, faculties and rooms through RESTRICT
 * foreign keys — Postgres refuses the delete while a session still points at
 * the record. That used to surface as a raw SQLSTATE[23503] 500 on the
 * Subjects page, because Subject was the only one of the three models without
 * a deleting hook (Faculty and Room already had one).
 *
 * These tests pin the intended behaviour: deleting master data clears the
 * sessions that referenced it, so the action succeeds instead of 500-ing.
 */
class MasterDataDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Department Head',
            'email' => 'admin@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    private function subject(string $code = 'PROG1'): Subject
    {
        return Subject::create([
            'code' => $code,
            'title' => 'Programming 1',
            'year_level' => 1,
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'lab_room_type' => null,
            'is_active' => true,
        ]);
    }

    private function faculty(string $name = 'Prof. Reyes'): Faculty
    {
        return Faculty::create([
            'name' => $name,
            'faculty_type' => 'full_time',
            'max_teaching_load' => 24,
        ]);
    }

    private function room(string $name = 'R101'): Room
    {
        return Room::create(['name' => $name, 'type' => 'lecture', 'capacity' => 40]);
    }

    private function section(string $name = 'BSIT 1A'): Section
    {
        return Section::create([
            'name' => $name,
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3, 4, 5],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '15:00',
        ]);
    }

    /**
     * A scheduled session that ties one subject, one faculty member and one
     * room together — the exact shape that used to block deletion.
     */
    private function scheduledSession(): array
    {
        $subject = $this->subject();
        $faculty = $this->faculty();
        $room = $this->room();
        $section = $this->section();

        $schedule = Schedule::create([
            'section_id' => $section->id,
            'status' => 'draft',
        ]);

        ScheduleSession::create([
            'schedule_id' => $schedule->id,
            'subject_id' => $subject->id,
            'faculty_id' => $faculty->id,
            'room_id' => $room->id,
            'session_type' => 'lecture',
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '10:00',
        ]);

        return compact('subject', 'faculty', 'room', 'section', 'schedule');
    }

    #[Test]
    public function a_subject_that_is_already_in_a_schedule_can_be_deleted(): void
    {
        ['subject' => $subject] = $this->scheduledSession();

        $this->assertSame(1, ScheduleSession::count());

        $this->actingAs($this->admin)
            ->deleteJson("/api/subjects/{$subject->id}")
            ->assertOk();

        $this->assertSame(0, Subject::count(), 'Subject survived the delete.');
        $this->assertSame(
            0,
            ScheduleSession::where('subject_id', $subject->id)->count(),
            'Sessions still reference the deleted subject (FK violation).'
        );
    }

    #[Test]
    public function deleting_a_subject_detaches_it_from_faculty_and_sections(): void
    {
        ['subject' => $subject, 'faculty' => $faculty, 'section' => $section] =
            $this->scheduledSession();

        $faculty->subjects()->attach($subject->id);
        $section->subjects()->attach($subject->id);

        $this->assertSame(1, $faculty->subjects()->count());
        $this->assertSame(1, $section->subjects()->count());

        $this->actingAs($this->admin)
            ->deleteJson("/api/subjects/{$subject->id}")
            ->assertOk();

        $this->assertSame(0, $faculty->subjects()->count(), 'faculty_subjects row left behind.');
        $this->assertSame(0, $section->subjects()->count(), 'section_subjects row left behind.');
    }

    #[Test]
    public function a_faculty_member_that_is_already_in_a_schedule_can_be_deleted(): void
    {
        ['faculty' => $faculty] = $this->scheduledSession();

        $this->actingAs($this->admin)
            ->deleteJson("/api/faculties/{$faculty->id}")
            ->assertOk();

        $this->assertSame(0, Faculty::count());
        $this->assertSame(0, ScheduleSession::where('faculty_id', $faculty->id)->count());
    }

    #[Test]
    public function a_room_that_is_already_in_a_schedule_can_be_deleted(): void
    {
        ['room' => $room] = $this->scheduledSession();

        $this->actingAs($this->admin)
            ->deleteJson("/api/rooms/{$room->id}")
            ->assertOk();

        $this->assertSame(0, Room::count());
        $this->assertSame(0, ScheduleSession::where('room_id', $room->id)->count());
    }

    #[Test]
    public function deleting_a_section_removes_its_schedules_and_sessions(): void
    {
        ['section' => $section, 'schedule' => $schedule] = $this->scheduledSession();

        $this->actingAs($this->admin)
            ->deleteJson("/api/sections/{$section->id}")
            ->assertOk();

        $this->assertSame(0, Section::count());
        $this->assertSame(0, Schedule::count(), 'Schedule outlived its section.');
        $this->assertSame(0, ScheduleSession::count(), 'Session outlived its section.');
    }

    #[Test]
    public function deleting_unreferenced_master_data_still_works(): void
    {
        $subject = $this->subject('UNUSED1');
        $faculty = $this->faculty('Prof. Santos');
        $room = $this->room('R102');

        $this->actingAs($this->admin)->deleteJson("/api/subjects/{$subject->id}")->assertOk();
        $this->actingAs($this->admin)->deleteJson("/api/faculties/{$faculty->id}")->assertOk();
        $this->actingAs($this->admin)->deleteJson("/api/rooms/{$room->id}")->assertOk();

        $this->assertSame(0, Subject::count());
        $this->assertSame(0, Faculty::count());
        $this->assertSame(0, Room::count());
    }
}
