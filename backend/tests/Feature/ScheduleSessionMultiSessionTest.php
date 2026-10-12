<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\FacultyAvailability;
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
 * The multi-session editor: one subject, several meetings, stored as several
 * `schedule_sessions` rows.
 *
 * The design being pinned here is that a subject's single generated block can be
 * split across days by hand, but the result stays inside the rules the solver
 * enforces at generation time — the same room, break, availability and
 * double-booking constraints, plus the subject's own lecture and laboratory hour
 * budgets.
 *
 * Two properties matter more than any individual rule:
 *
 *  - **Lecture and laboratory hours are separate budgets.** The solver builds one
 *    session per type straight from `lecture_hours` and `lab_hours`
 *    (ai-engine/solver/scheduler.py), so a lecture hour can never be spent as a
 *    laboratory hour and vice versa.
 *  - **A rejected batch writes nothing.** Every entry is reviewed against the
 *    state the schedule would be left in before the first INSERT, so a request
 *    that is wrong about its last entry cannot have applied its first.
 */
class ScheduleSessionMultiSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $nonAdmin;

    private Faculty $faculty;

    private Room $room;

    private Subject $subject;

    private Section $section;

    private Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Department Head',
            'email' => 'admin@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->nonAdmin = User::create([
            'name' => 'Not The Head',
            'email' => 'other@test.local',
            'password' => bcrypt('password'),
            'role' => 'faculty',
        ]);

        $this->faculty = $this->makeFaculty();

        $this->room = $this->makeRoom('R101', 'lecture');

        $this->subject = $this->makeSubject();

        $this->section = $this->makeSection();

        $this->section->subjects()->attach($this->subject->id);

        $this->schedule = Schedule::create([
            'section_id' => $this->section->id,
            'status' => 'draft',
        ]);
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    private function makeFaculty(string $name = 'Prof. Load', string $employeeNo = 'EMP-001'): Faculty
    {
        return Faculty::create([
            'name' => $name,
            'employee_no' => $employeeNo,
            'faculty_type' => 'full_time',
            'max_teaching_load' => 24,
            'is_active' => true,
        ]);
    }

    private function makeRoom(string $name, string $type, int $capacity = 40): Room
    {
        return Room::create([
            'name' => $name,
            'type' => $type,
            'capacity' => $capacity,
            'status' => 'available',
        ]);
    }

    private function makeSubject(array $overrides = []): Subject
    {
        return Subject::create(array_merge([
            'code' => 'PROG1',
            'title' => 'Programming 1',
            'classification' => 'minor',
            'year_level' => 1,
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'lab_room_type' => null,
            'is_active' => true,
        ], $overrides));
    }

    private function makeSection(array $overrides = []): Section
    {
        return Section::create(array_merge([
            'name' => 'BSIT 1A',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3, 4, 5],
            'preferred_start_time' => '07:00:00',
            'preferred_end_time' => '21:00:00',
            'student_count' => 30,
        ], $overrides));
    }

    /** One meeting in the shape the API accepts. */
    private function meeting(array $overrides = []): array
    {
        return array_merge([
            'subject_id' => $this->subject->id,
            'session_type' => 'lecture',
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'room_id' => $this->room->id,
            'faculty_id' => $this->faculty->id,
        ], $overrides);
    }

    private function postSessions(array $sessions, ?Schedule $schedule = null, ?User $as = null)
    {
        $schedule ??= $this->schedule;

        return $this->actingAs($as ?? $this->admin)
            ->postJson("/api/schedules/{$schedule->id}/sessions", ['sessions' => $sessions]);
    }

    /** The `hours` row for one subject and session type, or null. */
    private function hoursRow($response, int $subjectId, string $type): ?array
    {
        foreach ($response->json('hours') as $row) {
            if ($row['subject_id'] === $subjectId && $row['session_type'] === $type) {
                return $row;
            }
        }

        return null;
    }

    private function assertHours($response, int $subjectId, string $type, float $required, float $scheduled): void
    {
        $row = $this->hoursRow($response, $subjectId, $type);

        $this->assertNotNull($row, "No {$type} hours row for subject #{$subjectId}.");

        $this->assertEqualsWithDelta($required, (float) $row['required'], 0.001, 'required hours');
        $this->assertEqualsWithDelta($scheduled, (float) $row['scheduled'], 0.001, 'scheduled hours');
        $this->assertEqualsWithDelta($required - $scheduled, (float) $row['remaining'], 0.001, 'remaining hours');
    }

    // -----------------------------------------------------------------------
    // 1. Splitting a subject across days
    // -----------------------------------------------------------------------

    #[Test]
    public function a_subject_can_be_split_across_multiple_days(): void
    {
        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
            $this->meeting(['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])
            ->assertStatus(201)
            ->assertJsonCount(2, 'sessions');

        $this->assertDatabaseHas('schedule_sessions', [
            'schedule_id' => $this->schedule->id,
            'subject_id' => $this->subject->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $this->assertDatabaseHas('schedule_sessions', [
            'schedule_id' => $this->schedule->id,
            'subject_id' => $this->subject->id,
            'day_of_week' => 2,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);
    }

    #[Test]
    public function splitting_a_subject_into_exactly_its_required_hours_is_accepted(): void
    {
        $response = $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
            $this->meeting(['day_of_week' => 3, 'start_time' => '09:00', 'end_time' => '10:00']),
        ]);

        $response->assertStatus(201);

        $this->assertHours($response, $this->subject->id, 'lecture', 2.0, 2.0);

        $this->assertSame([], $response->json('warnings'));
    }

    // -----------------------------------------------------------------------
    // 2. Hour totals
    // -----------------------------------------------------------------------

    #[Test]
    public function total_hours_are_reported_per_session_type(): void
    {
        $labRoom = $this->makeRoom('LAB1', 'computer_lab');

        $subject = $this->makeSubject([
            'code' => 'PROG1-LAB',
            'lecture_hours' => 2,
            'lab_hours' => 2,
            'lab_room_type' => 'computer_lab',
        ]);

        $this->section->subjects()->attach($subject->id);

        $response = $this->postSessions([
            $this->meeting([
                'subject_id' => $subject->id,
                'session_type' => 'lecture',
                'day_of_week' => 1,
                'start_time' => '09:00',
                'end_time' => '11:00',
            ]),
            $this->meeting([
                'subject_id' => $subject->id,
                'session_type' => 'laboratory',
                'day_of_week' => 2,
                'start_time' => '09:00',
                'end_time' => '11:00',
                'room_id' => $labRoom->id,
            ]),
        ]);

        $response->assertStatus(201);

        // Lecture and laboratory are reported as two independent budgets even
        // though the subject needs the same number of hours of each.
        $this->assertHours($response, $subject->id, 'lecture', 2.0, 2.0);
        $this->assertHours($response, $subject->id, 'laboratory', 2.0, 2.0);
    }

    #[Test]
    public function hours_are_summed_across_every_meeting_of_a_subject(): void
    {
        $response = $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '08:30']),
            $this->meeting(['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '08:30']),
            $this->meeting(['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '09:00']),
        ]);

        $response->assertStatus(201);

        // 0.5 + 0.5 + 1 = 2, the subject's whole lecture requirement — the same
        // total in three meetings as the generator's single two-hour block.
        $this->assertHours($response, $this->subject->id, 'lecture', 2.0, 2.0);
    }

    // -----------------------------------------------------------------------
    // 3. Excessive and incomplete schedules
    // -----------------------------------------------------------------------

    #[Test]
    public function a_single_meeting_longer_than_the_subject_requires_is_rejected(): void
    {
        $response = $this->postSessions([
            $this->meeting(['start_time' => '09:00', 'end_time' => '12:00']),
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'conflicts']);

        $this->assertStringContainsString('PROG1', implode(' ', $response->json('conflicts')));

        // Nothing was written.
        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function excessive_hours_are_rejected_even_when_split_across_days(): void
    {
        $response = $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '11:00']),
            $this->meeting(['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '11:00']),
        ]);

        // 4 hours of lecture for a subject that requires 2.
        $response->assertStatus(422);

        $this->assertStringContainsString('2h', implode(' ', $response->json('conflicts')));
        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function an_incomplete_schedule_is_saved_and_reported_as_a_warning(): void
    {
        // A partial timetable is a legitimate state — the solver itself reports
        // unscheduled sessions — so one hour of a two-hour requirement saves,
        // and the shortfall comes back with the result for the editor to show.
        $response = $this->postSessions([
            $this->meeting(['start_time' => '09:00', 'end_time' => '10:00']),
        ]);

        $response->assertStatus(201);

        $this->assertHours($response, $this->subject->id, 'lecture', 2.0, 1.0);

        $warnings = $response->json('warnings');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('PROG1', implode(' ', $warnings));
    }

    #[Test]
    public function a_lecture_budget_cannot_be_paid_with_laboratory_hours(): void
    {
        $labRoom = $this->makeRoom('LAB1', 'computer_lab');

        $subject = $this->makeSubject([
            'code' => 'PROG1-LAB',
            'lecture_hours' => 2,
            'lab_hours' => 2,
            'lab_room_type' => 'computer_lab',
        ]);

        $this->section->subjects()->attach($subject->id);

        // The laboratory requirement is met in full and the lecture requirement
        // is untouched: the two budgets do not offset each other.
        $response = $this->postSessions([
            $this->meeting([
                'subject_id' => $subject->id,
                'session_type' => 'laboratory',
                'start_time' => '09:00',
                'end_time' => '11:00',
                'room_id' => $labRoom->id,
            ]),
        ]);

        $response->assertStatus(201);

        $this->assertHours($response, $subject->id, 'laboratory', 2.0, 2.0);
        $this->assertHours($response, $subject->id, 'lecture', 2.0, 0.0);

        $this->assertStringContainsString('lecture', implode(' ', $response->json('warnings')));
    }

    #[Test]
    public function the_lecture_budget_is_still_enforced_when_a_laboratory_exists(): void
    {
        $labRoom = $this->makeRoom('LAB1', 'computer_lab');

        $subject = $this->makeSubject([
            'code' => 'PROG1-LAB',
            'lecture_hours' => 2,
            'lab_hours' => 2,
            'lab_room_type' => 'computer_lab',
        ]);

        $this->section->subjects()->attach($subject->id);

        // A three-hour laboratory meeting: the lab budget is 2, so this is over
        // even though the subject runs 4 hours in total.
        $response = $this->postSessions([
            $this->meeting([
                'subject_id' => $subject->id,
                'session_type' => 'laboratory',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'room_id' => $labRoom->id,
            ]),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    // -----------------------------------------------------------------------
    // 4. Meeting shape
    // -----------------------------------------------------------------------

    #[Test]
    public function a_meeting_must_start_on_the_half_hour_grid(): void
    {
        $this->postSessions([
            $this->meeting(['start_time' => '09:15', 'end_time' => '10:15']),
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors', 'conflicts']);

        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function a_meeting_must_last_a_whole_number_of_half_hours(): void
    {
        $this->postSessions([
            $this->meeting(['start_time' => '09:00', 'end_time' => '09:45']),
        ])->assertStatus(422);

        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function an_end_time_before_the_start_time_is_rejected(): void
    {
        $this->postSessions([
            $this->meeting(['start_time' => '10:00', 'end_time' => '09:00']),
        ])->assertStatus(422);

        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function a_subject_from_another_semester_cannot_be_placed(): void
    {
        $other = $this->makeSubject([
            'code' => 'PROG1-B',
            'semester_name' => '2nd Semester',
        ]);

        $this->postSessions([
            $this->meeting([
                'subject_id' => $other->id,
                'start_time' => '09:00',
                'end_time' => '10:00',
            ]),
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors', 'conflicts']);

        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    // -----------------------------------------------------------------------
    // 5. Lecture / laboratory combination rules
    // -----------------------------------------------------------------------

    #[Test]
    public function a_laboratory_meeting_is_rejected_when_the_subject_has_no_lab_component(): void
    {
        $this->postSessions([
            $this->meeting(['session_type' => 'laboratory', 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(422);

        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function a_lecture_meeting_is_rejected_for_a_subject_with_no_lecture_hours(): void
    {
        $labOnly = $this->makeSubject([
            'code' => 'LABONLY',
            'lecture_hours' => 0,
            'lab_hours' => 2,
            'lab_room_type' => 'computer_lab',
        ]);

        $this->section->subjects()->attach($labOnly->id);

        $this->postSessions([
            $this->meeting([
                'subject_id' => $labOnly->id,
                'session_type' => 'lecture',
                'start_time' => '09:00',
                'end_time' => '10:00',
            ]),
        ])->assertStatus(422);

        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function a_laboratory_meeting_needs_a_room_of_the_subjects_lab_type(): void
    {
        $subject = $this->makeSubject([
            'code' => 'PROG1-LAB',
            'lecture_hours' => 2,
            'lab_hours' => 2,
            'lab_room_type' => 'computer_lab',
        ]);

        $this->section->subjects()->attach($subject->id);

        // `$this->room` is a lecture room; a laboratory meeting cannot use it.
        $response = $this->postSessions([
            $this->meeting([
                'subject_id' => $subject->id,
                'session_type' => 'laboratory',
                'start_time' => '09:00',
                'end_time' => '11:00',
            ]),
        ]);

        $response->assertStatus(422);

        $this->assertStringContainsString('computer_lab', implode(' ', $response->json('conflicts')));
        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function a_room_too_small_for_the_section_is_rejected(): void
    {
        $tiny = $this->makeRoom('R-SMALL', 'lecture', 10);

        $this->postSessions([
            $this->meeting(['room_id' => $tiny->id, 'start_time' => '09:00', 'end_time' => '11:00']),
        ])->assertStatus(422);

        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function a_laboratory_meeting_cannot_use_a_room_of_another_lab_type(): void
    {
        $subject = $this->makeSubject([
            'code' => 'PROG1-LAB',
            'lecture_hours' => 2,
            'lab_hours' => 2,
            'lab_room_type' => 'computer_lab',
        ]);

        $this->section->subjects()->attach($subject->id);

        $scienceLab = $this->makeRoom('SCI-LAB', 'science_lab');

        $this->postSessions([
            $this->meeting([
                'subject_id' => $subject->id,
                'session_type' => 'laboratory',
                'start_time' => '09:00',
                'end_time' => '11:00',
                'room_id' => $scienceLab->id,
            ]),
        ])->assertStatus(422);

        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    // -----------------------------------------------------------------------
    // 6. Conflicts within the schedule
    // -----------------------------------------------------------------------

    #[Test]
    public function two_overlapping_meetings_in_the_same_batch_are_rejected_atomically(): void
    {
        $response = $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:30', 'end_time' => '10:30']),
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors', 'conflicts']);

        $this->assertStringContainsString('Overlaps', implode(' ', $response->json('conflicts')));

        // The first entry was legal on its own, and still nothing was written.
        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function a_meeting_overlapping_an_existing_one_is_rejected(): void
    {
        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(201);

        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:30', 'end_time' => '10:00']),
        ])->assertStatus(422);

        $this->assertDatabaseCount('schedule_sessions', 1);
    }

    #[Test]
    public function back_to_back_meetings_do_not_count_as_an_overlap(): void
    {
        $response = $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
            $this->meeting(['day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '11:00']),
        ]);

        $response->assertStatus(201);

        $this->assertHours($response, $this->subject->id, 'lecture', 2.0, 2.0);
    }

    #[Test]
    public function the_same_faculty_cannot_teach_two_meetings_at_once(): void
    {
        $otherRoom = $this->makeRoom('R-OTHER', 'lecture');

        $response = $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
            $this->meeting([
                'day_of_week' => 1,
                'start_time' => '09:00',
                'end_time' => '10:00',
                'room_id' => $otherRoom->id,
            ]),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function the_same_room_cannot_host_two_meetings_at_once(): void
    {
        $otherSubject = $this->makeSubject(['code' => 'PROG2', 'lecture_hours' => 1]);

        $this->section->subjects()->attach($otherSubject->id);

        $otherFaculty = $this->makeFaculty('Prof. Other', 'EMP-002');

        $response = $this->postSessions([
            $this->meeting([
                'day_of_week' => 1,
                'start_time' => '09:00',
                'end_time' => '10:00',
                'faculty_id' => $this->faculty->id,
            ]),
            $this->meeting([
                'subject_id' => $otherSubject->id,
                'day_of_week' => 1,
                'start_time' => '09:00',
                'end_time' => '10:00',
                'faculty_id' => $otherFaculty->id,
            ]),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function the_midday_break_is_kept_clear(): void
    {
        $response = $this->postSessions([
            $this->meeting(['start_time' => '12:00', 'end_time' => '13:00']),
        ]);

        $response->assertStatus(422);

        $this->assertStringContainsString('break', strtolower(implode(' ', $response->json('conflicts'))));
        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function a_faculty_members_declared_availability_is_respected(): void
    {
        // Declared: Monday, 07:00-10:00, and nothing else.
        FacultyAvailability::create([
            'faculty_id' => $this->faculty->id,
            'day_of_week' => 1,
            'start_time' => '07:00:00',
            'end_time' => '10:00:00',
        ]);

        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(201);

        // Tuesday is outside the declared window entirely. The existing Monday
        // meeting is not in the way, so availability is the only rule in play.
        $response = $this->postSessions([
            $this->meeting(['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00']),
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('not available', implode(' ', $response->json('conflicts')));

        $this->assertDatabaseCount('schedule_sessions', 1);
    }

    #[Test]
    public function a_faculty_member_with_no_declared_availability_is_unrestricted(): void
    {
        // No availability rows at all means "no declared restriction", which is
        // deliberately not the same as "unavailable".
        $this->postSessions([
            $this->meeting(['day_of_week' => 6, 'start_time' => '18:00', 'end_time' => '19:00']),
        ])->assertStatus(201);
    }

    // -----------------------------------------------------------------------
    // 7. Conflicts with other sections' live schedules
    // -----------------------------------------------------------------------

    #[Test]
    public function a_meeting_conflicting_with_another_sections_approved_schedule_is_rejected(): void
    {
        $otherSection = $this->makeSection(['name' => 'BSIT 1B']);

        $otherSchedule = Schedule::create([
            'section_id' => $otherSection->id,
            'status' => 'approved',
        ]);

        $otherRoom = $this->makeRoom('R-OTHER', 'lecture');

        ScheduleSession::create([
            'schedule_id' => $otherSchedule->id,
            'subject_id' => $this->subject->id,
            'session_type' => 'lecture',
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'room_id' => $otherRoom->id,
            'faculty_id' => $this->faculty->id,
        ]);

        // Same faculty, same time, different room and section.
        $response = $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
        ]);

        $response->assertStatus(422);

        $this->assertStringContainsString('already booked', implode(' ', $response->json('conflicts')));
        $this->assertDatabaseCount('schedule_sessions', 1);
    }

    #[Test]
    public function a_meeting_conflicting_with_another_sections_draft_is_rejected(): void
    {
        // Drafts count: the generator refuses to clash with a sibling draft, so
        // a hand edit must not be able to create the clash the generator forbids.
        $otherSection = $this->makeSection(['name' => 'BSIT 1C']);

        $otherSchedule = Schedule::create([
            'section_id' => $otherSection->id,
            'status' => 'draft',
        ]);

        $otherRoom = $this->makeRoom('R-DRAFT', 'lecture');

        ScheduleSession::create([
            'schedule_id' => $otherSchedule->id,
            'subject_id' => $this->subject->id,
            'session_type' => 'lecture',
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'room_id' => $otherRoom->id,
            'faculty_id' => $this->faculty->id,
        ]);

        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(422);

        $this->assertDatabaseCount('schedule_sessions', 1);
    }

    #[Test]
    public function another_terms_schedule_is_not_a_conflict(): void
    {
        $otherSection = $this->makeSection([
            'name' => 'BSIT 1D',
            'academic_year' => '2027-2028',
        ]);

        $otherSchedule = Schedule::create([
            'section_id' => $otherSection->id,
            'status' => 'published',
        ]);

        $otherRoom = $this->makeRoom('R-OTHER-TERM', 'lecture');

        ScheduleSession::create([
            'schedule_id' => $otherSchedule->id,
            'subject_id' => $this->subject->id,
            'session_type' => 'lecture',
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'room_id' => $otherRoom->id,
            'faculty_id' => $this->faculty->id,
        ]);

        // Different academic year: a different timetable, so the same faculty
        // member being booked there is not a clash here.
        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(201);
    }

    // -----------------------------------------------------------------------
    // 8. Batch updates
    // -----------------------------------------------------------------------

    #[Test]
    public function a_batch_update_applies_every_entry_on_success(): void
    {
        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
            $this->meeting(['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(201);

        $ids = $this->schedule->sessions()->orderBy('day_of_week')->pluck('id');

        $this->actingAs($this->admin)
            ->putJson("/api/schedules/{$this->schedule->id}/sessions", [
                'sessions' => [
                    ['id' => $ids[0], 'start_time' => '08:00', 'end_time' => '09:00'],
                    ['id' => $ids[1], 'start_time' => '08:00', 'end_time' => '09:00'],
                ],
            ])
            ->assertStatus(200)
            ->assertJsonCount(2, 'sessions');

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $ids[0],
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $ids[1],
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);
    }

    #[Test]
    public function a_batch_update_is_atomic_when_one_entry_is_invalid(): void
    {
        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
            $this->meeting(['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(201);

        $ids = $this->schedule->sessions()->orderBy('day_of_week')->pluck('id');

        // The first entry is valid on its own; the second overlaps it.
        $this->actingAs($this->admin)
            ->putJson("/api/schedules/{$this->schedule->id}/sessions", [
                'sessions' => [
                    ['id' => $ids[0], 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00'],
                    ['id' => $ids[1], 'day_of_week' => 1, 'start_time' => '09:30', 'end_time' => '10:30'],
                ],
            ])
            ->assertStatus(422);

        // Neither entry moved.
        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $ids[0],
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $ids[1],
            'day_of_week' => 2,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);
    }

    #[Test]
    public function a_batch_update_cannot_reach_another_schedules_meetings(): void
    {
        $otherSection = $this->makeSection(['name' => 'BSIT 1E']);

        $otherSchedule = Schedule::create([
            'section_id' => $otherSection->id,
            'status' => 'draft',
        ]);

        $otherSession = ScheduleSession::create([
            'schedule_id' => $otherSchedule->id,
            'subject_id' => $this->subject->id,
            'session_type' => 'lecture',
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'room_id' => $this->room->id,
            'faculty_id' => $this->faculty->id,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/schedules/{$this->schedule->id}/sessions", [
                'sessions' => [
                    ['id' => $otherSession->id, 'start_time' => '14:00', 'end_time' => '15:00'],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $otherSession->id,
            'start_time' => '09:00:00',
        ]);
    }

    #[Test]
    public function a_batch_update_is_refused_when_it_would_exceed_the_hour_budget(): void
    {
        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
            $this->meeting(['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(201);

        $ids = $this->schedule->sessions()->orderBy('day_of_week')->pluck('id');

        // Both meetings stretched to two hours each: four hours for a subject
        // that requires two.
        $this->actingAs($this->admin)
            ->putJson("/api/schedules/{$this->schedule->id}/sessions", [
                'sessions' => [
                    ['id' => $ids[0], 'end_time' => '11:00'],
                    ['id' => $ids[1], 'end_time' => '11:00'],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $ids[0],
            'end_time' => '10:00:00',
        ]);
    }

    // -----------------------------------------------------------------------
    // 9. Deletion
    // -----------------------------------------------------------------------

    #[Test]
    public function sessions_can_be_deleted_from_a_draft(): void
    {
        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
            $this->meeting(['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(201);

        $ids = $this->schedule->sessions()->pluck('id')->all();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/schedules/{$this->schedule->id}/sessions", [
                'session_ids' => [$ids[0]],
            ]);

        $response->assertStatus(200);
        $this->assertSame([$ids[0]], $response->json('deleted'));
        $this->assertDatabaseCount('schedule_sessions', 1);
    }

    #[Test]
    public function a_delete_naming_a_foreign_session_removes_nothing(): void
    {
        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(201);

        $mine = $this->schedule->sessions()->pluck('id')->first();

        $otherSection = $this->makeSection(['name' => 'BSIT 1F']);

        $otherSchedule = Schedule::create([
            'section_id' => $otherSection->id,
            'status' => 'draft',
        ]);

        $theirs = ScheduleSession::create([
            'schedule_id' => $otherSchedule->id,
            'subject_id' => $this->subject->id,
            'session_type' => 'lecture',
            'day_of_week' => 1,
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
            'room_id' => $this->room->id,
            'faculty_id' => $this->faculty->id,
        ]);

        // The valid id is first: a delete that walked the list would have
        // removed it before noticing the invalid one.
        $this->actingAs($this->admin)
            ->deleteJson("/api/schedules/{$this->schedule->id}/sessions", [
                'session_ids' => [$mine, $theirs->id],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('schedule_sessions', 2);
        $this->assertDatabaseHas('schedule_sessions', ['id' => $mine]);
    }

    // -----------------------------------------------------------------------
    // 10. Authorization and schedule lifecycle
    // -----------------------------------------------------------------------

    /**
     * Every batch endpoint, as the editor calls it. Declared once so the two
     * authorization tests below cannot drift out of step with each other — or
     * quietly stop covering an endpoint that is added later.
     *
     * @return array<string,array{0:string,1:string,2:array<string,mixed>}>
     */
    private function editorRequests(int $sessionId): array
    {
        $base = "/api/schedules/{$this->schedule->id}/sessions";

        return [
            'list' => ['getJson', $base, []],
            'create' => ['postJson', $base, ['sessions' => [$this->meeting()]]],
            'update' => ['putJson', $base, ['sessions' => [['id' => $sessionId, 'day_of_week' => 2]]]],
            'delete' => ['deleteJson', $base, ['session_ids' => [$sessionId]]],
        ];
    }

    /**
     * A stored meeting, seeded without going through an endpoint: `actingAs()`
     * persists for the rest of a test, so a test that has signed in as the
     * admin cannot then make an unauthenticated request.
     */
    private function seedMeeting(array $overrides = []): ScheduleSession
    {
        return ScheduleSession::create(array_merge([
            'schedule_id' => $this->schedule->id,
            'subject_id' => $this->subject->id,
            'session_type' => 'lecture',
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'room_id' => $this->room->id,
            'faculty_id' => $this->faculty->id,
        ], $overrides));
    }

    #[Test]
    public function every_new_endpoint_rejects_an_unauthenticated_request(): void
    {
        $session = $this->seedMeeting();

        // No `actingAs` anywhere in this test, so no token: `auth:sanctum`
        // answers before the controller's own role check is reached.
        foreach ($this->editorRequests($session->id) as $label => [$method, $url, $body]) {
            $this->withHeaders(['Accept' => 'application/json'])
                ->{$method}($url, $body)
                ->assertStatus(401);
        }

        $this->assertDatabaseHas('schedule_sessions', ['id' => $session->id, 'day_of_week' => 1]);
    }

    #[Test]
    public function every_new_endpoint_rejects_a_non_admin(): void
    {
        $session = $this->seedMeeting();

        foreach ($this->editorRequests($session->id) as $label => [$method, $url, $body]) {
            $this->actingAs($this->nonAdmin)
                ->{$method}($url, $body)
                ->assertStatus(403);
        }

        // None of the refused calls created, moved or deleted anything.
        $this->assertDatabaseCount('schedule_sessions', 1);
        $this->assertDatabaseHas('schedule_sessions', ['id' => $session->id, 'day_of_week' => 1]);
    }

    #[Test]
    public function a_draft_is_readable_and_an_approved_schedule_is_readable_but_not_writable(): void
    {
        $this->actingAs($this->admin)
            ->getJson("/api/schedules/{$this->schedule->id}/sessions")
            ->assertStatus(200)
            ->assertJsonStructure(['sessions', 'hours']);

        $approved = Schedule::create([
            'section_id' => $this->section->id,
            'status' => 'approved',
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/schedules/{$approved->id}/sessions")
            ->assertStatus(200);

        $this->postSessions([$this->meeting()], $approved)->assertStatus(422);
    }

    #[Test]
    public function a_published_schedule_can_be_read_but_not_written(): void
    {
        $published = Schedule::create([
            'section_id' => $this->section->id,
            'status' => 'published',
        ]);

        // Reading is open across the term's live statuses: `GET /api/schedules/
        // {id}` already returns a published schedule with every session attached,
        // so refusing the narrower read would only cost the editor the ability to
        // display it.
        $this->actingAs($this->admin)
            ->getJson("/api/schedules/{$published->id}/sessions")
            ->assertStatus(200)
            ->assertJsonStructure(['sessions', 'hours']);

        // Writing is still draft-only.
        $this->postSessions([$this->meeting()], $published)->assertStatus(422);
        $this->assertDatabaseCount('schedule_sessions', 0);
    }

    #[Test]
    public function an_archived_schedule_is_not_part_of_the_working_timetable(): void
    {
        $archived = Schedule::create([
            'section_id' => $this->section->id,
            'status' => 'archived',
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/schedules/{$archived->id}/sessions")
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // 11. The legacy single-session endpoint keeps its contract
    // -----------------------------------------------------------------------

    #[Test]
    public function the_legacy_single_session_endpoint_still_edits_one_meeting(): void
    {
        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(201);

        $session = $this->schedule->sessions()->firstOrFail();

        $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'day_of_week' => 3,
                'start_time' => '14:00',
                'end_time' => '15:00',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'day_of_week' => 3,
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
        ]);
    }

    #[Test]
    public function the_legacy_single_session_endpoint_still_reports_conflicts(): void
    {
        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
            $this->meeting(['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(201);

        $session = $this->schedule->sessions()->orderBy('day_of_week')->firstOrFail();

        // Drag Monday's meeting onto Tuesday's, inside the break window.
        $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'day_of_week' => 1,
                'start_time' => '12:00',
                'end_time' => '13:00',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'conflicts']);

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'start_time' => '09:00:00',
        ]);
    }

    // -----------------------------------------------------------------------
    // 12. Generation still works around hand-edited meetings
    // -----------------------------------------------------------------------

    #[Test]
    public function generation_still_runs_after_meetings_are_edited_by_hand(): void
    {
        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
            $this->meeting(['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(201);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/schedules/generate/{$this->section->id}");

        // The AI engine is not running in the test suite, so a 502 for an
        // unreachable engine is expected. What matters is that the hand-edited
        // draft has not broken the generation path.
        $this->assertContains(
            $response->status(),
            [200, 409, 422, 502],
            'Generation returned '.$response->status().', which is not one of the expected outcomes.'
        );
    }

    #[Test]
    public function the_legacy_session_edit_still_cannot_be_reached_by_a_non_admin(): void
    {
        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(201);

        $session = $this->schedule->sessions()->firstOrFail();

        $this->actingAs($this->nonAdmin)
            ->putJson("/api/schedules/sessions/{$session->id}", ['day_of_week' => 2])
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // 13. The legacy single-session edit enforces the hour budget
    //
    // The endpoint cannot add or remove a meeting or move one to another
    // subject, but it CAN lengthen one, which made it the only way a hand edit
    // could push a subject past the hours it requires. It now refuses that —
    // without refusing the edits it used to accept.
    //
    // Both write paths now share one implementation, so the last test here pins
    // the batch endpoints' side of that bargain: their `conflicts` sentences.
    // -----------------------------------------------------------------------

    #[Test]
    public function a_legacy_edit_that_grows_a_lecture_past_its_budget_is_rejected(): void
    {
        // PROG1 requires 2h of lecture. One hour is placed, so there is a spare
        // hour to spend — but not two.
        $session = $this->seedMeeting([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'start_time' => '09:00',
                'end_time' => '12:00',
            ]);

        $response->assertStatus(422);

        // Structured: which subject, which budget, and both figures, so the
        // admin can see how far over the change would put them.
        $response->assertJsonStructure([
            'message',
            'hours' => [['subject_id', 'code', 'session_type', 'required', 'scheduled', 'over', 'message']],
        ]);

        $hours = $response->json('hours.0');
        $this->assertEquals($this->subject->id, $hours['subject_id']);
        $this->assertSame('PROG1', $hours['code']);
        $this->assertSame('lecture', $hours['session_type']);
        $this->assertEqualsWithDelta(2, (float) $hours['required'], 0.001, 'required hours');
        $this->assertEqualsWithDelta(3, (float) $hours['scheduled'], 0.001, 'resulting scheduled hours');
        $this->assertEqualsWithDelta(1, (float) $hours['over'], 0.001, 'hours over budget');

        // Hour reasons live in their own list: `conflicts` stays the placement
        // list it has always been, and is present but empty here.
        $this->assertSame([], $response->json('conflicts'));
        $this->assertStringContainsString('PROG1', $response->json('message'));
        $this->assertStringContainsString('2h', $response->json('message'));

        // Nothing was written.
        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);
    }

    #[Test]
    public function a_legacy_edit_that_grows_a_laboratory_past_its_budget_is_rejected(): void
    {
        // A subject whose only budget is laboratory time, so a laboratory
        // over-placement cannot be mistaken for a lecture one.
        $labSubject = $this->makeSubject([
            'code' => 'LAB1',
            'title' => 'Networking Laboratory',
            'lecture_hours' => 0,
            'lab_hours' => 2,
            'lab_room_type' => 'computer_lab',
        ]);

        $this->section->subjects()->attach($labSubject->id);

        $lab = $this->makeRoom('LAB-R1', 'computer_lab');

        $session = $this->seedMeeting([
            'subject_id' => $labSubject->id,
            'session_type' => 'laboratory',
            'room_id' => $lab->id,
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'start_time' => '13:00',
                'end_time' => '16:00',
            ]);

        $response->assertStatus(422);

        $this->assertSame('laboratory', $response->json('hours.0.session_type'));
        $this->assertEqualsWithDelta(2, (float) $response->json('hours.0.required'), 0.001);
        $this->assertEqualsWithDelta(3, (float) $response->json('hours.0.scheduled'), 0.001);
        $this->assertSame([], $response->json('conflicts'));

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
        ]);
    }

    #[Test]
    public function a_legacy_edit_that_stays_within_the_budget_saves(): void
    {
        $session = $this->seedMeeting([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        // Growing 1h to exactly the 2h PROG1 requires is the boundary case: it
        // fills the budget without exceeding it.
        $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'start_time' => '09:00',
                'end_time' => '11:00',
            ])
            ->assertOk();

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ]);
    }

    #[Test]
    public function a_legacy_edit_that_moves_an_over_placed_meeting_without_lengthening_it_saves(): void
    {
        // A row that predates the budget: 3h of lecture against a 2h subject.
        // Moving it does not make the over-placement any worse, so it has to
        // stay editable — refusing would wedge the schedule with no way out.
        $session = $this->seedMeeting([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'day_of_week' => 3,
                'start_time' => '13:00',
                'end_time' => '16:00',
            ])
            ->assertOk();

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'day_of_week' => 3,
            'start_time' => '13:00:00',
            'end_time' => '16:00:00',
        ]);
    }

    #[Test]
    public function a_legacy_edit_that_shrinks_an_over_placement_saves(): void
    {
        // The recovery path: an over-long meeting has to be shortenable even
        // though the endpoint is what is meant to prevent over-placement.
        $session = $this->seedMeeting([
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'start_time' => '09:00',
                'end_time' => '11:00',
            ])
            ->assertOk();

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'end_time' => '11:00:00',
        ]);
    }

    #[Test]
    public function an_unrelated_legacy_edit_saves_while_another_subject_is_over_placed(): void
    {
        // PROG1 is already 1h over its lecture budget.
        $this->seedMeeting([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ]);

        $other = $this->makeSubject([
            'code' => 'PROG2',
            'title' => 'Programming 2',
            'lecture_hours' => 2,
        ]);

        $this->section->subjects()->attach($other->id);

        $session = $this->seedMeeting([
            'subject_id' => $other->id,
            'day_of_week' => 2,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        // Editing PROG2 cannot change PROG1's excess, so it saves.
        $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'start_time' => '09:00',
                'end_time' => '11:00',
            ])
            ->assertOk();

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'end_time' => '11:00:00',
        ]);
    }

    #[Test]
    public function the_legacy_hour_guard_still_refuses_a_non_admin(): void
    {
        $session = $this->seedMeeting([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        // Over budget for a reason that has nothing to do with authorization:
        // the role gate has to win, and the body must not be evaluated at all.
        $this->actingAs($this->nonAdmin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'start_time' => '09:00',
                'end_time' => '15:00',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'end_time' => '10:00:00',
        ]);
    }

    #[Test]
    public function the_legacy_session_edit_still_requires_a_token(): void
    {
        $session = $this->seedMeeting([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        // No actingAs(): a signed-in session persists for the rest of a test, so
        // signing in first would only prove the admin can still reach it. The
        // body is over budget as well, so a 401 rather than 422 also shows the
        // token is checked before the timetable is read.
        $this->putJson("/api/schedules/sessions/{$session->id}", [
            'start_time' => '09:00',
            'end_time' => '15:00',
        ])->assertStatus(401);

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'end_time' => '10:00:00',
        ]);
    }

    #[Test]
    public function the_legacy_edit_keeps_its_draft_and_approved_status_gate(): void
    {
        $session = $this->seedMeeting([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        // `approved` has always been editable here: it is the schedule awaiting
        // publish, and nudging one of its meetings is what this endpoint is for.
        $this->schedule->update(['status' => 'approved']);

        $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", ['day_of_week' => 2])
            ->assertOk();

        // The statuses that are not part of the working timetable stay locked.
        // The grid and hour checks sit BEHIND this gate, not in front of it, so
        // an off-grid body here answers with the status and nothing else.
        foreach (['published', 'archived'] as $status) {
            $this->schedule->update(['status' => $status]);

            $this->actingAs($this->admin)
                ->putJson("/api/schedules/sessions/{$session->id}", [
                    'start_time' => '09:15',
                    'end_time' => '10:45',
                ])
                ->assertStatus(422)
                ->assertJsonPath('message', "Cannot edit a session on a '{$status}' schedule.");
        }

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'day_of_week' => 2,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);
    }

    #[Test]
    public function the_batch_path_still_reports_an_over_placement_through_conflicts(): void
    {
        // The batch endpoints have always reported hour problems as sentences in
        // `conflicts`, and the editor renders those strings verbatim. Sharing one
        // implementation with the legacy edit must not have changed that, and
        // `hours` is the legacy edit's key — the batch response does not gain it.
        $this->postSessions([
            $this->meeting(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
        ])->assertStatus(201);

        $session = $this->schedule->sessions()->firstOrFail();

        $response = $this->actingAs($this->admin)
            ->putJson("/api/schedules/{$this->schedule->id}/sessions", [
                'sessions' => [['id' => $session->id, 'end_time' => '12:00']],
            ]);

        $response->assertStatus(422);
        $this->assertArrayNotHasKey('hours', $response->json());

        $conflicts = implode(' ', $response->json('conflicts'));
        $this->assertStringContainsString('PROG1 needs only 2h of lecture time', $conflicts);
        $this->assertStringContainsString('would schedule 3h', $conflicts);

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'end_time' => '10:00:00',
        ]);
    }

    // -----------------------------------------------------------------------
    // 14. The legacy single-session edit sits on the same 30-minute grid
    //
    // A hand-placed meeting was previously free to start or end at any minute,
    // so the legacy path could produce a timetable the generator would never
    // build. It now applies the batch reviewer's three time checks — end after
    // start, start on the grid, whole 30-minute slots — to the times a partial
    // update would leave behind, which request-level validation cannot see.
    //
    // Placement still wins: a change that both clashes and lands off the grid
    // answers with the conflict it always did, so the last test here pins that
    // order rather than leaving it to chance.
    // -----------------------------------------------------------------------

    #[Test]
    public function a_legacy_edit_that_starts_off_the_half_hour_grid_is_rejected(): void
    {
        $session = $this->seedMeeting([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'start_time' => '09:15',
                'end_time' => '11:00',
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors', 'conflicts']);

        // The grid reason is keyed by the field that broke the rule, and the
        // off-grid figure is named so the admin does not have to look it up.
        $this->assertStringContainsString('09:15', $response->json('errors.start_time'));
        $this->assertArrayNotHasKey('end_time', $response->json('errors'));

        // Grid problems are not placement conflicts, so `conflicts` stays the
        // placement list it has always been — present, and empty here.
        $this->assertSame([], $response->json('conflicts'));

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);
    }

    #[Test]
    public function a_legacy_edit_that_ends_off_the_half_hour_grid_is_rejected(): void
    {
        $session = $this->seedMeeting([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'start_time' => '09:00',
                'end_time' => '10:45',
            ]);

        $response->assertStatus(422);

        // On-grid start, off-grid end: the failure is the duration, reported
        // against the end in the same words the batch reviewer uses.
        $this->assertArrayNotHasKey('start_time', $response->json('errors'));
        $this->assertStringContainsString('10:45', $response->json('errors.end_time'));
        $this->assertSame([], $response->json('conflicts'));

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'end_time' => '10:00:00',
        ]);
    }

    #[Test]
    public function a_legacy_edit_that_moves_a_meeting_onto_a_half_hour_boundary_saves(): void
    {
        $session = $this->seedMeeting([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        // The grid is 30 minutes, not whole hours: :30 has to be accepted.
        $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'start_time' => '09:30',
                'end_time' => '11:00',
            ])
            ->assertOk();

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'start_time' => '09:30:00',
            'end_time' => '11:00:00',
        ]);
    }

    #[Test]
    public function a_partial_legacy_edit_is_checked_against_the_stored_start_time(): void
    {
        $session = $this->seedMeeting([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        // Only `end_time` is sent, so the request carries no `start_time` for
        // the `after:start_time` rule to compare against and Laravel passes it.
        // The 09:00 it inherits is what makes 10:45 off-grid, so the merged pair
        // is the only place this can be caught.
        $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'end_time' => '10:45',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors', 'conflicts']);

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);
    }

    #[Test]
    public function a_partial_legacy_edit_that_would_end_before_it_starts_is_rejected(): void
    {
        $session = $this->seedMeeting([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        // Moving the start past the stored end leaves 10:30-10:00. The
        // `after:start_time` rule cannot see this — it is not sent an end at all
        // — so before the merged-state check this wrote an inverted meeting.
        $response = $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'start_time' => '10:30',
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors', 'conflicts']);

        $this->assertSame([], $response->json('conflicts'));
        $this->assertSame(
            'The end time must be later than the start time.',
            $response->json('errors.end_time'),
        );

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);
    }

    #[Test]
    public function a_partial_legacy_edit_of_only_the_end_time_saves_when_it_fits_the_grid(): void
    {
        $session = $this->seedMeeting([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        // The same shape of request as the rejection above, one hour later and
        // still inside PROG1's 2h budget.
        $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'end_time' => '11:00',
            ])
            ->assertOk();

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'end_time' => '11:00:00',
        ]);
    }

    #[Test]
    public function a_legacy_conflict_is_still_reported_before_an_off_grid_time(): void
    {
        $session = $this->seedMeeting([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $this->seedMeeting([
            'day_of_week' => 1,
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
        ]);

        // Dragged onto the other meeting, off the grid as well. Placement is
        // checked first, so the answer is the conflict this endpoint has always
        // given — and the response keeps its original shape, with no `errors`.
        $response = $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$session->id}", [
                'day_of_week' => 1,
                'start_time' => '12:15',
                'end_time' => '13:00',
            ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('conflicts'));
        $this->assertArrayNotHasKey('errors', $response->json());

        $this->assertDatabaseHas('schedule_sessions', [
            'id' => $session->id,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);
    }
}
