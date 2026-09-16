<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies the lab_hours / lab_room_type consistency rule on the Subject API:
 * - lab_hours > 0 requires a canonical lab_room_type (computer_lab,
 *   science_lab, electronics_lab);
 * - lab_hours = 0 requires lab_room_type to be null (lecture-only subject);
 * - violations are rejected with HTTP 422 on both create and update, so
 *   invalid subject data can never reach schedule generation.
 */
class SubjectLabConsistencyTest extends TestCase
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'PROG1',
            'title' => 'Programming 1',
            'year_level' => 1,
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'lab_room_type' => null,
            'is_active' => true,
        ], $overrides);
    }

    #[Test]
    public function valid_lecture_subject_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/subjects', $this->validPayload([
                'code' => 'MATH1', 'title' => 'College Algebra',
                'lecture_hours' => 3, 'lab_hours' => 0, 'lab_room_type' => null,
            ]))
            ->assertCreated()
            ->assertJsonPath('lab_room_type', null);
    }

    #[Test]
    public function valid_laboratory_subject_with_canonical_type_is_accepted(): void
    {
        foreach (['computer_lab', 'science_lab', 'electronics_lab'] as $i => $type) {
            $this->actingAs($this->admin)
                ->postJson('/api/subjects', $this->validPayload([
                    'code' => "LAB{$i}",
                    'title' => "Lab Subject {$i}",
                    'lecture_hours' => 2,
                    'lab_hours' => 3,
                    'lab_room_type' => $type,
                ]))
                ->assertCreated()
                ->assertJsonPath('lab_room_type', $type);
        }
    }

    #[Test]
    public function lab_hours_without_lab_room_type_is_rejected_with_422(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/subjects', $this->validPayload([
                'code' => 'BAD1',
                'lecture_hours' => 2,
                'lab_hours' => 3,
                'lab_room_type' => null,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('lab_room_type');

        $this->assertStringContainsString(
            'Lab room type is required',
            $response->json('errors.lab_room_type')[0] ?? $response->json('message')
        );

        // Nothing was persisted
        $this->assertSame(0, Subject::where('code', 'BAD1')->count());
    }

    #[Test]
    public function invalid_lab_room_type_is_rejected_with_422(): void
    {
        foreach (['pool', 'gym', 'lecture', 'Computer Lab'] as $badType) {
            $this->actingAs($this->admin)
                ->postJson('/api/subjects', $this->validPayload([
                    'code' => 'BAD2',
                    'lecture_hours' => 2,
                    'lab_hours' => 3,
                    'lab_room_type' => $badType,
                ]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('lab_room_type');
        }

        $this->assertSame(0, Subject::where('code', 'BAD2')->count());
    }

    #[Test]
    public function zero_lab_hours_with_lab_room_type_is_rejected_with_422(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/subjects', $this->validPayload([
                'code' => 'BAD3',
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'lab_room_type' => 'computer_lab',
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('lab_hours');

        $this->assertStringContainsString(
            'must be empty when lab hours are 0',
            $response->json('errors.lab_hours')[0] ?? $response->json('message')
        );

        $this->assertSame(0, Subject::where('code', 'BAD3')->count());
    }

    #[Test]
    public function update_enforces_the_same_rule(): void
    {
        // Start from a valid lab subject
        $subject = Subject::create($this->validPayload([
            'code' => 'PROG2',
            'title' => 'Programming 2',
            'lecture_hours' => 2,
            'lab_hours' => 3,
            'lab_room_type' => 'computer_lab',
        ]));

        // Removing the lab room type while keeping lab hours must fail
        $this->actingAs($this->admin)
            ->putJson("/api/subjects/{$subject->id}", $this->validPayload([
                'code' => 'PROG2',
                'title' => 'Programming 2',
                'lecture_hours' => 2,
                'lab_hours' => 3,
                'lab_room_type' => null,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lab_room_type');

        $subject->refresh();
        $this->assertSame('computer_lab', $subject->lab_room_type, 'Invalid update was persisted!');
    }

    #[Test]
    public function update_to_a_valid_consistent_state_is_accepted(): void
    {
        // Lecture-only subject gains a lab component the correct way
        $subject = Subject::create($this->validPayload([
            'code' => 'NET1',
            'title' => 'Networking 1',
        ]));

        $this->actingAs($this->admin)
            ->putJson("/api/subjects/{$subject->id}", $this->validPayload([
                'code' => 'NET1',
                'title' => 'Networking 1',
                'lecture_hours' => 2,
                'lab_hours' => 3,
                'lab_room_type' => 'electronics_lab',
            ]))
            ->assertOk()
            ->assertJsonPath('lab_room_type', 'electronics_lab');

        $subject->refresh();
        $this->assertSame(3, $subject->lab_hours);
        $this->assertSame('electronics_lab', $subject->lab_room_type);
    }

    #[Test]
    public function update_can_clear_lab_component_by_zeroing_lab_hours(): void
    {
        $subject = Subject::create($this->validPayload([
            'code' => 'PROG3',
            'title' => 'Programming 3',
            'lecture_hours' => 2,
            'lab_hours' => 3,
            'lab_room_type' => 'computer_lab',
        ]));

        $this->actingAs($this->admin)
            ->putJson("/api/subjects/{$subject->id}", $this->validPayload([
                'code' => 'PROG3',
                'title' => 'Programming 3',
                'lecture_hours' => 3,
                'lab_hours' => 0,
                'lab_room_type' => null,
            ]))
            ->assertOk()
            ->assertJsonPath('lab_room_type', null);

        $subject->refresh();
        $this->assertSame(0, $subject->lab_hours);
        $this->assertNull($subject->lab_room_type);
    }
}
