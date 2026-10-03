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
 * The delete button follows the approval cycle: a schedule is deletable at
 * every step where it is NOT the live timetable.
 *
 *  - draft, approved and archived → deletable
 *  - published → protected, must be unpublished first (422 naming the status)
 *
 * The approved case matters most in practice: an approved schedule blocks
 * publish-time conflict checks for other sections, so being able to discard a
 * stale one is what lets the admin clear a clash without hand-editing sessions.
 */
class ScheduleDeleteLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Section $section;
    private Room $room;
    private Subject $subject;
    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Department Head',
            'email' => 'admin@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->room = Room::create([
            'name' => 'R101',
            'type' => 'lecture',
            'capacity' => 40,
            'status' => 'available',
        ]);

        $this->subject = Subject::create([
            'code' => 'PROG1',
            'title' => 'Programming 1',
            'year_level' => 1,
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'is_active' => true,
        ]);

        $this->faculty = Faculty::create([
            'name' => 'Prof. Deletable',
            'employee_no' => 'EMP-900',
            'faculty_type' => 'full_time',
            'max_teaching_load' => 24,
            'is_active' => true,
        ]);

        $this->section = Section::create([
            'name' => 'BSIT 1A',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3, 4, 5],
            'preferred_start_time' => '07:00:00',
            'preferred_end_time' => '21:00:00',
            'student_count' => 30,
        ]);
    }

    private function makeSchedule(string $status, ?Section $section = null, string $start = '09:00:00'): Schedule
    {
        $schedule = Schedule::create([
            'section_id' => ($section ?? $this->section)->id,
            'status' => $status,
        ]);

        ScheduleSession::create([
            'schedule_id' => $schedule->id,
            'subject_id' => $this->subject->id,
            'faculty_id' => $this->faculty->id,
            'room_id' => $this->room->id,
            'session_type' => 'lecture',
            'day_of_week' => 1,
            'start_time' => $start,
            'end_time' => '11:00:00',
        ]);

        return $schedule;
    }

    #[Test]
    public function draft_schedule_can_be_deleted_with_its_sessions(): void
    {
        $schedule = $this->makeSchedule('draft');
        $sessionId = $schedule->sessions()->first()->id;

        $this->actingAs($this->admin)
            ->deleteJson("/api/schedules/{$schedule->id}")
            ->assertOk()
            ->assertJson(['message' => 'Schedule deleted successfully']);

        $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
        $this->assertDatabaseMissing('schedule_sessions', ['id' => $sessionId]);
    }

    #[Test]
    public function approved_schedule_can_be_deleted(): void
    {
        $schedule = $this->makeSchedule('approved');

        $this->actingAs($this->admin)
            ->deleteJson("/api/schedules/{$schedule->id}")
            ->assertOk();

        $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
        $this->assertDatabaseMissing('schedule_sessions', ['schedule_id' => $schedule->id]);
    }

    #[Test]
    public function archived_schedule_can_be_deleted(): void
    {
        $schedule = $this->makeSchedule('archived');

        $this->actingAs($this->admin)
            ->deleteJson("/api/schedules/{$schedule->id}")
            ->assertOk();

        $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
    }

    #[Test]
    public function rejected_schedule_can_be_deleted_so_the_state_is_not_a_dead_end(): void
    {
        // Nothing in the UI writes `rejected`, but the API can, and the state
        // used to be unclearable (neither approved nor published nor archived).
        $schedule = $this->makeSchedule('rejected');

        $this->actingAs($this->admin)
            ->deleteJson("/api/schedules/{$schedule->id}")
            ->assertOk();

        $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
    }

    #[Test]
    public function published_schedule_is_protected_and_the_message_names_its_status(): void
    {
        $schedule = $this->makeSchedule('published');

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/schedules/{$schedule->id}");

        $response->assertStatus(422);
        $this->assertStringContainsString('published', $response->json('message'));

        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'published']);
        $this->assertDatabaseHas('schedule_sessions', ['schedule_id' => $schedule->id]);
    }

    #[Test]
    public function published_schedule_can_be_deleted_once_unpublished(): void
    {
        $schedule = $this->makeSchedule('published');

        $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$schedule->id}/unpublish")
            ->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson("/api/schedules/{$schedule->id}")
            ->assertOk();

        $this->assertDatabaseMissing('schedules', ['id' => $schedule->id]);
    }

    #[Test]
    public function deleting_a_stale_approved_schedule_clears_the_publish_conflict_it_caused(): void
    {
        $other = Section::create([
            'name' => 'BSIT 1B',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3, 4, 5],
            'preferred_start_time' => '07:00:00',
            'preferred_end_time' => '21:00:00',
            'student_count' => 30,
        ]);

        // Same day, same room and same faculty as the schedule below — while
        // both are approved/published, the later publish must be refused.
        // The blocker is *approved*, which is the case the delete button newly
        // covers; a published blocker still has to be unpublished first.
        $stale = $this->makeSchedule('approved', $other, '10:00:00');
        $candidate = $this->makeSchedule('approved');

        $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$candidate->id}/publish")
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'conflicts']);

        // Refused for the right reason: an overlapping booking, not a bad state.
        $conflicts = implode(' ', $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$candidate->id}/publish")
            ->json('conflicts'));
        $this->assertStringContainsString('R101', $conflicts);

        // The clash is cleared by discarding the schedule causing it, with no
        // editing of any session.
        $this->actingAs($this->admin)
            ->deleteJson("/api/schedules/{$stale->id}")
            ->assertOk();

        $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$candidate->id}/publish")
            ->assertOk()
            ->assertJsonPath('status', 'published');
    }

    #[Test]
    public function a_non_admin_cannot_delete_a_schedule(): void
    {
        $schedule = $this->makeSchedule('draft');

        $user = User::create([
            'name' => 'Ordinary User',
            'email' => 'user@test.local',
            'password' => bcrypt('password'),
            'role' => 'faculty',
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/schedules/{$schedule->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('schedules', ['id' => $schedule->id]);
    }
}
