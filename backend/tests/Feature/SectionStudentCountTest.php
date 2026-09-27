<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The room-capacity rule lives in the solver, which reads the class size
 * straight from sections.student_count. That rule is only meaningful when the
 * size the admin configures actually reaches the column, so these tests pin
 * the API/UI wiring around it: create, update, validation, read-back, and
 * that generation itself never rewrites the stored value.
 */
class SectionStudentCountTest extends TestCase
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'BSIT 1A',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
        ], $overrides);
    }

    private function createSection(array $overrides = []): Section
    {
        return Section::create($this->payload($overrides));
    }

    #[Test]
    public function create_persists_the_supplied_class_size(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->payload(['student_count' => 42]))
            ->assertCreated()
            ->assertJsonPath('student_count', 42);

        $this->assertSame(42, Section::sole()->student_count);
    }

    #[Test]
    public function create_without_a_class_size_keeps_the_column_default(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->payload())
            ->assertCreated();

        // The migration's own default stays authoritative — no magic 30 in PHP.
        $this->assertSame(30, Section::sole()->student_count);
    }

    #[Test]
    public function update_persists_a_new_class_size(): void
    {
        $section = $this->createSection();

        $this->actingAs($this->admin)
            ->putJson("/api/sections/{$section->id}", ['student_count' => 7])
            ->assertOk()
            ->assertJsonPath('student_count', 7);

        $this->assertSame(7, $section->fresh()->student_count);
    }

    #[Test]
    public function update_without_a_class_size_leaves_it_untouched(): void
    {
        $section = $this->createSection(['student_count' => 42]);

        $this->actingAs($this->admin)
            ->putJson("/api/sections/{$section->id}", ['name' => 'BSIT 1B'])
            ->assertOk();

        $section->refresh();
        $this->assertSame('BSIT 1B', $section->name);
        $this->assertSame(42, $section->student_count);
    }

    #[Test]
    #[DataProvider('invalidClassSizes')]
    public function invalid_class_size_is_rejected(string|int|float $invalid): void
    {
        $section = $this->createSection(['student_count' => 42]);

        $this->actingAs($this->admin)
            ->putJson("/api/sections/{$section->id}", ['student_count' => $invalid])
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_count');

        $this->assertSame(42, $section->fresh()->student_count);

        $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->payload(['name' => 'BSIT 9Z', 'student_count' => $invalid]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_count');

        $this->assertSame(1, Section::count());
    }

    public static function invalidClassSizes(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'not a number' => ['abc'],
            'fractional' => [2.5],
            'out of range' => [1001],
        ];
    }

    #[Test]
    public function sections_endpoints_expose_the_class_size(): void
    {
        $section = $this->createSection(['student_count' => 42]);

        $this->actingAs($this->admin)
            ->getJson('/api/sections')
            ->assertOk()
            ->assertJsonPath('0.student_count', 42);

        $this->actingAs($this->admin)
            ->getJson("/api/sections/{$section->id}")
            ->assertOk()
            ->assertJsonPath('student_count', 42);
    }

    #[Test]
    public function subject_mismatch_is_still_rejected_when_the_class_size_changes(): void
    {
        $mismatched = Subject::create([
            'code' => 'PROG2', 'title' => 'Programming 2',
            'year_level' => 2, 'semester_name' => '2nd Semester',
            'lecture_hours' => 2, 'lab_hours' => 0,
        ]);
        $section = $this->createSection(['student_count' => 42]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/sections/{$section->id}", [
                'student_count' => 99,
                'subject_ids' => [$mismatched->id],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('PROG2', $response->json('message'));

        // The rejected request changed nothing.
        $this->assertSame(42, $section->fresh()->student_count);
        $this->assertSame(0, $section->subjects()->count());
    }

    #[Test]
    public function generation_does_not_rewrite_the_stored_class_size(): void
    {
        Http::fake([
            '127.0.0.1:8001/generate-schedule/*' => Http::response([
                'status' => 'OPTIMAL',
                'message' => 'All sessions scheduled successfully.',
                'sessions' => [],
            ]),
        ]);

        $section = $this->createSection(['student_count' => 42]);

        $this->actingAs($this->admin)
            ->postJson("/api/schedules/generate/{$section->id}")
            ->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), "/generate-schedule/{$section->id}"));
        $this->assertSame(42, $section->fresh()->student_count);
    }
}
