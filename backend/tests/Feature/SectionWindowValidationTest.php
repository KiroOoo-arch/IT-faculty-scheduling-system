<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A section's preferred days and hours are not cosmetic: they become the
 * solver's day domain and day window for every session it places, so the API
 * must reject states that make the section unschedulable or unrenderable.
 *
 *  - `preferred_days` must be a non-empty list of distinct values in 1–7
 *  - the window must stay ordered after a PARTIAL update, where only one end is
 *    sent and the other comes from the stored row
 */
class SectionWindowValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
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

        $this->section = Section::create([
            'name' => 'BSIT 1A',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3, 4, 5],
            'preferred_start_time' => '07:00:00',
            'preferred_end_time' => '17:00:00',
            'student_count' => 30,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'BSIT 1B',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '17:00',
        ], $overrides);
    }

    #[Test]
    public function a_section_cannot_be_created_without_any_preferred_day(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->payload(['preferred_days' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferred_days');

        $this->assertDatabaseMissing('sections', ['name' => 'BSIT 1B']);
    }

    #[Test]
    public function days_outside_the_week_are_rejected_on_create(): void
    {
        // The error must point at the offending entry, not at the list.
        $cases = [
            [[0], 'preferred_days.0'],
            [[8], 'preferred_days.0'],
            [[-1], 'preferred_days.0'],
            [[1, 0], 'preferred_days.1'],
        ];

        foreach ($cases as [$days, $key]) {
            $this->actingAs($this->admin)
                ->postJson('/api/sections', $this->payload(['preferred_days' => $days]))
                ->assertStatus(422)
                ->assertJsonValidationErrors($key);
        }

        $this->assertDatabaseMissing('sections', ['name' => 'BSIT 1B']);
    }

    #[Test]
    public function duplicated_days_are_rejected_on_create(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->payload(['preferred_days' => [1, 1, 2]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferred_days.1');
    }

    #[Test]
    public function sunday_is_a_valid_day(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->payload(['preferred_days' => [7]]))
            ->assertStatus(201);

        $this->assertDatabaseHas('sections', ['name' => 'BSIT 1B']);
    }

    #[Test]
    public function the_window_must_be_ordered_on_create(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->payload([
                'preferred_start_time' => '15:00',
                'preferred_end_time' => '09:00',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferred_end_time');
    }

    #[Test]
    public function a_partial_update_cannot_invert_the_window_by_moving_only_the_end(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson("/api/sections/{$this->section->id}", ['preferred_end_time' => '06:00']);

        $response->assertStatus(422);
        $this->assertStringContainsString('must be later', $response->json('message'));

        $this->section->refresh();
        $this->assertSame('17:00:00', $this->section->preferred_end_time);
    }

    #[Test]
    public function a_partial_update_cannot_invert_the_window_by_moving_only_the_start(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/sections/{$this->section->id}", ['preferred_start_time' => '18:00'])
            ->assertStatus(422);

        $this->section->refresh();
        $this->assertSame('07:00:00', $this->section->preferred_start_time);
    }

    #[Test]
    public function a_zero_length_window_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/sections/{$this->section->id}", ['preferred_end_time' => '07:00'])
            ->assertStatus(422);

        $this->section->refresh();
        $this->assertSame('17:00:00', $this->section->preferred_end_time);
    }

    #[Test]
    public function a_partial_update_of_days_cannot_empty_the_list(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/sections/{$this->section->id}", ['preferred_days' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferred_days');

        $this->section->refresh();
        $this->assertSame([1, 2, 3, 4, 5], $this->section->preferred_days);
    }

    #[Test]
    public function a_valid_partial_update_is_still_accepted(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/sections/{$this->section->id}", [
                'preferred_days' => [1, 3, 5],
                'preferred_start_time' => '08:00',
            ])
            ->assertOk();

        $this->section->refresh();
        $this->assertSame([1, 3, 5], $this->section->preferred_days);
        $this->assertSame('08:00:00', $this->section->preferred_start_time);
        // The untouched end of the window survives.
        $this->assertSame('17:00:00', $this->section->preferred_end_time);
    }
}
