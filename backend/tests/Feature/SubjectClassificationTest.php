<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The classification contract itself: what the database refuses, what a new
 * subject defaults to, and the approved mapping the seeder writes.
 *
 * These are the invariants the rest of the feature rests on. A major with no
 * owner, a minor that claims one, or an unknown subject that quietly became
 * schedulable would all be representable if only the application enforced them —
 * so the important ones are pinned at the database level here.
 */
class SubjectClassificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The approved curriculum, written out rather than read from the seeder, so
     * this test states the requirement instead of restating the implementation.
     *
     * @var list<string>
     */
    private const EXPECTED_MAJORS = [
        // BIT (15)
        'PROG1', 'PROG101', 'PROG201', 'PROG3', 'ADVPROG', 'DSA201', 'DB201', 'NET201', 'IAS',
        'TPCC 311', 'TPCC 312', 'TPCC 313', 'TPCC 314', 'TACC 311', 'TACC 312',
        // ELEC owns ELEC and ELEC201
        'ELEC', 'ELEC201',
    ];

    /** @var list<string> */
    private const EXPECTED_MINORS = [
        'MATH1', 'FOLA', 'LEC', 'REG-LEC', 'REG-LAB', 'REG-S2',
        'GE101', 'GE102', 'MATH101', 'ELEC101', 'GE 7', 'GEE 3', 'FL 1',
    ];

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

        $this->it = Department::create(['code' => 'BIT', 'name' => 'Industrial Technology']);
        $this->electronics = Department::create(['code' => 'ELEC', 'name' => 'BindTech Electronics']);
    }

    private function makeSubject(string $code, ?string $classification = null, ?int $departmentId = null): Subject
    {
        $attributes = [
            'code' => $code,
            'title' => $code.' title',
            'year_level' => 1,
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'department_id' => $departmentId,
        ];

        if ($classification !== null) {
            $attributes['classification'] = $classification;
        }

        return Subject::create($attributes);
    }

    private function makeSection(string $name, ?int $departmentId): Section
    {
        $section = Section::create([
            'name' => $name,
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
            'student_count' => 30,
        ]);

        if ($departmentId !== null) {
            DB::table('sections')->where('id', $section->id)->update(['department_id' => $departmentId]);
            $section->refresh();
        }

        return $section;
    }

    // ---------------------------------------------------------------------
    // Defaults and database invariants
    // ---------------------------------------------------------------------

    #[Test]
    public function a_new_subject_defaults_to_unclassified_never_minor(): void
    {
        $subject = $this->makeSubject('MYSTERY1');

        $this->assertSame(Subject::CLASSIFICATION_UNCLASSIFIED, $subject->fresh()->classification);

        // And unclassified grants nothing: not to a department's section, not to a
        // section with no department.
        $this->assertFalse($subject->isSchedulableBySection($this->makeSection('BIT 1A', $this->it->id)));
        $this->assertFalse($subject->isSchedulableBySection($this->makeSection('NOWHERE', null)));
    }

    #[Test]
    public function the_database_refuses_a_classification_outside_the_three_review_outcomes(): void
    {
        $this->expectException(QueryException::class);

        $this->makeSubject('MYSTERY1', 'shared');
    }

    #[Test]
    public function the_database_refuses_a_major_with_no_owning_department(): void
    {
        $this->expectException(QueryException::class);

        $this->makeSubject('PROG1', Subject::CLASSIFICATION_MAJOR, null);
    }

    #[Test]
    public function the_database_refuses_a_minor_that_claims_an_owning_department(): void
    {
        $this->expectException(QueryException::class);

        $this->makeSubject('MATH1', Subject::CLASSIFICATION_MINOR, $this->it->id);
    }

    #[Test]
    public function access_rows_are_unique_per_subject_and_department(): void
    {
        $subject = $this->makeSubject('PROG1', Subject::CLASSIFICATION_MAJOR, $this->it->id);

        DB::table('subject_department_access')->insert([
            'subject_id' => $subject->id,
            'department_id' => $this->it->id,
        ]);

        $this->expectException(QueryException::class);

        DB::table('subject_department_access')->insert([
            'subject_id' => $subject->id,
            'department_id' => $this->it->id,
        ]);
    }

    #[Test]
    public function deleting_a_subject_or_department_withdraws_its_access_rows(): void
    {
        $subject = $this->makeSubject('PROG1', Subject::CLASSIFICATION_MAJOR, $this->it->id);
        $subject->replaceDepartmentAccess([$this->it->id, $this->electronics->id]);

        $this->assertSame(2, DB::table('subject_department_access')->where('subject_id', $subject->id)->count());

        $subject->delete();

        $this->assertSame(0, DB::table('subject_department_access')->count());
    }

    // ---------------------------------------------------------------------
    // The approved mapping
    // ---------------------------------------------------------------------

    #[Test]
    public function the_seeder_produces_seventeen_majors_and_thirteen_minor_subjects(): void
    {
        foreach (array_merge(self::EXPECTED_MAJORS, self::EXPECTED_MINORS, ['UNKNOWN9']) as $code) {
            $this->makeSubject($code, Subject::CLASSIFICATION_UNCLASSIFIED);
        }

        $this->seed(DepartmentSeeder::class);

        // 1 & 2: the approved totals, measured from the database.
        $this->assertSame(17, DB::table('subjects')->where('classification', 'major')->count());
        $this->assertSame(13, DB::table('subjects')->where('classification', 'minor')->count());
        $this->assertSame(1, DB::table('subjects')->where('classification', 'unclassified')->count());

        $this->assertEqualsCanonicalizing(
            self::EXPECTED_MAJORS,
            DB::table('subjects')->where('classification', 'major')->pluck('code')->all()
        );

        $this->assertEqualsCanonicalizing(
            self::EXPECTED_MINORS,
            DB::table('subjects')->where('classification', 'minor')->pluck('code')->all()
        );

        // The unreviewed code on no list was left exactly as it was.
        $unknown = Subject::where('code', 'UNKNOWN9')->firstOrFail();
        $this->assertSame(Subject::CLASSIFICATION_UNCLASSIFIED, $unknown->classification);
        $this->assertNull($unknown->department_id);
    }

    #[Test]
    public function the_seeder_gives_every_major_its_owner_and_no_minor_any_grant(): void
    {
        foreach (array_merge(self::EXPECTED_MAJORS, self::EXPECTED_MINORS) as $code) {
            $this->makeSubject($code, Subject::CLASSIFICATION_UNCLASSIFIED);
        }

        $this->seed(DepartmentSeeder::class);

        foreach (self::EXPECTED_MAJORS as $code) {
            $subject = Subject::where('code', $code)->firstOrFail();

            $this->assertNotNull($subject->department_id, "{$code} must have an owner");
            $this->assertContains(
                (int) $subject->department_id,
                $subject->authorizedDepartmentIds(),
                "{$code} must hold its owner's access row"
            );
        }

        foreach (self::EXPECTED_MINORS as $code) {
            $subject = Subject::where('code', $code)->firstOrFail();

            $this->assertNull($subject->department_id, "{$code} must have no owner");
            $this->assertSame([], $subject->authorizedDepartmentIds(), "{$code} must hold no grant");
        }

        // 17 owner grants + 1 extra (ELEC201 → BIT) and nothing else.
        $this->assertSame(18, DB::table('subject_department_access')->count());
    }

    #[Test]
    public function elec201_is_owned_by_elec_and_explicitly_accessible_to_elec_and_bit(): void
    {
        // 3: the one deliberately shared major in the curriculum.
        foreach (['ELEC', 'ELEC201', 'MATH1'] as $code) {
            $this->makeSubject($code, Subject::CLASSIFICATION_UNCLASSIFIED);
        }

        $this->seed(DepartmentSeeder::class);

        $shared = Subject::where('code', 'ELEC201')->firstOrFail();

        $this->assertSame(Subject::CLASSIFICATION_MAJOR, $shared->classification);
        $this->assertSame($this->electronics->id, (int) $shared->department_id);

        $this->assertEqualsCanonicalizing(
            [$this->electronics->id, $this->it->id],
            $shared->authorizedDepartmentIds()
        );

        // Stated as the table holds it, not only through the relation.
        $this->assertEqualsCanonicalizing(
            [$this->electronics->id, $this->it->id],
            DB::table('subject_department_access')
                ->where('subject_id', $shared->id)
                ->pluck('department_id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        // A BIT section may schedule it, an ELEC section may, and a section with no
        // department may not.
        $this->assertTrue($shared->isSchedulableBySection($this->makeSection('BIT 2A', $this->it->id)));
        $this->assertTrue($shared->isSchedulableBySection($this->makeSection('ELEC 1B', $this->electronics->id)));
        $this->assertFalse($shared->isSchedulableBySection($this->makeSection('NOWHERE', null)));
    }

    // ---------------------------------------------------------------------
    // 4: unknown subjects are rejected by scheduling and generation
    // ---------------------------------------------------------------------

    #[Test]
    public function an_unknown_subject_cannot_be_assigned_to_a_section(): void
    {
        $unknown = $this->makeSubject('MYSTERY1');

        $response = $this->actingAs($this->admin)->postJson('/api/sections', [
            'name' => 'BIT 1A',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
            'student_count' => 30,
            'department_id' => $this->it->id,
            'subject_ids' => [$unknown->id],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('MYSTERY1', $response->json('message'));
        $this->assertStringContainsString('has not been classified yet', $response->json('message'));
        $this->assertSame(0, Section::count());
    }

    #[Test]
    public function an_unknown_subject_blocks_generation_before_the_engine_is_called(): void
    {
        $section = $this->makeSection('BIT 1A', $this->it->id);
        $unknown = $this->makeSubject('MYSTERY1');
        $section->subjects()->attach($unknown->id);

        Http::fake();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/schedules/generate/{$section->id}");

        $response->assertStatus(422);
        $this->assertStringContainsString('MYSTERY1', $response->json('message'));

        // The engine is never asked to generate from an unreviewed subject.
        Http::assertNothingSent();
    }
}
