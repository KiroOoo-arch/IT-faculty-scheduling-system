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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The bulk half of the review queue: what happens when an administrator ticks
 * unreviewed subjects and classifies them together.
 *
 * There is no bulk endpoint on purpose. The screen sends the ordinary PUT that a
 * single subject would get, once per ticked subject, so every backend rule still
 * applies to each subject on its own — the classification / ownership rules, the
 * lab consistency rule and the stranded-section check are evaluated per subject,
 * and a refusal is one subject's, not the batch's. That is why these tests drive
 * the sequence through the real API rather than calling a service method: the
 * request body in `reviewPayload()` is the one `SubjectsPage.reviewPayload()`
 * builds, so the screen and these tests cannot drift apart.
 *
 * What is pinned here: a single tick, a few ticks and every tick each classify
 * correctly; a pass that half succeeds reports exactly which subjects changed;
 * reviewing a selected subject as a Major still requires an owner and an explicit
 * grant for that owner; a subject left unreviewed by any of this is still
 * unschedulable; and none of it disturbs sections, schedules or sessions.
 */
class BulkSubjectReviewTest extends TestCase
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
        $this->it = Department::create(['code' => 'BIT', 'name' => 'Industrial Technology']);
        $this->electronics = Department::create(['code' => 'ELEC', 'name' => 'BindTech Electronics']);
    }

    // ---------------------------------------------------------------------
    // Fixtures, and the requests the screen actually sends
    // ---------------------------------------------------------------------

    /** An unreviewed subject — what the queue is made of. */
    private function unreviewed(string $code, int $labHours = 0, ?string $labRoomType = null): Subject
    {
        return Subject::create([
            'code' => $code,
            'title' => $code.' title',
            'year_level' => 1,
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => $labHours,
            'lab_room_type' => $labRoomType,
            'is_active' => true,
            'classification' => Subject::CLASSIFICATION_UNCLASSIFIED,
        ]);
    }

    /**
     * A subject that already carries a review outcome, with the owner's grant
     * written as the seeder and the API write it.
     */
    private function reviewed(string $code, string $classification, ?Department $owner = null): Subject
    {
        $subject = Subject::create([
            'code' => $code,
            'title' => $code.' title',
            'year_level' => 1,
            'semester_name' => '1st Semester',
            'lecture_hours' => 3,
            'lab_hours' => 0,
            'is_active' => true,
            'classification' => $classification,
            'department_id' => $owner?->id,
        ]);

        if ($owner !== null) {
            $subject->replaceDepartmentAccess([$owner->id]);
        }

        return $subject;
    }

    /** A department's section, as the seeder and the Section editor store one. */
    private function makeSection(string $name, ?Department $department): Section
    {
        return Section::create([
            'name' => $name,
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
            'student_count' => 30,
            'department_id' => $department?->id,
        ]);
    }

    /**
     * The body the review screen builds for one subject, field for field: every
     * stored field is sent, and the review outcome is always explicit — an absent
     * classification would be read as `unclassified`, which is exactly the
     * inference the screen must not rely on.
     *
     * @param  list<int>  $accessIds
     * @return array<string, mixed>
     */
    private function reviewPayload(Subject $subject, string $classification, ?int $ownerId, array $accessIds): array
    {
        return [
            'code' => $subject->code,
            'title' => $subject->title,
            'year_level' => $subject->year_level,
            'semester_name' => $subject->semester_name,
            'lecture_hours' => $subject->lecture_hours,
            'lab_hours' => $subject->lab_hours,
            'lab_room_type' => $subject->lab_room_type ?: null,
            'is_active' => $subject->is_active,
            'classification' => $classification,
            'department_id' => $ownerId,
            'access_department_ids' => $accessIds,
        ];
    }

    /** Review one subject, exactly as the single-row action does. */
    private function review(Subject $subject, string $classification, ?int $ownerId, array $accessIds)
    {
        return $this->actingAs($this->admin)->putJson(
            "/api/subjects/{$subject->id}",
            $this->reviewPayload($subject, $classification, $ownerId, $accessIds)
        );
    }

    /**
     * The bulk action, written out: one Minor / shared request per selected
     * subject, in the order they appear in the queue, reporting the status each
     * subject's own request returned.
     *
     * @param  list<Subject>  $selection
     * @return array<string, int>
     */
    private function classifySelectionAsMinor(array $selection): array
    {
        $statuses = [];

        foreach ($selection as $subject) {
            $statuses[$subject->code] = $this->review($subject, Subject::CLASSIFICATION_MINOR, null, [])
                ->getStatusCode();
        }

        return $statuses;
    }

    /**
     * The queue as the screen reads it: the whole subject list, filtered to what
     * is still unclassified. Deliberately not a server-side filter — the screen
     * filters the list it already has, so the test does the same.
     *
     * @return list<string>
     */
    private function reviewQueue(): array
    {
        $subjects = $this->actingAs($this->admin)->getJson('/api/subjects')->assertOk()->json();

        $codes = [];

        foreach ($subjects as $subject) {
            if ($subject['classification'] === Subject::CLASSIFICATION_UNCLASSIFIED) {
                $codes[] = $subject['code'];
            }
        }

        sort($codes);

        return $codes;
    }

    /** Everything a review pass must not disturb, as comparable rows. */
    private function schedulingSnapshot(): array
    {
        $rows = fn (string $table, string $order): array => DB::table($table)
            ->orderByRaw($order)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        return [
            'sections' => $rows('sections', 'id'),
            'section_subjects' => $rows('section_subjects', 'section_id, subject_id'),
            'schedules' => $rows('schedules', 'id'),
            'schedule_sessions' => $rows('schedule_sessions', 'id'),
            'faculty_subjects' => $rows('faculty_subjects', 'faculty_id, subject_id'),
        ];
    }

    // ---------------------------------------------------------------------
    // One selected subject
    // ---------------------------------------------------------------------

    #[Test]
    public function one_selected_subject_is_classified_and_the_others_stay_unreviewed(): void
    {
        $chosen = $this->unreviewed('BK1', 2, 'computer_lab');
        $second = $this->unreviewed('BK2');
        $third = $this->unreviewed('BK3');

        $untouched = [Subject::findOrFail($second->id), Subject::findOrFail($third->id)];

        $this->assertSame(200, $this->classifySelectionAsMinor([$chosen])['BK1']);

        // The chosen subject is a minor: no owner and no grants, because a grant
        // would imply an authorization decision that was never made.
        $chosen->refresh();
        $this->assertSame(Subject::CLASSIFICATION_MINOR, $chosen->classification);
        $this->assertNull($chosen->department_id);
        $this->assertSame([], $chosen->authorizedDepartmentIds());

        // Its lab hours and lab room type are untouched by the classification —
        // the payload carries them, it does not invent them.
        $this->assertSame(2, (int) $chosen->lab_hours);
        $this->assertSame('computer_lab', $chosen->lab_room_type);

        // The subjects that were not ticked were not written to at all.
        foreach ($untouched as $before) {
            $after = Subject::findOrFail($before->id);

            $this->assertSame(Subject::CLASSIFICATION_UNCLASSIFIED, $after->classification);
            $this->assertSame([], $after->authorizedDepartmentIds());
            $this->assertEquals($before->updated_at, $after->updated_at, "{$before->code} must not have been touched");
        }

        // And the queue now holds exactly the two that are left.
        $this->assertSame(['BK2', 'BK3'], $this->reviewQueue());
    }

    // ---------------------------------------------------------------------
    // A few selected subjects
    // ---------------------------------------------------------------------

    #[Test]
    public function several_selected_subjects_are_classified_in_one_pass_and_the_rest_are_left_alone(): void
    {
        // The reviewed half of the list, which a pass must not restate.
        $major = $this->reviewed('PROG1', Subject::CLASSIFICATION_MAJOR, $this->it);
        $minor = $this->reviewed('MATH1', Subject::CLASSIFICATION_MINOR);

        $first = $this->unreviewed('BK1');
        $second = $this->unreviewed('BK2');
        $left = [$this->unreviewed('BK3'), $this->unreviewed('BK4')];

        $majorStamp = $major->updated_at;
        $minorStamp = $minor->updated_at;

        // Two of the four ticked, in queue order.
        $statuses = $this->classifySelectionAsMinor([$first, $second]);

        $this->assertSame(['BK1' => 200, 'BK2' => 200], $statuses);

        foreach ([$first, $second] as $subject) {
            $subject->refresh();
            $this->assertSame(Subject::CLASSIFICATION_MINOR, $subject->classification);
            $this->assertNull($subject->department_id);
            $this->assertSame([], $subject->authorizedDepartmentIds());
        }

        // The two that were not ticked are still waiting for a decision.
        foreach ($left as $subject) {
            $this->assertSame(Subject::CLASSIFICATION_UNCLASSIFIED, $subject->fresh()->classification);
        }

        // The reviewed subjects keep their classification, owner and grants.
        $major->refresh();
        $this->assertSame(Subject::CLASSIFICATION_MAJOR, $major->classification);
        $this->assertSame($this->it->id, (int) $major->department_id);
        $this->assertSame([$this->it->id], $major->authorizedDepartmentIds());
        $this->assertEquals($majorStamp, $major->updated_at);

        $this->assertSame(Subject::CLASSIFICATION_MINOR, $minor->fresh()->classification);
        $this->assertSame([], $minor->fresh()->authorizedDepartmentIds());
        $this->assertEquals($minorStamp, $minor->fresh()->updated_at);

        $this->assertSame(['BK3', 'BK4'], $this->reviewQueue());
    }

    // ---------------------------------------------------------------------
    // Every unreviewed subject at once
    // ---------------------------------------------------------------------

    #[Test]
    public function selecting_every_subject_empties_the_queue(): void
    {
        foreach (['BK1', 'BK2', 'BK3'] as $code) {
            $this->unreviewed($code);
        }

        $queue = $this->reviewQueue();
        $this->assertSame(['BK1', 'BK2', 'BK3'], $queue);

        // Select all, then classify: the selection is the queue, in its order.
        $selection = Subject::whereIn('code', $queue)->orderBy('code')->get()->all();
        $statuses = $this->classifySelectionAsMinor($selection);

        $this->assertSame(['BK1' => 200, 'BK2' => 200, 'BK3' => 200], $statuses);

        // Nothing is left outstanding, and every subject is a minor holding no
        // grant — one access row per remaining major would be a decision nobody
        // made.
        $this->assertSame([], $this->reviewQueue());

        foreach ($queue as $code) {
            $subject = Subject::where('code', $code)->firstOrFail();

            $this->assertSame(Subject::CLASSIFICATION_MINOR, $subject->classification);
            $this->assertNull($subject->department_id);
            $this->assertSame([], $subject->authorizedDepartmentIds());
        }

        $this->assertSame(0, DB::table('subject_department_access')->count());
    }

    // ---------------------------------------------------------------------
    // Partial failure
    // ---------------------------------------------------------------------

    #[Test]
    public function a_pass_that_half_succeeds_reports_which_subjects_changed_and_which_did_not(): void
    {
        $fine = $this->unreviewed('BK1');
        $second = $this->unreviewed('BK2');

        // A row the review screen cannot save as it stands: lab hours with no lab
        // room type. The Subjects form refuses this combination, so it can only
        // reach the database from a legacy import — which is precisely the case
        // where a batch has to report one failure and keep going.
        $broken = $this->unreviewed('LEGACY1', 2, null);
        $brokenStamp = $broken->updated_at;

        $statuses = $this->classifySelectionAsMinor([$fine, $broken, $second]);

        $this->assertSame(['BK1' => 200, 'LEGACY1' => 422, 'BK2' => 200], $statuses);

        // The refusal is readable and specific, so the screen can put the reason
        // beside the one subject it belongs to.
        $refusal = $this->review($broken, Subject::CLASSIFICATION_MINOR, null, []);
        $refusal->assertStatus(422);
        $this->assertStringContainsString('Lab room type is required', $refusal->json('message'));
        $this->assertIsArray($refusal->json('errors.lab_room_type'));

        // The refused subject was not written to at all: it is still waiting for a
        // decision, it still owns nothing, and its timestamp is untouched.
        $broken->refresh();
        $this->assertSame(Subject::CLASSIFICATION_UNCLASSIFIED, $broken->classification);
        $this->assertNull($broken->department_id);
        $this->assertSame([], $broken->authorizedDepartmentIds());
        $this->assertEquals($brokenStamp, $broken->updated_at);

        // The two that could be classified were, and the queue now holds exactly
        // the refused one — the subject the screen leaves ticked for a retry.
        $this->assertSame(Subject::CLASSIFICATION_MINOR, $fine->fresh()->classification);
        $this->assertSame(Subject::CLASSIFICATION_MINOR, $second->fresh()->classification);
        $this->assertSame(['LEGACY1'], $this->reviewQueue());
    }

    // ---------------------------------------------------------------------
    // Reviewing a selection as Major
    // ---------------------------------------------------------------------

    #[Test]
    public function a_major_reviewed_from_the_selection_still_needs_an_owning_department(): void
    {
        $subject = $this->unreviewed('BK1');

        $response = $this->review($subject, Subject::CLASSIFICATION_MAJOR, null, []);

        $response->assertStatus(422);
        $this->assertStringContainsString('needs an owning department', $response->json('message'));

        // Nothing was recorded: it is still unreviewed and still the queue's work.
        $subject->refresh();
        $this->assertSame(Subject::CLASSIFICATION_UNCLASSIFIED, $subject->classification);
        $this->assertNull($subject->department_id);
        $this->assertSame([], $subject->authorizedDepartmentIds());
        $this->assertSame(['BK1'], $this->reviewQueue());
    }

    #[Test]
    public function a_major_reviewed_from_the_selection_keeps_its_owner_and_the_departments_chosen_for_it(): void
    {
        // The owner alone: the form sends the owner in the grant list, and the API
        // keeps it there regardless, so a major is never struck with no grant.
        $owned = $this->unreviewed('ELEC301');
        $this->assertSame(200, $this->review($owned, Subject::CLASSIFICATION_MAJOR, $this->electronics->id, [])
            ->getStatusCode());

        $owned->refresh();
        $this->assertSame(Subject::CLASSIFICATION_MAJOR, $owned->classification);
        $this->assertSame($this->electronics->id, (int) $owned->department_id);
        $this->assertEqualsCanonicalizing([$this->electronics->id], $owned->authorizedDepartmentIds());

        // Owner plus one explicitly authorized department — the ELEC201 shape, where
        // a second department may schedule another department's major.
        $shared = $this->unreviewed('ELEC302');
        $status = $this->review(
            $shared,
            Subject::CLASSIFICATION_MAJOR,
            $this->electronics->id,
            [$this->it->id]
        )->getStatusCode();

        $this->assertSame(200, $status);

        $shared->refresh();
        $this->assertSame($this->electronics->id, (int) $shared->department_id);
        $this->assertEqualsCanonicalizing(
            [$this->electronics->id, $this->it->id],
            $shared->authorizedDepartmentIds()
        );

        // And the authorization is what scheduling reads: either department's
        // section may take it, a section with no department may not.
        $this->assertTrue($shared->isSchedulableBySection($this->makeSection('ELEC 1A', $this->electronics)));
        $this->assertTrue($shared->isSchedulableBySection($this->makeSection('BIT 1A', $this->it)));
        $this->assertFalse($shared->isSchedulableBySection($this->makeSection('NOWHERE', null)));

        // Both left the queue as majors. The owner-only one is offered to its own
        // department and to nobody else.
        $this->assertSame([], $this->reviewQueue());

        $forIt = array_column(
            $this->actingAs($this->admin)->getJson('/api/subjects?department_id='.$this->it->id)->json(),
            'code'
        );

        $this->assertContains('ELEC302', $forIt);
        $this->assertNotContains('ELEC301', $forIt);
    }

    // ---------------------------------------------------------------------
    // A subject the pass could not classify is still unschedulable
    // ---------------------------------------------------------------------

    #[Test]
    public function a_subject_a_pass_could_not_classify_remains_unschedulable(): void
    {
        $broken = $this->unreviewed('LEGACY1', 2, null);
        $fine = $this->unreviewed('BK1');

        $this->classifySelectionAsMinor([$fine, $broken]);
        $this->assertSame(['LEGACY1'], $this->reviewQueue());

        // Not offered to any department's section editor…
        foreach ([$this->it->id, $this->electronics->id] as $departmentId) {
            $offered = array_column(
                $this->actingAs($this->admin)->getJson('/api/subjects?department_id='.$departmentId)->json(),
                'code'
            );

            $this->assertNotContains('LEGACY1', $offered);
        }

        // …refused when a section is asked for it outright…
        $refused = $this->actingAs($this->admin)->postJson('/api/sections', [
            'name' => 'BIT 1A',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
            'student_count' => 30,
            'department_id' => $this->it->id,
            'subject_ids' => [$broken->id],
        ]);

        $refused->assertStatus(422);
        $this->assertStringContainsString('LEGACY1', $refused->json('message'));
        $this->assertStringContainsString('has not been classified yet', $refused->json('message'));
        $this->assertSame(0, Section::count());

        // …and refused by generation, without the engine ever being asked.
        $section = $this->makeSection('BIT 1A', $this->it);
        $section->subjects()->attach($broken->id);

        Http::fake();

        $generation = $this->actingAs($this->admin)->postJson("/api/schedules/generate/{$section->id}");

        $generation->assertStatus(422);
        $this->assertStringContainsString('LEGACY1', $generation->json('message'));
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------------
    // What a pass must not disturb
    // ---------------------------------------------------------------------

    #[Test]
    public function classifying_subjects_leaves_sections_schedules_and_sessions_alone(): void
    {
        $major = $this->reviewed('PROG1', Subject::CLASSIFICATION_MAJOR, $this->it);

        $faculty = Faculty::create([
            'name' => 'Prof. Test', 'faculty_type' => 'full_time',
            'max_teaching_load' => 24, 'is_active' => true,
        ]);
        $major->faculties()->attach($faculty->id);

        $room = Room::create(['name' => 'R101', 'type' => 'lecture', 'capacity' => 40, 'status' => 'available']);

        $section = $this->makeSection('BIT 1A', $this->it);
        $section->subjects()->attach($major->id);

        $schedule = Schedule::create(['section_id' => $section->id, 'status' => 'draft']);
        ScheduleSession::create([
            'schedule_id' => $schedule->id,
            'subject_id' => $major->id,
            'faculty_id' => $faculty->id,
            'room_id' => $room->id,
            'session_type' => 'lecture',
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '09:00',
        ]);

        // An unreviewed subject already attached to that section — a legacy
        // assignment, since the assignment gate refuses one today. Classifying it
        // as Minor / shared must be allowed (a minor is compatible with every
        // section) and must leave the section holding it.
        $pending = $this->unreviewed('BK1');
        $section->subjects()->attach($pending->id);

        $before = $this->schedulingSnapshot();

        $this->assertSame(200, $this->classifySelectionAsMinor([$pending])['BK1']);

        $this->assertEquals($before, $this->schedulingSnapshot());

        // The section still holds both subjects, the new minor included.
        $this->assertEqualsCanonicalizing(
            ['BK1', 'PROG1'],
            $section->fresh()->subjects()->orderBy('code')->pluck('subjects.code')->all()
        );

        // And the newly classified subject is now genuinely schedulable there,
        // rather than merely attached.
        $this->assertTrue($pending->fresh()->isSchedulableBySection($section->fresh()));
    }
}
