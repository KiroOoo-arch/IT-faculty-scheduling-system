<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\FacultyAvailability;
use App\Models\Room;
use App\Models\Section;
use App\Models\Schedule;
use App\Models\ScheduleSession;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies that AI schedule generation is attributed to the CORRECT
 * section and year level, and that the generated sessions belong to
 * that section's schedule only.
 */
class ScheduleGenerationSectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Section $section;
    private Faculty $faculty;
    private Subject $subject;
    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Department Head',
            'email' => 'admin@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        // A 2nd-year, 1st-semester section
        $this->section = Section::create([
            'name' => 'BSIT 2A',
            'year_level' => 2,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
            'student_count' => 30,
        ]);

        $this->subject = Subject::create([
            'code' => 'PROG2',
            'title' => 'Programming 2',
            'year_level' => 2,
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => 0,
        ]);

        $this->faculty = Faculty::create([
            'name' => 'Prof. Test',
            'faculty_type' => 'full_time',
            'max_teaching_load' => 24,
            'is_active' => true,
        ]);

        $this->faculty->subjects()->attach($this->subject->id);
        FacultyAvailability::create([
            'faculty_id' => $this->faculty->id,
            'day_of_week' => 1,
            'start_time' => '07:00',
            'end_time' => '18:00',
        ]);

        $this->room = Room::create(['name' => 'R101', 'type' => 'lecture', 'capacity' => 40, 'status' => 'available']);

        $this->section->subjects()->attach($this->subject->id);
    }

    private function fakeAiEngine(): void
    {
        // The AI engine responds with a session for OUR section's subject,
        // assigned to OUR faculty and room.
        Http::fake([
            '127.0.0.1:8001/generate-schedule/*' => Http::response([
                'status' => 'OPTIMAL',
                'message' => null,
                'sessions' => [
                    [
                        'subject_id' => $this->subject->id,
                        'faculty_id' => $this->faculty->id,
                        'room_id' => $this->room->id,
                        'session_type' => 'lecture',
                        'day_of_week' => 1,
                        'start_hour' => 8,
                        'end_hour' => 10,
                        'is_scheduled' => true,
                    ],
                ],
            ], 200),
        ]);
    }

    #[Test]
    public function generated_schedule_belongs_to_the_requested_section_and_year_level(): void
    {
        $this->fakeAiEngine();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/schedules/generate/{$this->section->id}");

        $response->assertOk()
            ->assertJsonPath('status', 'OPTIMAL');

        // Exactly one schedule was created, tied to the requested section
        $this->assertSame(1, Schedule::count());
        $schedule = Schedule::first();
        $this->assertSame($this->section->id, $schedule->section_id);
        $this->assertSame('BSIT 2A', $schedule->section->name);
        $this->assertSame(2, $schedule->section->year_level);
        $this->assertSame('1st Semester', $schedule->section->semester_name);

        // The schedule starts as a draft
        $this->assertSame('draft', $schedule->status);
    }

    #[Test]
    public function generated_sessions_reference_the_correct_section_subject_faculty_and_room(): void
    {
        $this->fakeAiEngine();

        $this->actingAs($this->admin)
            ->postJson("/api/schedules/generate/{$this->section->id}")
            ->assertOk();

        $schedule = Schedule::with(['section', 'sessions.subject', 'sessions.faculty', 'sessions.room'])->first();

        $this->assertSame(1, $schedule->sessions->count());

        $session = $schedule->sessions->first();
        $this->assertSame($this->subject->id, $session->subject_id);
        $this->assertSame('PROG2', $session->subject->code);
        $this->assertSame($this->faculty->id, $session->faculty_id);
        $this->assertSame('Prof. Test', $session->faculty->name);
        $this->assertSame($this->room->id, $session->room_id);
        $this->assertSame('lecture', $session->session_type);
        $this->assertSame(1, $session->day_of_week);
    }

    #[Test]
    public function generating_does_not_touch_other_sections_schedules(): void
    {
        // Another section at a different year level with its own (older) schedule
        $otherSection = Section::create([
            'name' => 'BSIT 4B',
            'year_level' => 4,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
            'student_count' => 25,
        ]);

        $otherSchedule = Schedule::create(['section_id' => $otherSection->id, 'status' => 'published']);

        $this->fakeAiEngine();

        $this->actingAs($this->admin)
            ->postJson("/api/schedules/generate/{$this->section->id}")
            ->assertOk();

        // The other section's published schedule is untouched
        $otherSchedule->refresh();
        $this->assertSame('published', $otherSchedule->status);
        $this->assertSame($otherSection->id, $otherSchedule->section_id);
        $this->assertSame(4, $otherSchedule->section->year_level);

        // And no session leaked into it
        $this->assertSame(0, ScheduleSession::where('schedule_id', $otherSchedule->id)->count());
    }

    #[Test]
    public function regeneration_archives_only_the_target_sections_old_drafts(): void
    {
        $this->fakeAiEngine();

        // First generation
        $this->actingAs($this->admin)->postJson("/api/schedules/generate/{$this->section->id}")->assertOk();
        $firstId = Schedule::first()->id;

        // Second generation for the SAME section archives the first draft
        $this->actingAs($this->admin)->postJson("/api/schedules/generate/{$this->section->id}")->assertOk();

        $first = Schedule::find($firstId);
        $this->assertSame('archived', $first->status);
        $this->assertSame($this->section->id, $first->section_id);

        // The new draft belongs to the same section
        $newDraft = Schedule::where('status', 'draft')->first();
        $this->assertNotNull($newDraft);
        $this->assertSame($this->section->id, $newDraft->section_id);
    }
}
