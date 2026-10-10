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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-section conflicts at generation time.
 *
 * Two sections for the same term used to be able to claim the same faculty
 * member or room: the engine's `existing_sessions` only saw approved/published
 * schedules, so a sibling that was still a draft was invisible, and both drafts
 * held the slot until someone tried to publish the second one.
 *
 * These tests pin the two halves of the fix:
 *   1. the controller refuses to persist a generated plan that clashes with any
 *      other section's live timetable in the same term (draft, approved or
 *      published), leaving the section's existing draft untouched; and
 *   2. generation is serialised per term, so a contended run is refused with a
 *      409 rather than racing the run that holds the lock.
 *
 * The engine is faked: it answers with a plan this test chooses, which lets a
 * clash be constructed deterministically. What is under test is the controller's
 * conflict check, lock and transaction — not the solver's search.
 *
 * CONCURRENCY LIMITATION: the feature suite runs on the `array` cache store
 * (phpunit.xml), which only serialises a single process. The lock-contention
 * test below therefore proves the refusal path, not true cross-process
 * mutual exclusion. Verifying the latter needs an integration test against the
 * `database` cache store with two concurrent HTTP workers; see the report.
 */
class ScheduleGenerationConflictTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Subject $subject;
    private Faculty $facultyA;
    private Faculty $facultyB;
    private Room $roomA;
    private Room $roomB;
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

        $this->subject = Subject::create([
            'code' => 'PROG1',
            'title' => 'Programming 1',
            'year_level' => 1,
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => 0,
        ]);

        $this->facultyA = Faculty::create([
            'name' => 'Prof. Alpha',
            'faculty_type' => 'full_time',
            'max_teaching_load' => 24,
            'is_active' => true,
        ]);
        $this->facultyB = Faculty::create([
            'name' => 'Prof. Beta',
            'faculty_type' => 'full_time',
            'max_teaching_load' => 24,
            'is_active' => true,
        ]);

        $this->roomA = Room::create(['name' => 'R-A', 'type' => 'lecture', 'capacity' => 40, 'status' => 'available']);
        $this->roomB = Room::create(['name' => 'R-B', 'type' => 'lecture', 'capacity' => 40, 'status' => 'available']);

        // The section being generated: Year 1, 1st Semester, 2026-2027.
        $this->section = $this->makeSection('BSIT 1A');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeSection(
        string $name,
        string $academicYear = '2026-2027',
        string $semester = '1st Semester',
        int $yearLevel = 1
    ): Section {
        $section = Section::create([
            'name' => $name,
            'year_level' => $yearLevel,
            'academic_year' => $academicYear,
            'semester_name' => $semester,
            'preferred_days' => [1, 2, 3, 4, 5],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
            'student_count' => 30,
        ]);

        // The generation gate requires the section's subjects to match its year
        // level and semester, so the shared subject is attached everywhere.
        $section->subjects()->attach($this->subject->id);

        return $section;
    }

    private function makeSchedule(Section $section, string $status): Schedule
    {
        return Schedule::create(['section_id' => $section->id, 'status' => $status]);
    }

    private function addSession(
        Schedule $schedule,
        Faculty $faculty,
        Room $room,
        int $day,
        string $start,
        string $end
    ): ScheduleSession {
        return ScheduleSession::create([
            'schedule_id' => $schedule->id,
            'subject_id' => $this->subject->id,
            'faculty_id' => $faculty->id,
            'room_id' => $room->id,
            'session_type' => 'lecture',
            'day_of_week' => $day,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    /** One engine session in the real engine's shape (wall-clock HH:MM). */
    private function engineSession(array $overrides = []): array
    {
        return array_merge([
            'subject_id' => $this->subject->id,
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'session_type' => 'lecture',
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '10:00',
            'start_hour' => 8,
            'end_hour' => 10,
            'is_scheduled' => true,
        ], $overrides);
    }

    private function fakeEngine(array $sessions): void
    {
        Http::fake([
            '127.0.0.1:8001/generate-schedule/*' => Http::response([
                'status' => 'OPTIMAL',
                'message' => null,
                'sessions' => array_map(
                    fn (array $s) => $s + ['is_scheduled' => true],
                    $sessions
                ),
            ], 200),
        ]);
    }

    private function generateFor(Section $section)
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/schedules/generate/{$section->id}");
    }

    // -----------------------------------------------------------------------
    // Draft-vs-draft conflicts
    // -----------------------------------------------------------------------

    #[Test]
    public function another_sections_draft_overlapping_a_faculty_member_is_rejected(): void
    {
        // A sibling section in the SAME term shares Prof. Alpha in its draft.
        $sibling = $this->makeSection('BSIT 1B');
        $siblingDraft = $this->makeSchedule($sibling, 'draft');
        $this->addSession($siblingDraft, $this->facultyA, $this->roomB, 1, '08:00', '10:00');

        // The target already has a draft the admin is working on.
        $existing = $this->makeSchedule($this->section, 'draft');
        $this->addSession($existing, $this->facultyB, $this->roomB, 2, '13:00', '15:00');

        // The engine answers with Prof. Alpha at an overlapping time.
        $this->fakeEngine([$this->engineSession([
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '11:00',
        ])]);

        $response = $this->generateFor($this->section);

        $response->assertStatus(422)->assertJsonStructure(['message', 'conflicts']);
        $this->assertNotEmpty($response->json('conflicts'));

        // The existing draft survived: nothing was archived, nothing replaced.
        $existing->refresh();
        $this->assertSame('draft', $existing->status);
        $this->assertSame(1, $existing->sessions()->count());
        $this->assertSame(
            1,
            Schedule::where('section_id', $this->section->id)->count(),
            'A conflicting plan must not create a new draft.'
        );
        $this->assertSame(0, Schedule::where('status', 'archived')->count());

        // The sibling draft is untouched too.
        $siblingDraft->refresh();
        $this->assertSame('draft', $siblingDraft->status);
        $this->assertSame(1, $siblingDraft->sessions()->count());
    }

    #[Test]
    public function another_sections_draft_overlapping_a_room_is_rejected(): void
    {
        $sibling = $this->makeSection('BSIT 1C');
        $siblingDraft = $this->makeSchedule($sibling, 'draft');
        // Same room, different faculty: isolates the room rule.
        $this->addSession($siblingDraft, $this->facultyB, $this->roomA, 1, '08:00', '10:00');

        $existing = $this->makeSchedule($this->section, 'draft');
        $this->addSession($existing, $this->facultyB, $this->roomB, 2, '13:00', '15:00');

        $this->fakeEngine([$this->engineSession([
            'faculty_id' => $this->facultyA->id,   // not the sibling's faculty
            'room_id' => $this->roomA->id,         // the sibling's room
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '11:00',
        ])]);

        $response = $this->generateFor($this->section);

        $response->assertStatus(422);
        $joined = implode(' ', $response->json('conflicts'));
        $this->assertStringContainsString('R-A', $joined, 'The shared room must be named.');

        $existing->refresh();
        $this->assertSame('draft', $existing->status);
        $this->assertSame(1, $existing->sessions()->count());
        $this->assertSame(0, Schedule::where('status', 'archived')->count());
    }

    // -----------------------------------------------------------------------
    // Non-conflicting plans and other term scopes
    // -----------------------------------------------------------------------

    #[Test]
    public function a_non_overlapping_plan_is_accepted_and_persisted(): void
    {
        $sibling = $this->makeSection('BSIT 1D');
        $siblingDraft = $this->makeSchedule($sibling, 'draft');
        $this->addSession($siblingDraft, $this->facultyA, $this->roomA, 1, '08:00', '10:00');

        // Different day: genuinely free, so it must be accepted.
        $this->fakeEngine([$this->engineSession([
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'day_of_week' => 3,
            'start_time' => '08:00',
            'end_time' => '10:00',
        ])]);

        $this->generateFor($this->section)->assertOk();

        $draft = Schedule::where('section_id', $this->section->id)->firstOrFail();
        $this->assertSame('draft', $draft->status);
        $this->assertSame(1, $draft->sessions()->count());
        $this->assertSame(3, $draft->sessions()->first()->day_of_week);
    }

    #[Test]
    public function approved_and_published_schedules_are_protected(): void
    {
        // An approved schedule already teaches Prof. Alpha that morning...
        $approvedSection = $this->makeSection('BSIT 1G');
        $approved = $this->makeSchedule($approvedSection, 'approved');
        $this->addSession($approved, $this->facultyA, $this->roomB, 1, '08:00', '10:00');

        // ...and a published one already holds room R-A.
        $publishedSection = $this->makeSection('BSIT 1H');
        $published = $this->makeSchedule($publishedSection, 'published');
        $this->addSession($published, $this->facultyB, $this->roomA, 1, '08:00', '10:00');

        // The generated plan overlaps both: Prof. Alpha AND room R-A.
        $this->fakeEngine([$this->engineSession([
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '11:00',
        ])]);

        $response = $this->generateFor($this->section);

        $response->assertStatus(422);
        $joined = implode(' ', $response->json('conflicts'));
        $this->assertStringContainsString('Faculty', $joined);
        $this->assertStringContainsString('Room', $joined);

        // Nothing was written for the target, and the approved/published
        // schedules keep their status.
        $this->assertSame(0, Schedule::where('section_id', $this->section->id)->count());
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('published', $published->fresh()->status);
    }

    #[Test]
    public function schedules_in_another_term_do_not_cause_false_conflicts(): void
    {
        // Same faculty and room, but a different academic year...
        $otherYear = $this->makeSection('BSIT 1E', academicYear: '2025-2026');
        $otherYearDraft = $this->makeSchedule($otherYear, 'draft');
        $this->addSession($otherYearDraft, $this->facultyA, $this->roomA, 1, '08:00', '10:00');

        // ...and the same year but a different semester.
        $otherSemester = $this->makeSection('BSIT 1F', semester: '2nd Semester');
        $otherSemesterDraft = $this->makeSchedule($otherSemester, 'draft');
        $this->addSession($otherSemesterDraft, $this->facultyA, $this->roomA, 1, '08:00', '10:00');

        // The generated plan reuses that faculty and room on the same day and
        // time — which is perfectly valid, because it is a different term.
        $this->fakeEngine([$this->engineSession([
            'faculty_id' => $this->facultyA->id,
            'room_id' => $this->roomA->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '10:00',
        ])]);

        $this->generateFor($this->section)->assertOk();

        $this->assertSame(1, Schedule::where('section_id', $this->section->id)->count());
    }

    // -----------------------------------------------------------------------
    // Preservation, atomicity and regeneration
    // -----------------------------------------------------------------------

    #[Test]
    public function an_engine_failure_preserves_the_existing_draft(): void
    {
        $existing = $this->makeSchedule($this->section, 'draft');
        $this->addSession($existing, $this->facultyB, $this->roomB, 2, '13:00', '15:00');

        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $this->generateFor($this->section)
            ->assertStatus(502)
            ->assertJsonPath('error', 'AI engine unreachable');

        $existing->refresh();
        $this->assertSame('draft', $existing->status);
        $this->assertSame(1, $existing->sessions()->count());
        $this->assertSame(0, Schedule::where('status', 'archived')->count());
    }

    #[Test]
    public function successful_regeneration_archives_the_old_draft_and_keeps_one_active_draft(): void
    {
        $this->fakeEngine([$this->engineSession()]);

        $this->generateFor($this->section)->assertOk();
        $firstId = Schedule::where('section_id', $this->section->id)->firstOrFail()->id;

        $this->generateFor($this->section)->assertOk();

        $this->assertSame('archived', Schedule::findOrFail($firstId)->status);
        $this->assertSame(
            1,
            Schedule::where('section_id', $this->section->id)->where('status', 'draft')->count(),
            'Regeneration must leave exactly one active draft.'
        );
        $this->assertSame(1, Schedule::where('status', 'archived')->count());
    }

    #[Test]
    public function a_persistence_failure_rolls_back_the_draft_replacement(): void
    {
        $existing = $this->makeSchedule($this->section, 'draft');
        $this->addSession($existing, $this->facultyB, $this->roomB, 2, '13:00', '15:00');

        // A room id that does not exist: the session insert violates the foreign
        // key, throwing AFTER the old draft has been archived and the new one
        // created — exactly the half-written state the transaction must undo.
        $this->fakeEngine([$this->engineSession(['room_id' => 999999])]);

        $this->generateFor($this->section)->assertStatus(500);

        // The replacement rolled back completely: the old draft is still the
        // only schedule for this section, still a draft, with its session.
        $existing->refresh();
        $this->assertSame('draft', $existing->status);
        $this->assertSame(1, $existing->sessions()->count());
        $this->assertSame(
            1,
            Schedule::where('section_id', $this->section->id)->count(),
            'A failed replacement must not leave an extra draft behind.'
        );
        $this->assertSame(0, Schedule::where('status', 'archived')->count());
        $this->assertSame(0, ScheduleSession::where('room_id', 999999)->count());

        // `block()` releases the lock in a `finally`, so an uncaught exception
        // must not leave the term locked out for the rest of the TTL. (Checked
        // by taking the lock directly: Http::fake() appends stubs instead of
        // replacing them, so a second in-test fake would not take effect.)
        $lock = Cache::lock('schedule-generation:2026-2027:1st Semester', 180);
        $this->assertTrue(
            $lock->get(),
            'The generation lock was not released after the persistence failure.'
        );
        $lock->release();
    }

    // -----------------------------------------------------------------------
    // Locking
    // -----------------------------------------------------------------------

    #[Test]
    public function a_contended_generation_lock_is_refused_with_409(): void
    {
        // No waiting: a held lock turns straight into a refusal, so the test is
        // fast and deterministic.
        config(['scheduling.generation_lock.wait' => 0]);

        $this->fakeEngine([$this->engineSession()]);

        $lock = Cache::lock(
            'schedule-generation:2026-2027:1st Semester',
            (int) config('scheduling.generation_lock.ttl')
        );
        $this->assertTrue($lock->get(), 'The test could not take the generation lock.');

        try {
            $this->generateFor($this->section)
                ->assertStatus(409)
                ->assertJsonPath('error', 'Another schedule generation is already running for this term.');

            // Refusing must not have written anything.
            $this->assertSame(0, Schedule::count());
        } finally {
            $lock->release();
        }

        // With the lock free again the same request succeeds — proving the 409
        // was the lock and not the conflict check.
        $this->generateFor($this->section)->assertOk();
        $this->assertSame(1, Schedule::where('section_id', $this->section->id)->count());
    }
}
