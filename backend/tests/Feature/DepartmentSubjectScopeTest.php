<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleSession;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Subject classification and department-based scheduling restrictions.
 *
 * The model:
 *   - MAJOR — owned by one department and schedulable only for sections whose
 *     department holds a `subject_department_access` grant. The owner's grant is
 *     mandatory; a second department may hold one too, which is how ELEC201 is
 *     shared with BIT.
 *   - MINOR — no owner, no grants, open to every department.
 *   - UNCLASSIFIED — not reviewed yet, so schedulable nowhere.
 *
 * The rule is enforced in four places — the section assignment gate, the scoped
 * subject list, the pre-generation gate, and the session validator — and every one
 * of them is covered here, including the two edits that could otherwise bypass it
 * (moving a section between departments, and moving/reclassifying a subject).
 */
class DepartmentSubjectScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Department $it;

    private Department $electronics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Department Head',
            'email' => 'admin@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        // The display names the seeder writes, so the refusal messages asserted
        // below are the same words an administrator reads on screen.
        $this->it = Department::create([
            'code' => 'BIT',
            'name' => 'Industrial Technology',
        ]);

        $this->electronics = Department::create([
            'code' => 'ELEC',
            'name' => 'BindTech Electronics',
        ]);
    }

    /** Attributes a section is stored with. */
    private function sectionAttributes(array $overrides = []): array
    {
        return array_merge([
            'name' => 'BIT 1A',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
            'student_count' => 30,
            'department_id' => $this->it->id,
        ], $overrides);
    }

    /** The POST body for creating a section. */
    private function sectionPayload(array $overrides = []): array
    {
        return array_merge($this->sectionAttributes(), ['subject_ids' => []], $overrides);
    }

    private function makeSection(array $overrides = []): Section
    {
        return Section::create($this->sectionAttributes($overrides));
    }

    /**
     * A year-1, 1st-semester subject so it matches the default section. The
     * classification follows the fixture's shape unless it is stated: a department
     * makes it a major, no department makes it a minor. A major is granted access
     * to its owner, exactly as the seeder and the API do — a major without that row
     * is schedulable nowhere, which `SubjectClassificationTest` pins separately.
     */
    private function makeSubject(
        string $code,
        ?int $departmentId,
        int $lectureHours = 2,
        int $labHours = 0,
        ?string $classification = null,
    ): Subject {
        $subject = Subject::create([
            'code' => $code,
            'title' => $code.' title',
            'year_level' => 1,
            'semester_name' => '1st Semester',
            'lecture_hours' => $lectureHours,
            'lab_hours' => $labHours,
            'department_id' => $departmentId,
            'classification' => $classification
                ?? ($departmentId === null
                    ? Subject::CLASSIFICATION_MINOR
                    : Subject::CLASSIFICATION_MAJOR),
        ]);

        if ($subject->isMajor()) {
            $subject->replaceDepartmentAccess([(int) $departmentId]);
        }

        return $subject;
    }

    /** A major owned by $owner but also granted to $extra departments. */
    private function makeSharedMajor(string $code, Department $owner, array $extra): Subject
    {
        $subject = $this->makeSubject($code, $owner->id);

        $subject->replaceDepartmentAccess(array_merge(
            [$owner->id],
            array_map(fn (Department $department) => $department->id, $extra)
        ));

        return $subject;
    }

    // ---------------------------------------------------------------------
    // Visibility: the subject list
    // ---------------------------------------------------------------------

    #[Test]
    public function the_subject_list_offers_majors_only_to_their_own_department(): void
    {
        $this->makeSubject('PROG1', $this->it->id);
        $this->makeSubject('CIRCUITS1', $this->electronics->id);
        $this->makeSubject('MATH1', null);

        $forIt = $this->actingAs($this->admin)->getJson('/api/subjects?department_id='.$this->it->id);
        $forIt->assertOk();
        $itCodes = array_column($forIt->json(), 'code');

        $forElectronics = $this->actingAs($this->admin)->getJson('/api/subjects?department_id='.$this->electronics->id);
        $forElectronics->assertOk();
        $electronicsCodes = array_column($forElectronics->json(), 'code');

        // Each department sees its own major plus every minor / shared subject…
        $this->assertContains('PROG1', $itCodes);
        $this->assertContains('MATH1', $itCodes);
        $this->assertContains('CIRCUITS1', $electronicsCodes);
        $this->assertContains('MATH1', $electronicsCodes);

        // …and not the other department's major.
        $this->assertNotContains('CIRCUITS1', $itCodes);
        $this->assertNotContains('PROG1', $electronicsCodes);
    }

    #[Test]
    public function the_subject_list_offers_a_shared_major_to_both_departments(): void
    {
        $this->makeSharedMajor('ELEC201', $this->electronics, [$this->it]);

        $forIt = array_column(
            $this->actingAs($this->admin)->getJson('/api/subjects?department_id='.$this->it->id)->json(),
            'code'
        );
        $forElectronics = array_column(
            $this->actingAs($this->admin)->getJson('/api/subjects?department_id='.$this->electronics->id)->json(),
            'code'
        );

        $this->assertContains('ELEC201', $forIt);
        $this->assertContains('ELEC201', $forElectronics);
    }

    #[Test]
    public function the_subject_list_never_offers_an_unclassified_subject_to_a_department(): void
    {
        $this->makeSubject('MYSTERY1', null, 2, 0, Subject::CLASSIFICATION_UNCLASSIFIED);

        foreach ([$this->it->id, $this->electronics->id] as $departmentId) {
            $codes = array_column(
                $this->actingAs($this->admin)
                    ->getJson('/api/subjects?department_id='.$departmentId)
                    ->json(),
                'code'
            );

            $this->assertNotContains('MYSTERY1', $codes);
        }

        // The unscoped list is the management screen's, and it must still show an
        // unclassified subject: someone has to be able to classify it.
        $unscoped = array_column(
            $this->actingAs($this->admin)->getJson('/api/subjects')->json(),
            'code'
        );

        $this->assertContains('MYSTERY1', $unscoped);
    }

    #[Test]
    public function departments_are_listed_for_the_ownership_pickers(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/departments')
            ->assertOk()
            ->assertJsonFragment(['code' => 'BIT'])
            ->assertJsonFragment(['code' => 'ELEC']);
    }

    // ---------------------------------------------------------------------
    // Visibility and scheduling: section assignment
    // ---------------------------------------------------------------------

    #[Test]
    public function a_minor_subject_may_be_assigned_to_any_department(): void
    {
        $minor = $this->makeSubject('MATH1', null);

        $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->sectionPayload([
                'name' => 'ELEC 1B',
                'department_id' => $this->electronics->id,
                'subject_ids' => [$minor->id],
            ]))
            ->assertCreated()
            ->assertJsonPath('subjects.0.code', 'MATH1');
    }

    #[Test]
    public function a_major_subject_may_be_assigned_to_its_own_department(): void
    {
        $major = $this->makeSubject('PROG1', $this->it->id);

        $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->sectionPayload(['subject_ids' => [$major->id]]))
            ->assertCreated()
            ->assertJsonPath('subjects.0.code', 'PROG1');
    }

    #[Test]
    public function a_shared_major_may_be_assigned_to_either_authorized_department(): void
    {
        $shared = $this->makeSharedMajor('ELEC201', $this->electronics, [$this->it]);

        $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->sectionPayload([
                'name' => 'BIT 2A',
                'subject_ids' => [$shared->id],
            ]))
            ->assertCreated()
            ->assertJsonPath('subjects.0.code', 'ELEC201');

        $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->sectionPayload([
                'name' => 'ELEC 1B',
                'department_id' => $this->electronics->id,
                'subject_ids' => [$shared->id],
            ]))
            ->assertCreated();
    }

    #[Test]
    public function a_major_subject_is_rejected_for_another_department_with_422(): void
    {
        $major = $this->makeSubject('PROG1', $this->it->id);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->sectionPayload([
                'name' => 'ELEC 1B',
                'department_id' => $this->electronics->id,
                'subject_ids' => [$major->id],
            ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('PROG1', $response->json('message'));
        $this->assertStringContainsString('Industrial Technology', $response->json('message'));

        // Nothing was created
        $this->assertSame(0, Section::count());
    }

    #[Test]
    public function an_unclassified_subject_cannot_be_assigned_to_any_section(): void
    {
        $unclassified = $this->makeSubject('MYSTERY1', null, 2, 0, Subject::CLASSIFICATION_UNCLASSIFIED);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->sectionPayload(['subject_ids' => [$unclassified->id]]));

        $response->assertStatus(422);
        $this->assertStringContainsString('MYSTERY1', $response->json('message'));
        $this->assertStringContainsString('has not been classified yet', $response->json('message'));

        $this->assertSame(0, Section::count());
    }

    #[Test]
    public function a_section_without_a_department_may_only_take_minor_subjects(): void
    {
        $major = $this->makeSubject('PROG1', $this->it->id);
        $minor = $this->makeSubject('MATH1', null);

        $rejected = $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->sectionPayload([
                'name' => 'UNASSIGNED',
                'department_id' => null,
                'subject_ids' => [$major->id],
            ]));
        $rejected->assertStatus(422);
        $this->assertStringContainsString('PROG1', $rejected->json('message'));

        $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->sectionPayload([
                'name' => 'UNASSIGNED',
                'department_id' => null,
                'subject_ids' => [$minor->id],
            ]))
            ->assertCreated();
    }

    #[Test]
    public function moving_a_section_to_another_department_is_refused_while_it_holds_a_foreign_major(): void
    {
        $section = $this->makeSection();
        $major = $this->makeSubject('PROG1', $this->it->id);
        $section->subjects()->attach($major->id);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/sections/{$section->id}", ['department_id' => $this->electronics->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('PROG1', $response->json('message'));

        // The section keeps its old department
        $this->assertSame($this->it->id, $section->fresh()->department_id);
    }

    // ---------------------------------------------------------------------
    // Scheduling: generation and the multi-session editor
    // ---------------------------------------------------------------------

    #[Test]
    public function generation_is_blocked_when_a_legacy_assignment_holds_another_departments_major(): void
    {
        $section = $this->makeSection([
            'name' => 'ELEC 1B',
            'department_id' => $this->electronics->id,
        ]);

        // Legacy / bypassed assignment: written straight to the pivot
        $major = $this->makeSubject('PROG1', $this->it->id);
        $section->subjects()->attach($major->id);

        Http::fake();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/schedules/generate/{$section->id}");

        $response->assertStatus(422);
        $this->assertStringContainsString('PROG1', $response->json('message'));
        $this->assertStringContainsString('Industrial Technology', $response->json('message'));

        // The AI engine must never have been contacted
        Http::assertNothingSent();
    }

    #[Test]
    public function generation_is_blocked_when_an_assigned_subject_is_unclassified(): void
    {
        $section = $this->makeSection();

        $unclassified = $this->makeSubject('MYSTERY1', null, 2, 0, Subject::CLASSIFICATION_UNCLASSIFIED);
        $section->subjects()->attach($unclassified->id);

        Http::fake();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/schedules/generate/{$section->id}");

        $response->assertStatus(422);
        $this->assertStringContainsString('MYSTERY1', $response->json('message'));
        $this->assertStringContainsString('has not been classified yet', $response->json('message'));

        Http::assertNothingSent();
    }

    #[Test]
    public function a_session_batch_rejects_a_major_subject_of_another_department(): void
    {
        $section = $this->makeSection([
            'name' => 'ELEC 1B',
            'department_id' => $this->electronics->id,
        ]);
        $major = $this->makeSubject('PROG1', $this->it->id);

        $schedule = Schedule::create(['section_id' => $section->id, 'status' => 'draft']);

        $faculty = Faculty::create([
            'name' => 'Prof. Test', 'faculty_type' => 'full_time',
            'max_teaching_load' => 24, 'is_active' => true,
        ]);
        $room = Room::create(['name' => 'R101', 'type' => 'lecture', 'capacity' => 40, 'status' => 'available']);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/schedules/{$schedule->id}/sessions", [
                'sessions' => [[
                    'subject_id' => $major->id,
                    'session_type' => 'lecture',
                    'day_of_week' => 1,
                    'start_time' => '08:00',
                    'end_time' => '10:00',
                    'room_id' => $room->id,
                    'faculty_id' => $faculty->id,
                ]],
            ]);

        $response->assertStatus(422);

        // The batch reviewer reports per-session reasons in `errors`, keyed by
        // the submitted index, while `message` stays the generic sentence.
        $reason = $response->json('errors.0.subject_id');
        $this->assertIsString($reason);
        $this->assertStringContainsString('PROG1', $reason);
        $this->assertStringContainsString('Industrial Technology', $reason);

        // Nothing was written
        $this->assertSame(0, ScheduleSession::where('schedule_id', $schedule->id)->count());
    }

    #[Test]
    public function a_minor_subject_is_accepted_by_the_session_batch_of_any_department(): void
    {
        $section = $this->makeSection([
            'name' => 'ELEC 1B',
            'department_id' => $this->electronics->id,
        ]);
        $minor = $this->makeSubject('MATH1', null, 3, 0);

        $schedule = Schedule::create(['section_id' => $section->id, 'status' => 'draft']);

        $faculty = Faculty::create([
            'name' => 'Prof. Test', 'faculty_type' => 'full_time',
            'max_teaching_load' => 24, 'is_active' => true,
        ]);
        $room = Room::create(['name' => 'R101', 'type' => 'lecture', 'capacity' => 40, 'status' => 'available']);

        $this->actingAs($this->admin)
            ->postJson("/api/schedules/{$schedule->id}/sessions", [
                'sessions' => [[
                    'subject_id' => $minor->id,
                    'session_type' => 'lecture',
                    'day_of_week' => 1,
                    'start_time' => '08:00',
                    'end_time' => '10:00',
                    'room_id' => $room->id,
                    'faculty_id' => $faculty->id,
                ]],
            ])
            ->assertCreated();

        $this->assertSame(1, ScheduleSession::where('schedule_id', $schedule->id)->count());
    }

    // ---------------------------------------------------------------------
    // Ownership edits cannot strand an existing assignment
    // ---------------------------------------------------------------------

    #[Test]
    public function changing_a_majors_department_is_refused_while_it_is_assigned_outside_the_new_one(): void
    {
        $section = $this->makeSection();
        $major = $this->makeSubject('PROG1', $this->it->id);
        $section->subjects()->attach($major->id);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/subjects/{$major->id}", [
                'code' => 'PROG1',
                'title' => 'Programming 1',
                'year_level' => 1,
                'semester_name' => '1st Semester',
                'lecture_hours' => 2,
                'lab_hours' => 0,
                'lab_room_type' => null,
                'is_active' => true,
                'department_id' => $this->electronics->id,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('BIT 1A', $response->json('message'));

        // The ownership change did not happen
        $this->assertSame($this->it->id, $major->fresh()->department_id);
    }

    #[Test]
    public function a_major_may_be_reclassified_as_a_minor_because_every_section_accepts_one(): void
    {
        $section = $this->makeSection();
        $major = $this->makeSubject('PROG1', $this->it->id);
        $section->subjects()->attach($major->id);

        $this->actingAs($this->admin)
            ->putJson("/api/subjects/{$major->id}", [
                'code' => 'PROG1',
                'title' => 'Programming 1',
                'year_level' => 1,
                'semester_name' => '1st Semester',
                'lecture_hours' => 2,
                'lab_hours' => 0,
                'lab_room_type' => null,
                'is_active' => true,
                'department_id' => null,
                'classification' => Subject::CLASSIFICATION_MINOR,
            ])
            ->assertOk();

        $major->refresh();

        $this->assertNull($major->department_id);
        $this->assertSame(Subject::CLASSIFICATION_MINOR, $major->classification);

        // A minor holds no access grants, so the old owner's row is gone.
        $this->assertSame([], $major->authorizedDepartmentIds());
        $this->assertSame(0, DB::table('subject_department_access')->where('subject_id', $major->id)->count());
    }

    #[Test]
    public function a_major_cannot_be_left_without_an_owner(): void
    {
        $major = $this->makeSubject('PROG1', $this->it->id);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/subjects/{$major->id}", [
                'code' => 'PROG1',
                'title' => 'Programming 1',
                'year_level' => 1,
                'semester_name' => '1st Semester',
                'lecture_hours' => 2,
                'lab_hours' => 0,
                'lab_room_type' => null,
                'is_active' => true,
                'classification' => Subject::CLASSIFICATION_MAJOR,
                'department_id' => null,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('major subject needs an owning department', $response->json('message'));
        $this->assertSame($this->it->id, $major->fresh()->department_id);
    }

    #[Test]
    public function a_minor_cannot_hold_an_owning_department(): void
    {
        $minor = $this->makeSubject('MATH1', null);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/subjects/{$minor->id}", [
                'code' => 'MATH1',
                'title' => 'College Algebra',
                'year_level' => 1,
                'semester_name' => '1st Semester',
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'lab_room_type' => null,
                'is_active' => true,
                'classification' => Subject::CLASSIFICATION_MINOR,
                'department_id' => $this->it->id,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('cannot have an owning department', $response->json('message'));
        $this->assertNull($minor->fresh()->department_id);
    }

    #[Test]
    public function a_major_edited_through_the_api_keeps_its_owner_access_grant(): void
    {
        $major = $this->makeSubject('PROG1', $this->it->id);

        $this->actingAs($this->admin)
            ->putJson("/api/subjects/{$major->id}", [
                'code' => 'PROG1',
                'title' => 'Programming 1 Revised',
                'year_level' => 1,
                'semester_name' => '1st Semester',
                'lecture_hours' => 2,
                'lab_hours' => 0,
                'lab_room_type' => null,
                'is_active' => true,
                'classification' => Subject::CLASSIFICATION_MAJOR,
                'department_id' => $this->it->id,
                'access_department_ids' => [$this->electronics->id],
            ])
            ->assertOk();

        // Owner plus the deliberately granted department, stored in one place.
        $this->assertEqualsCanonicalizing(
            [$this->it->id, $this->electronics->id],
            $major->fresh()->authorizedDepartmentIds()
        );
    }

    #[Test]
    public function a_subject_created_with_a_department_is_stored_as_a_major(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/subjects', [
                'code' => 'PROG9',
                'title' => 'Programming 9',
                'year_level' => 1,
                'semester_name' => '1st Semester',
                'lecture_hours' => 2,
                'lab_hours' => 0,
                'lab_room_type' => null,
                'is_active' => true,
                'department_id' => $this->it->id,
            ]);

        $response->assertCreated();

        $subject = Subject::where('code', 'PROG9')->firstOrFail();
        $this->assertSame(Subject::CLASSIFICATION_MAJOR, $subject->classification);
        $this->assertEqualsCanonicalizing([$this->it->id], $subject->authorizedDepartmentIds());
    }

    // ---------------------------------------------------------------------
    // The classification backfill itself
    // ---------------------------------------------------------------------

    #[Test]
    public function the_seeder_classifies_the_reviewed_curriculum(): void
    {
        foreach (array_merge(
            array_merge(...array_values(DepartmentSeeder::MAJORS)),
            DepartmentSeeder::MINORS
        ) as $code) {
            $this->makeSubject($code, null, 2, 0, Subject::CLASSIFICATION_UNCLASSIFIED);
        }

        // An unreviewed code that is on no list must be left alone.
        $this->makeSubject('UNKNOWN9', null, 2, 0, Subject::CLASSIFICATION_UNCLASSIFIED);

        $this->makeSection(['name' => 'BIT-2A']);
        $this->makeSection(['name' => 'ELEC-1B', 'department_id' => null]);

        $this->seed(DepartmentSeeder::class);

        // Every major is classified as one and owned by its own department…
        foreach (DepartmentSeeder::MAJORS['BIT'] as $code) {
            $subject = Subject::where('code', $code)->firstOrFail();

            $this->assertSame(Subject::CLASSIFICATION_MAJOR, $subject->classification, $code);
            $this->assertSame($this->it->id, (int) $subject->department_id, $code);
            $this->assertContains($this->it->id, $subject->authorizedDepartmentIds(), $code);
        }

        foreach (DepartmentSeeder::MAJORS['ELEC'] as $code) {
            $subject = Subject::where('code', $code)->firstOrFail();

            $this->assertSame(Subject::CLASSIFICATION_MAJOR, $subject->classification, $code);
            $this->assertSame($this->electronics->id, (int) $subject->department_id, $code);
        }

        // …and every service subject is a minor with no owner and no grants.
        foreach (DepartmentSeeder::MINORS as $code) {
            $subject = Subject::where('code', $code)->firstOrFail();

            $this->assertSame(Subject::CLASSIFICATION_MINOR, $subject->classification, $code);
            $this->assertNull($subject->department_id, $code);
            $this->assertSame([], $subject->authorizedDepartmentIds(), $code);
        }

        // A code on no list stays unclassified, so nothing was guessed for it.
        $unknown = Subject::where('code', 'UNKNOWN9')->firstOrFail();
        $this->assertSame(Subject::CLASSIFICATION_UNCLASSIFIED, $unknown->classification);
        $this->assertNull($unknown->department_id);

        // Sections are claimed by name prefix.
        $this->assertSame($this->it->id, Section::where('name', 'BIT-2A')->value('department_id'));
        $this->assertSame($this->electronics->id, Section::where('name', 'ELEC-1B')->value('department_id'));
    }

    #[Test]
    public function the_seeder_grants_the_shared_major_to_both_departments(): void
    {
        foreach (['ELEC', 'ELEC201', 'MATH1'] as $code) {
            $this->makeSubject($code, null, 2, 0, Subject::CLASSIFICATION_UNCLASSIFIED);
        }

        $this->seed(DepartmentSeeder::class);

        $shared = Subject::where('code', 'ELEC201')->firstOrFail();

        $this->assertSame(Subject::CLASSIFICATION_MAJOR, $shared->classification);
        $this->assertSame($this->electronics->id, (int) $shared->department_id);
        $this->assertEqualsCanonicalizing(
            [$this->electronics->id, $this->it->id],
            $shared->authorizedDepartmentIds()
        );
    }

    #[Test]
    public function the_classification_backfill_is_idempotent_and_keeps_existing_bit_assignments_valid(): void
    {
        // The live shape this backfill must not break: a BIT section that already
        // holds the electronics subjects as well as its own IT major.
        $elec101 = $this->makeSubject('ELEC101', null, 2, 0, Subject::CLASSIFICATION_UNCLASSIFIED);
        $elec201 = $this->makeSubject('ELEC201', null, 2, 0, Subject::CLASSIFICATION_UNCLASSIFIED);
        $prog1 = $this->makeSubject('PROG1', null, 2, 0, Subject::CLASSIFICATION_UNCLASSIFIED);

        $bit = $this->makeSection(['name' => 'BIT-1A', 'department_id' => null]);
        $bit->subjects()->attach([$elec101->id, $elec201->id, $prog1->id]);

        $this->seed(DepartmentSeeder::class);
        $this->seed(DepartmentSeeder::class);

        // Re-running converges instead of duplicating.
        $this->assertSame(2, Department::count());
        $this->assertSame(3, DB::table('subject_department_access')->count());

        $bit->refresh();
        $this->assertSame($this->it->id, $bit->department_id);

        // The section is now IT's, so its own major is schedulable…
        $this->assertTrue($prog1->fresh()->isSchedulableBySection($bit));

        // …ELEC101 is a minor, open to everyone…
        $this->assertTrue($elec101->fresh()->isSchedulableBySection($bit));

        // …and ELEC201 is a major of ELEC that is explicitly granted to BIT, so
        // the assignments the section already had remain legal.
        $this->assertTrue($elec201->fresh()->isSchedulableBySection($bit));

        // Nothing was detached, and no session or schedule data was involved.
        $this->assertSame(3, $bit->subjects()->count());
    }

    #[Test]
    public function re_seeding_does_not_duplicate_grants_or_churn_timestamps(): void
    {
        foreach (['PROG1', 'MATH1'] as $code) {
            $this->makeSubject($code, null, 2, 0, Subject::CLASSIFICATION_UNCLASSIFIED);
        }

        $this->seed(DepartmentSeeder::class);

        // Raw column reads, so the comparison is over scalars rather than over
        // freshly hydrated objects.
        $subjectTimestamps = DB::table('subjects')->orderBy('id')->pluck('updated_at', 'id')->all();
        $departmentTimestamps = DB::table('departments')->orderBy('id')->pluck('updated_at', 'id')->all();
        $grants = DB::table('subject_department_access')
            ->orderBy('subject_id')->orderBy('department_id')
            ->pluck('department_id', 'subject_id')->all();

        $this->seed(DepartmentSeeder::class);

        $this->assertSame(
            $grants,
            DB::table('subject_department_access')
                ->orderBy('subject_id')->orderBy('department_id')
                ->pluck('department_id', 'subject_id')->all()
        );
        $this->assertSame($subjectTimestamps, DB::table('subjects')->orderBy('id')->pluck('updated_at', 'id')->all());
        $this->assertSame($departmentTimestamps, DB::table('departments')->orderBy('id')->pluck('updated_at', 'id')->all());
    }

    #[Test]
    public function the_seeder_repairs_a_wrong_owner_and_withdraws_a_stale_grant(): void
    {
        $this->makeSubject('MATH1', null, 2, 0, Subject::CLASSIFICATION_UNCLASSIFIED);
        $prog1 = $this->makeSubject('PROG1', $this->electronics->id);

        // The wrong owner and an access grant that the mapping does not call for.
        $prog1->replaceDepartmentAccess([$this->electronics->id, $this->it->id]);

        $this->seed(DepartmentSeeder::class);

        $prog1->refresh();

        $this->assertSame($this->it->id, (int) $prog1->department_id);
        $this->assertEqualsCanonicalizing([$this->it->id], $prog1->authorizedDepartmentIds());
    }
}
