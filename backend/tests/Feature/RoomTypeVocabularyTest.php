<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A room's `type` must come from the canonical vocabulary shared by the Rooms
 * page, the Subjects page, and the solver.
 *
 * The solver matches a session to a room with an exact string comparison, so a
 * free-text type such as "Computer Lab" would silently orphan a laboratory
 * and the generator would report "No room of type 'computer_lab' exists" even
 * though a suitable room plainly exists. Both entry points (create and edit)
 * are pinned so the mismatch cannot be reintroduced through either.
 */
class RoomTypeVocabularyTest extends TestCase
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
            'name' => 'R301',
            'type' => 'lecture',
            'capacity' => 40,
        ], $overrides);
    }

    #[Test]
    public function every_canonical_room_type_is_accepted_on_create(): void
    {
        foreach (['lecture', 'computer_lab', 'science_lab', 'electronics_lab'] as $i => $type) {
            $this->actingAs($this->admin)
                ->postJson('/api/rooms', $this->payload(['name' => "R30{$i}", 'type' => $type]))
                ->assertCreated()
                ->assertJsonPath('type', $type);
        }
    }

    #[Test]
    public function a_type_outside_the_vocabulary_is_rejected_with_422(): void
    {
        foreach (['Computer Lab', 'lab', 'banana', 'COMPUTER_LAB'] as $badType) {
            $this->actingAs($this->admin)
                ->postJson('/api/rooms', $this->payload(['type' => $badType]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('type');
        }

        // Nothing was written, so the bad value cannot reach the solver as a
        // create.
        $this->assertSame(0, Room::whereNotIn('type', ['lecture', 'computer_lab', 'science_lab', 'electronics_lab'])->count());
    }

    #[Test]
    public function a_type_outside_the_vocabulary_is_rejected_on_update(): void
    {
        $room = Room::create($this->payload());

        $this->actingAs($this->admin)
            ->putJson("/api/rooms/{$room->id}", ['type' => 'Computer Lab'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        // The stored type is untouched by the rejected edit.
        $this->assertSame('lecture', $room->fresh()->type);
    }

    #[Test]
    public function a_canonical_type_can_still_be_changed(): void
    {
        $room = Room::create($this->payload());

        $this->actingAs($this->admin)
            ->putJson("/api/rooms/{$room->id}", ['type' => 'computer_lab'])
            ->assertOk()
            ->assertJsonPath('type', 'computer_lab');

        $this->assertSame('computer_lab', $room->fresh()->type);
    }
}