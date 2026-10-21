<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What an administrator reads in place of a department code.
 *
 * `departments.code` is the stable handle everything keys on — subject
 * ownership, access grants, section name prefixes, `?department_id=` — while
 * `departments.name` is only ever printed. That separation is what makes
 * renaming a department a one-line edit to the seeder rather than a data
 * migration, and it is what these tests pin down: the names an administrator
 * sees, and the fact that a later authorized seed renames the existing row
 * instead of adding a second department for the same code.
 */
class DepartmentNamingTest extends TestCase
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
     * The approved curriculum as it really is, so the re-seed has something
     * meaningful to leave alone: a BIT major, the cross-department ELEC201, a
     * minor and an unreviewed code.
     */
    private function approvedCurriculum(Department $bit, Department $electronics): void
    {
        $prog1 = Subject::create([
            'code' => 'PROG1', 'title' => 'Object Oriented Programming', 'year_level' => 2,
            'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 3,
            'lab_room_type' => 'computer_lab', 'classification' => Subject::CLASSIFICATION_MAJOR,
            'department_id' => $bit->id,
        ]);
        $prog1->replaceDepartmentAccess([$bit->id]);

        $shared = Subject::create([
            'code' => 'ELEC201', 'title' => 'Electronic Devices and Circuits', 'year_level' => 2,
            'semester_name' => '2nd Semester', 'lecture_hours' => 2, 'lab_hours' => 3,
            'lab_room_type' => 'electronics_lab', 'classification' => Subject::CLASSIFICATION_MAJOR,
            'department_id' => $electronics->id,
        ]);
        $shared->replaceDepartmentAccess([$electronics->id, $bit->id]);

        Subject::create([
            'code' => 'MATH1', 'title' => 'College Algebra', 'year_level' => 1,
            'semester_name' => '1st Semester', 'lecture_hours' => 3, 'lab_hours' => 0,
            'classification' => Subject::CLASSIFICATION_MINOR,
        ]);

        // On no list at all: the seeder must leave it unreviewed.
        Subject::create([
            'code' => 'MYSTERY9', 'title' => 'Nobody reviewed this', 'year_level' => 1,
            'semester_name' => '1st Semester', 'lecture_hours' => 2, 'lab_hours' => 0,
            'classification' => Subject::CLASSIFICATION_UNCLASSIFIED,
        ]);
    }

    /** @return array<string, mixed> */
    private function sectionData(Department $department): array
    {
        return [
            'name' => 'BIT-1A',
            'year_level' => 2,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
            'student_count' => 30,
            'department_id' => $department->id,
        ];
    }

    /** Everything the rename must not disturb, as comparable snapshots. */
    private function schedulingSnapshot(): array
    {
        return [
            'subjects' => Subject::orderBy('code')
                ->get(['code', 'classification', 'department_id', 'updated_at'])
                ->toArray(),
            'access' => DB::table('subject_department_access')
                ->orderBy('subject_id')->orderBy('department_id')->get()->toArray(),
            'sections' => Section::orderBy('id')->get(['id', 'name', 'department_id'])->toArray(),
            'section_subjects' => DB::table('section_subjects')
                ->orderBy('section_id')->orderBy('subject_id')->get()->toArray(),
        ];
    }

    #[Test]
    public function the_seeder_writes_the_administrator_facing_display_names(): void
    {
        $this->seed(DepartmentSeeder::class);

        $this->assertSame('Industrial Technology', Department::where('code', 'BIT')->value('name'));
        $this->assertSame('BindTech Electronics', Department::where('code', 'ELEC')->value('name'));

        // The codes are untouched: they are the handles the rest of the system
        // keys on, and nothing else in the schema changed with the name.
        $this->assertEqualsCanonicalizing(['BIT', 'ELEC'], Department::pluck('code')->all());
    }

    #[Test]
    public function re_seeding_renames_the_existing_departments_instead_of_adding_new_ones(): void
    {
        // A database seeded before the rename, already carrying ownership,
        // grants, a section and its subject assignments.
        $bit = Department::create(['code' => 'BIT', 'name' => 'Department of Information Technology']);
        $electronics = Department::create(['code' => 'ELEC', 'name' => 'Department of Electronics Engineering']);
        $this->approvedCurriculum($bit, $electronics);

        $prog1 = Subject::where('code', 'PROG1')->firstOrFail();
        $section = Section::create($this->sectionData($bit));
        $section->subjects()->attach($prog1->id);

        $before = $this->schedulingSnapshot();

        $this->seed(DepartmentSeeder::class);

        // The display names are updated on the same rows, in place.
        $this->assertSame(2, Department::count());
        $this->assertSame([$bit->id], Department::where('code', 'BIT')->pluck('id')->all());
        $this->assertSame('Industrial Technology', $bit->fresh()->name);
        $this->assertSame('BindTech Electronics', $electronics->fresh()->name);

        // And nothing that scheduling reads moved with them.
        $this->assertEquals($before, $this->schedulingSnapshot());
    }

    #[Test]
    public function a_department_the_mapping_does_not_name_is_left_alone(): void
    {
        $general = Department::create(['code' => 'GE', 'name' => 'General Education']);

        $this->seed(DepartmentSeeder::class);

        $this->assertSame('General Education', $general->fresh()->name);
        $this->assertSame(3, Department::count());
    }

    #[Test]
    public function re_seeding_an_unchanged_database_does_not_touch_department_timestamps(): void
    {
        $this->seed(DepartmentSeeder::class);

        $stamps = Department::orderBy('code')->pluck('updated_at', 'code')->all();

        $this->seed(DepartmentSeeder::class);

        $this->assertEquals($stamps, Department::orderBy('code')->pluck('updated_at', 'code')->all());
    }

    #[Test]
    public function the_department_list_returns_the_stable_code_with_the_display_name(): void
    {
        $this->seed(DepartmentSeeder::class);

        $bit = Department::where('code', 'BIT')->firstOrFail();
        $electronics = Department::where('code', 'ELEC')->firstOrFail();

        $this->actingAs($this->admin)
            ->getJson('/api/departments')
            ->assertOk()
            ->assertExactJson([
                ['id' => $bit->id, 'code' => 'BIT', 'name' => 'Industrial Technology'],
                ['id' => $electronics->id, 'code' => 'ELEC', 'name' => 'BindTech Electronics'],
            ]);
    }
}
