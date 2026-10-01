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
 * The published-reference guard.
 *
 * Deleting master data clears the sessions that point at it, which is fine for
 * drafts and archives but wrong for a published schedule — the timetable of
 * record. These tests pin the rule: a delete that would change a published
 * timetable is refused with 409 (naming the schedule and the loss), and only
 * proceeds when the client retries with ?force=1.
 */
class PublishedReferenceGuardTest extends TestCase
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

    /**
     * One subject/faculty/room seated in one schedule of the given status.
     *
     * @return array{subject: Subject, faculty: Faculty, room: Room, section: Section, schedule: Schedule}
     */
    private function graph(string $status): array
    {
        $subject = Subject::create([
            'code' => 'PROG1',
            'title' => 'Programming 1',
            'year_level' => 1,
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'lab_room_type' => null,
            'is_active' => true,
        ]);

        $faculty = Faculty::create([
            'name' => 'Prof. Reyes',
            'faculty_type' => 'full_time',
            'max_teaching_load' => 24,
        ]);

        $room = Room::create(['name' => 'R101', 'type' => 'lecture', 'capacity' => 40]);

        $section = Section::create([
            'name' => 'BSIT 1A',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3, 4, 5],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '15:00',
        ]);

        $schedule = Schedule::create(['section_id' => $section->id, 'status' => $status]);

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
    public function deleting_a_room_used_by_a_published_schedule_is_refused(): void
    {
        ['room' => $room, 'schedule' => $schedule] = $this->graph('published');

        $this->actingAs($this->admin)
            ->deleteJson("/api/rooms/{$room->id}")
            ->assertStatus(409)
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('sessions_at_risk', 1)
            ->assertJsonPath('published_sessions_at_risk', 1)
            ->assertJsonPath('published_schedule_ids', [$schedule->id]);

        $this->assertSame(1, Room::count(), 'Room was deleted despite the published reference.');
        $this->assertSame(1, ScheduleSession::count(), 'Published session was removed without confirmation.');
    }

    #[Test]
    public function a_room_delete_can_be_forced_after_confirmation(): void
    {
        ['room' => $room] = $this->graph('published');

        $this->actingAs($this->admin)
            ->deleteJson("/api/rooms/{$room->id}?force=1")
            ->assertOk();

        $this->assertSame(0, Room::count());
        $this->assertSame(0, ScheduleSession::count());
    }

    #[Test]
    public function deleting_a_subject_used_by_a_published_schedule_is_refused(): void
    {
        ['subject' => $subject, 'schedule' => $schedule] = $this->graph('published');

        $this->actingAs($this->admin)
            ->deleteJson("/api/subjects/{$subject->id}")
            ->assertStatus(409)
            ->assertJsonPath('sessions_at_risk', 1)
            ->assertJsonPath('published_sessions_at_risk', 1)
            ->assertJsonPath('published_schedule_ids', [$schedule->id]);

        $this->assertSame(1, Subject::count());
        $this->assertSame(1, ScheduleSession::count());
    }

    #[Test]
    public function a_subject_delete_can_be_forced_after_confirmation(): void
    {
        ['subject' => $subject] = $this->graph('published');

        $this->actingAs($this->admin)
            ->deleteJson("/api/subjects/{$subject->id}?force=1")
            ->assertOk();

        $this->assertSame(0, Subject::count());
        $this->assertSame(0, ScheduleSession::count());
    }

    #[Test]
    public function deleting_a_section_with_a_published_schedule_is_refused(): void
    {
        ['section' => $section, 'schedule' => $schedule] = $this->graph('published');

        $this->actingAs($this->admin)
            ->deleteJson("/api/sections/{$section->id}")
            ->assertStatus(409)
            ->assertJsonPath('sessions_at_risk', 1)
            ->assertJsonPath('published_sessions_at_risk', 1)
            ->assertJsonPath('published_schedule_ids', [$schedule->id]);

        $this->assertSame(1, Section::count(), 'Section was deleted despite the published schedule.');
        $this->assertSame(1, Schedule::count(), 'Published schedule was cascaded away.');
    }

    #[Test]
    public function a_section_delete_can_be_forced_after_confirmation(): void
    {
        ['section' => $section] = $this->graph('published');

        $this->actingAs($this->admin)
            ->deleteJson("/api/sections/{$section->id}?force=1")
            ->assertOk();

        $this->assertSame(0, Section::count());
        $this->assertSame(0, Schedule::count());
        $this->assertSame(0, ScheduleSession::count());
    }

    #[Test]
    public function the_reported_loss_covers_every_session_not_just_published_ones(): void
    {
        ['room' => $room, 'subject' => $subject, 'faculty' => $faculty, 'section' => $section] =
            $this->graph('published');

        // The published schedule owns one session; two archived schedules also
        // use the same room. Deleting the room drops all three, so the guard
        // must not quote just the published one.
        foreach ([1, 2] as $offset) {
            $extra = Schedule::create(['section_id' => $section->id, 'status' => 'archived']);
            ScheduleSession::create([
                'schedule_id' => $extra->id,
                'subject_id' => $subject->id,
                'faculty_id' => $faculty->id,
                'room_id' => $room->id,
                'session_type' => 'lecture',
                'day_of_week' => $offset,
                'start_time' => '08:00',
                'end_time' => '10:00',
            ]);
        }

        $this->actingAs($this->admin)
            ->deleteJson("/api/rooms/{$room->id}")
            ->assertStatus(409)
            ->assertJsonPath('sessions_at_risk', 3)
            ->assertJsonPath('published_sessions_at_risk', 1);
    }

    #[Test]
    public function deleting_a_faculty_member_in_a_published_schedule_is_refused(): void
    {
        ['faculty' => $faculty, 'schedule' => $schedule] = $this->graph('published');

        $this->actingAs($this->admin)
            ->deleteJson("/api/faculties/{$faculty->id}")
            ->assertStatus(409)
            ->assertJsonPath('sessions_at_risk', 1)
            ->assertJsonPath('published_sessions_at_risk', 1)
            ->assertJsonPath('published_schedule_ids', [$schedule->id]);

        $this->assertSame(1, Faculty::count(), 'Faculty was deleted despite the published reference.');
        $this->assertSame(1, ScheduleSession::count(), 'Published session was removed without confirmation.');
    }

    #[Test]
    public function a_faculty_delete_can_be_forced_after_confirmation(): void
    {
        ['faculty' => $faculty] = $this->graph('published');

        $this->actingAs($this->admin)
            ->deleteJson("/api/faculties/{$faculty->id}?force=1")
            ->assertOk();

        $this->assertSame(0, Faculty::count());
        $this->assertSame(0, ScheduleSession::count());
    }

    #[Test]
    public function draft_and_archived_references_do_not_block_a_delete(): void
    {
        foreach (['draft', 'archived'] as $status) {
            ['subject' => $subject, 'faculty' => $faculty, 'room' => $room, 'section' => $section] =
                $this->graph($status);

            $this->actingAs($this->admin)
                ->deleteJson("/api/rooms/{$room->id}")
                ->assertOk();
            $this->actingAs($this->admin)
                ->deleteJson("/api/subjects/{$subject->id}")
                ->assertOk();
            $this->actingAs($this->admin)
                ->deleteJson("/api/faculties/{$faculty->id}")
                ->assertOk();
            $this->actingAs($this->admin)
                ->deleteJson("/api/sections/{$section->id}")
                ->assertOk();

            $this->assertSame(0, Room::count(), "Room with {$status} reference should delete freely.");
            $this->assertSame(0, Subject::count(), "Subject with {$status} reference should delete freely.");
            $this->assertSame(0, Faculty::count(), "Faculty with {$status} reference should delete freely.");
            $this->assertSame(0, Schedule::count(), "Section with {$status} reference should cascade freely.");
        }
    }

    #[Test]
    public function unreferenced_master_data_is_unaffected(): void
    {
        $subject = Subject::create([
            'code' => 'UNUSED1',
            'title' => 'Unused',
            'year_level' => 1,
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'lab_room_type' => null,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/subjects/{$subject->id}")
            ->assertOk();

        $this->assertSame(0, Subject::count());
    }
}
