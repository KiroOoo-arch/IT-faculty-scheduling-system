<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Section;
use App\Models\Subject;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The subject classification deployment preflight.
 *
 * `departments:preflight` is the gate between "the code is deployed" and "the
 * feature is active". It must fail closed whenever the reviewed data cannot
 * support generation — an unclassified major, a major with no owner, a major
 * whose owner holds no access grant, a minor holding one anyway, or a section
 * holding a subject it may not schedule.
 *
 * It must equally not fail when the data is merely incomplete-but-harmless: a
 * subject nobody has classified yet blocks nothing until a section holds it, so it
 * is reported as a warning rather than as a refusal.
 *
 * Assertions run against the captured console output rather than
 * expectsOutputToContain(), because several of the details worth pinning down sit
 * on one line together and that matcher resolves one substring per write.
 *
 * Every test runs against the testing database (phpunit.xml sets DB_DATABASE to
 * the isolated phase 2 test database) inside RefreshDatabase's transaction, so no
 * fixture outlives its test.
 */
class DepartmentPreflightTest extends TestCase
{
    use RefreshDatabase;

    /** Run the command and return [exit code, console output]. */
    private function preflight(): array
    {
        $exitCode = Artisan::call('departments:preflight');

        return [$exitCode, Artisan::output()];
    }

    /** The command must fail closed, and say why, for every one of these reasons. */
    private function assertNotReady(string ...$reasons): string
    {
        [$exitCode, $output] = $this->preflight();

        $this->assertSame(1, $exitCode, "Preflight should have failed closed.\n\n".$output);
        $this->assertStringContainsString('NOT READY', $output);

        foreach ($reasons as $reason) {
            $this->assertStringContainsString($reason, $output);
        }

        return $output;
    }

    /** The command must pass, with the full picture printed. */
    private function assertReady(): string
    {
        [$exitCode, $output] = $this->preflight();

        $this->assertSame(0, $exitCode, "Preflight should have passed.\n\n".$output);
        $this->assertStringNotContainsString('NOT READY', $output);
        $this->assertStringContainsString('READY', $output);

        return $output;
    }

    /**
     * A subject fixture. The classification follows the shape unless it is stated,
     * and a major is granted its owner by default — that grant is mandatory, and
     * `$ownerGrant = false` is how the check that insists on it is exercised.
     */
    private function makeSubject(
        string $code,
        ?int $departmentId = null,
        ?string $classification = null,
        bool $ownerGrant = true,
    ): Subject {
        $subject = Subject::create([
            'code' => $code,
            'title' => $code.' title',
            'year_level' => 1,
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'department_id' => $departmentId,
            'classification' => $classification
                ?? ($departmentId === null
                    ? Subject::CLASSIFICATION_MINOR
                    : Subject::CLASSIFICATION_MAJOR),
        ]);

        if ($departmentId !== null && $ownerGrant) {
            $subject->replaceDepartmentAccess([$departmentId]);
        }

        return $subject;
    }

    private function makeSection(string $name, ?int $departmentId = null): Section
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
            // department_id is deliberately not mass-assignable on Section: a
            // section's department comes from the backfill, never from user
            // input. The seeder sets it with a query-builder update, so the
            // fixture does the same rather than widening $fillable for a test.
            DB::table('sections')->where('id', $section->id)->update(['department_id' => $departmentId]);
            $section->refresh();
        }

        return $section;
    }

    private function departmentId(string $code): int
    {
        return (int) Department::where('code', $code)->value('id');
    }

    /**
     * A realistic activation: subjects and sections exist first, then the real
     * backfill classifies the subjects and claims the sections — the same order the
     * documented activation sequence uses, so this is the state a correct deploy
     * produces.
     */
    private function activate(): void
    {
        $this->makeSubject('PROG1', null, Subject::CLASSIFICATION_UNCLASSIFIED);
        $this->makeSubject('MATH1', null, Subject::CLASSIFICATION_UNCLASSIFIED);

        $this->makeSection('BIT-1A');
        $this->makeSection('ELEC-1B');

        $this->seed(DepartmentSeeder::class);

        Section::where('name', 'BIT-1A')->firstOrFail()
            ->subjects()->attach(Subject::where('code', 'PROG1')->value('id'));

        Section::where('name', 'ELEC-1B')->firstOrFail()
            ->subjects()->attach(Subject::where('code', 'MATH1')->value('id'));
    }

    // ---------------------------------------------------------------------
    // Fail closed
    // ---------------------------------------------------------------------

    #[Test]
    public function it_fails_when_the_department_migrations_have_not_been_applied(): void
    {
        // Postgres applies DDL transactionally, so RefreshDatabase's rollback
        // restores the tables for the next test — a genuinely destructive check
        // without a lasting effect.
        DB::statement('DROP TABLE IF EXISTS subject_department_access CASCADE');
        DB::statement('DROP TABLE IF EXISTS departments CASCADE');

        $this->assertNotReady(
            'departments table',
            'subject_department_access table',
            'classification migrations have not been applied',
            'php artisan migrate',
        );
    }

    #[Test]
    public function it_fails_when_the_classification_column_has_not_been_applied(): void
    {
        DB::statement('ALTER TABLE subjects DROP COLUMN classification');

        $this->assertNotReady('subjects.classification', 'php artisan migrate');
    }

    #[Test]
    public function it_fails_when_no_departments_are_recorded(): void
    {
        $this->makeSubject('PROG1');

        $this->assertNotReady(
            'Required department record missing: BIT',
            'Required department record missing: ELEC',
            'db:seed --class=DepartmentSeeder',
        );
    }

    #[Test]
    public function it_fails_when_one_listed_department_is_absent(): void
    {
        Department::create(['code' => 'BIT', 'name' => 'Industrial Technology']);

        $this->assertNotReady('BIT is recorded', 'Required department record missing: ELEC');
    }

    #[Test]
    public function it_fails_when_a_listed_major_is_still_unclassified(): void
    {
        // Both departments present, but the backfill never reviewed the subject, so
        // it is schedulable nowhere.
        $this->seed(DepartmentSeeder::class);
        $this->makeSubject('PROG1', null, Subject::CLASSIFICATION_UNCLASSIFIED);

        $this->assertNotReady(
            "PROG1 is listed as a BIT major but is classified as 'unclassified'",
            'schedulable nowhere',
        );
    }

    #[Test]
    public function it_fails_when_a_listed_major_belongs_to_the_wrong_department(): void
    {
        $this->seed(DepartmentSeeder::class);
        $this->makeSubject('PROG1', $this->departmentId('ELEC'));

        $this->assertNotReady('PROG1 is listed as a BIT major', 'major of that department');
    }

    #[Test]
    public function it_fails_when_a_listed_minor_is_stored_as_a_major(): void
    {
        /**
         * The direction the major check cannot see, and the reason both directions
         * are judged: a listed minor stored as a major silently stops being open to
         * every department and becomes schedulable only where the access table
         * allows, so what is deployed is no longer the reviewed shape.
         */
        $this->seed(DepartmentSeeder::class);

        $this->makeSubject('MATH1', $this->departmentId('BIT'), Subject::CLASSIFICATION_MAJOR);

        $this->assertNotReady(
            'MATH1 is listed as a minor / shared subject',
            "but is classified as 'major'",
            'schedulable only by the departments authorized for it',
        );
    }

    #[Test]
    public function it_fails_when_a_listed_majors_owner_holds_no_access_grant(): void
    {
        /**
         * The owner's access row is mandatory: enforcement reads the access table
         * alone, so a major whose owner row is missing is a major nobody can
         * schedule — including the department that owns it.
         */
        $this->seed(DepartmentSeeder::class);

        $this->makeSubject('PROG1', $this->departmentId('BIT'), null, ownerGrant: false);

        $this->assertNotReady(
            'PROG1 is listed as a major authorized for BIT but has no subject_department_access row for it',
            'so that department cannot schedule it',
        );
    }

    #[Test]
    public function it_fails_when_an_unlisted_majors_owner_holds_no_access_grant(): void
    {
        /**
         * The same mandatory owner row, for a major the reviewed mapping does not
         * list — a subject someone reviewed through the subject editor or the bulk
         * review instead of the backfill. Enforcement reads the access table alone,
         * so without the owner's row this major is schedulable nowhere; and because
         * the mapping cannot describe it, it used to be skipped entirely.
         */
        $this->seed(DepartmentSeeder::class);

        // LEGACY1 is on none of the seeder's lists: it stands in for a subject
        // reviewed directly rather than backfilled.
        $this->makeSubject('LEGACY1', $this->departmentId('BIT'), Subject::CLASSIFICATION_MAJOR, ownerGrant: false);

        $this->assertNotReady(
            'LEGACY1 is a major outside the reviewed mapping',
            'its owning department BIT holds no subject_department_access row for it',
            'so that department cannot schedule it',
        );
    }

    #[Test]
    public function it_fails_when_a_minor_subject_holds_an_access_grant(): void
    {
        $this->seed(DepartmentSeeder::class);

        $minor = $this->makeSubject('MATH1');
        $minor->replaceDepartmentAccess([$this->departmentId('BIT')]);

        $this->assertNotReady(
            "MATH1 is classified as 'minor'",
            'but holds 1 access grant(s)',
            'a minor subject must hold none',
        );
    }

    #[Test]
    public function it_fails_when_a_section_holds_a_major_of_another_department(): void
    {
        $this->seed(DepartmentSeeder::class);

        $major = $this->makeSubject('PROG1', $this->departmentId('BIT'));
        $section = $this->makeSection('ELEC-1B', $this->departmentId('ELEC'));
        $section->subjects()->attach($major->id);

        $this->assertNotReady(
            '1 of 1 assignment(s) pair a section with a subject it may not schedule',
            'generation for those sections is refused',
            'PROG1 is a major subject of Industrial Technology',
        );
    }

    #[Test]
    public function it_fails_when_a_section_with_no_department_holds_a_major(): void
    {
        $this->seed(DepartmentSeeder::class);

        $major = $this->makeSubject('PROG1', $this->departmentId('BIT'));
        $section = $this->makeSection('UNASSIGNED', null);
        $section->subjects()->attach($major->id);

        $this->assertNotReady('PROG1 is a major subject', 'section UNASSIGNED, which has no department');
    }

    #[Test]
    public function it_fails_when_a_section_holds_an_unclassified_subject(): void
    {
        $this->seed(DepartmentSeeder::class);

        $unknown = $this->makeSubject('MYSTERY1', null, Subject::CLASSIFICATION_UNCLASSIFIED);
        $section = $this->makeSection('BIT-1A', $this->departmentId('BIT'));
        $section->subjects()->attach($unknown->id);

        $this->assertNotReady(
            'MYSTERY1 has not been classified yet, so it cannot be scheduled',
        );
    }

    #[Test]
    public function it_fails_when_nothing_has_been_classified(): void
    {
        $this->seed(DepartmentSeeder::class);

        $this->assertNotReady(
            '0 major, 0 minor / shared and 0 unclassified subject(s)',
            'No subject is classified yet',
        );
    }

    // ---------------------------------------------------------------------
    // Pass only when the data is actually complete
    // ---------------------------------------------------------------------

    #[Test]
    public function it_passes_when_the_classification_is_complete_and_consistent(): void
    {
        $this->activate();

        $output = $this->assertReady();

        // It reports every area it judged, not just a verdict.
        foreach ([
            'Schema',
            'Departments',
            'Major subject ownership',
            'Department access grants',
            'Section assignments',
            'Classification coverage',
        ] as $section) {
            $this->assertStringContainsString($section, $output);
        }

        $this->assertStringContainsString('the department and classification migrations are applied', $output);
        $this->assertStringContainsString('1 major subject(s) classified and owned by the expected department', $output);
        $this->assertStringContainsString('2 assignment(s) are consistent with the reviewed classification', $output);
        $this->assertStringContainsString('no minor subject holds a major-specific access grant', $output);
        $this->assertStringContainsString('1 major, 1 minor / shared and 0 unclassified subject(s)', $output);
    }

    #[Test]
    public function it_passes_when_a_section_holds_a_major_granted_to_its_department(): void
    {
        /**
         * The live shape this must not break: the BIT sections that already take
         * ELEC201, which Electronics owns and BIT is granted. The grant is what
         * makes that assignment legal, and the preflight must accept it.
         */
        $this->seed(DepartmentSeeder::class);

        $shared = $this->makeSubject('ELEC201', $this->departmentId('ELEC'));
        $shared->replaceDepartmentAccess([
            $this->departmentId('ELEC'),
            $this->departmentId('BIT'),
        ]);

        $section = $this->makeSection('BIT-1A', $this->departmentId('BIT'));
        $section->subjects()->attach($shared->id);

        $output = $this->assertReady();

        $this->assertStringContainsString('1 assignment(s) are consistent with the reviewed classification', $output);
    }

    #[Test]
    public function it_does_not_require_minor_subjects_to_have_a_department(): void
    {
        $this->activate();

        $subject = Subject::where('code', 'MATH1')->firstOrFail();

        $this->assertNull($subject->department_id);
        $this->assertSame(Subject::CLASSIFICATION_MINOR, $subject->classification);

        $output = $this->assertReady();

        $this->assertStringContainsString('1 major, 1 minor / shared and 0 unclassified subject(s)', $output);
    }

    #[Test]
    public function it_passes_when_a_listed_minor_is_stored_as_a_minor(): void
    {
        $this->seed(DepartmentSeeder::class);

        $this->makeSubject('MATH1');

        $output = $this->assertReady();

        $this->assertStringContainsString('0 major, 1 minor / shared and 0 unclassified subject(s)', $output);
        $this->assertSame(Subject::CLASSIFICATION_MINOR, Subject::where('code', 'MATH1')->value('classification'));
        $this->assertNull(Subject::where('code', 'MATH1')->value('department_id'));
    }

    #[Test]
    public function it_passes_when_an_unlisted_majors_owner_holds_its_access_grant(): void
    {
        /**
         * The other half of the rule: a major outside the reviewed mapping that does
         * hold its owner's row is a legitimate, schedulable major, because a human
         * reviewed it. It must not be refused — being unlisted is not by itself a
         * fault, which is why the check falls back to its own owning department
         * rather than demanding a mapping entry.
         */
        $this->seed(DepartmentSeeder::class);

        $subject = $this->makeSubject('LEGACY1', $this->departmentId('BIT'), Subject::CLASSIFICATION_MAJOR);

        $output = $this->assertReady();

        $this->assertStringContainsString('1 major, 0 minor / shared and 0 unclassified subject(s)', $output);
        $this->assertSame([$this->departmentId('BIT')], $subject->authorizedDepartmentIds());
    }

    #[Test]
    public function it_reports_remaining_unclassified_subjects_as_a_warning_not_a_failure(): void
    {
        /**
         * An unclassified subject is not a failure by itself: nothing is assigned
         * to it, so nothing breaks. It is reported because it cannot be scheduled
         * or generated until someone reviews it, and naming it is what makes the
         * report actionable.
         */
        $this->activate();
        $this->makeSubject('UNREVIEWED1', null, Subject::CLASSIFICATION_UNCLASSIFIED);

        $output = $this->assertReady();

        $this->assertStringContainsString('WARN', $output);
        $this->assertStringContainsString('1 subject(s) are unclassified', $output);
        $this->assertStringContainsString('cannot be scheduled or generated until they are reviewed', $output);
        $this->assertStringContainsString('UNREVIEWED1', $output);
        $this->assertStringContainsString('with 1 warning(s)', $output);
    }

    #[Test]
    public function it_accepts_a_deliberate_extra_grant_with_a_warning(): void
    {
        /**
         * Widening access on purpose is a legitimate administrative act, so the
         * preflight reports the difference from the reviewed mapping instead of
         * overruling it.
         */
        $this->seed(DepartmentSeeder::class);

        $major = $this->makeSubject('PROG1', $this->departmentId('BIT'));
        $major->replaceDepartmentAccess([$this->departmentId('BIT'), $this->departmentId('ELEC')]);

        $output = $this->assertReady();

        $this->assertStringContainsString('WARN', $output);
        $this->assertStringContainsString('access grant(s) beyond the approved mapping', $output);
        $this->assertStringContainsString('ELEC', $output);
    }

    // ---------------------------------------------------------------------
    // Safe to run
    // ---------------------------------------------------------------------

    #[Test]
    public function it_writes_nothing(): void
    {
        $this->activate();

        $before = [
            'departments' => Department::count(),
            'subjects' => Subject::count(),
            'sections' => Section::count(),
            'assignments' => DB::table('section_subjects')->count(),
            'ownership' => Subject::orderBy('id')->pluck('department_id')->all(),
            'classification' => Subject::orderBy('id')->pluck('classification')->all(),
            'access' => DB::table('subject_department_access')
                ->orderBy('subject_id')->orderBy('department_id')
                ->pluck('department_id', 'subject_id')->all(),
            'section_departments' => Section::orderBy('id')->pluck('department_id')->all(),
            'section_names' => Section::orderBy('id')->pluck('name')->all(),
            'department_names' => Department::orderBy('id')->pluck('name')->all(),
        ];

        $this->assertReady();
        $this->assertReady();

        $this->assertSame($before['departments'], Department::count());
        $this->assertSame($before['subjects'], Subject::count());
        $this->assertSame($before['sections'], Section::count());
        $this->assertSame($before['assignments'], DB::table('section_subjects')->count());
        $this->assertSame($before['ownership'], Subject::orderBy('id')->pluck('department_id')->all());
        $this->assertSame($before['classification'], Subject::orderBy('id')->pluck('classification')->all());
        $this->assertSame(
            $before['access'],
            DB::table('subject_department_access')
                ->orderBy('subject_id')->orderBy('department_id')
                ->pluck('department_id', 'subject_id')->all()
        );
        $this->assertSame($before['section_departments'], Section::orderBy('id')->pluck('department_id')->all());
        $this->assertSame($before['section_names'], Section::orderBy('id')->pluck('name')->all());
        $this->assertSame($before['department_names'], Department::orderBy('id')->pluck('name')->all());
    }

    #[Test]
    public function it_gives_the_same_verdict_when_run_again(): void
    {
        $this->activate();

        $this->assertReady();

        // A newly added, unreviewed listed major flips the verdict on the next run —
        // so the command reflects the database, not a cached first answer.
        $this->makeSubject('PROG3', null, Subject::CLASSIFICATION_UNCLASSIFIED);

        $this->assertNotReady("PROG3 is listed as a BIT major but is classified as 'unclassified'");
    }

    #[Test]
    public function it_resolves_the_seeder_list_as_its_source_of_truth(): void
    {
        /**
         * A listed code with no subject row is a note, not a problem: the seeder
         * describes curriculum intent, and a code that does not exist in this
         * database yet has no classification to get wrong.
         */
        $this->makeSubject('MATH1', null, Subject::CLASSIFICATION_UNCLASSIFIED);

        $this->seed(DepartmentSeeder::class);

        $output = $this->assertReady();

        $this->assertStringContainsString('listed but not present in this database', $output);
        $this->assertStringContainsString('0 major, 1 minor / shared and 0 unclassified subject(s)', $output);
    }

    #[Test]
    public function it_reports_a_partially_seeded_database_as_not_ready(): void
    {
        /**
         * The likeliest real mistake: migrations applied, seeder never run. Every
         * subject reads as unclassified, so nothing can be scheduled and nothing
         * errors in the UI — which is exactly why this state must be caught here
         * instead of in production. This is the state the shared database is in
         * today.
         */
        $this->makeSubject('PROG1');
        $this->makeSubject('ELEC101');
        $this->makeSection('BIT-1A');
        $this->makeSection('ELEC-1B');

        [$exitCode, $output] = $this->preflight();

        $this->assertSame(1, $exitCode, "An unseeded deployment must not pass.\n\n".$output);
        $this->assertStringContainsString('Required department record missing: BIT', $output);
        $this->assertStringContainsString('Required department record missing: ELEC', $output);
        // Both fixtures are minors by construction, so nothing is unclassified here
        // — the failure is the missing departments.
        $this->assertStringContainsString('2 minor / shared and 0 unclassified subject(s)', $output);
    }
}
