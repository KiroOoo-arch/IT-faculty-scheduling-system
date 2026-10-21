<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\FacultyAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A faculty member's declared windows are a hard boundary for the solver: the
 * engine drops any window that ends before it starts and then treats that
 * faculty as unable to teach at all, so a bad window shows up as a mysteriously
 * PARTIAL or INFEASIBLE section rather than as a data error.
 *
 * These tests pin both halves of the fix:
 *  - invalid windows are refused with 422 and a reason naming the row
 *  - a refused save leaves the previously stored windows untouched
 */
class FacultyAvailabilityWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Faculty $faculty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Department Head',
            'email' => 'admin@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->faculty = Faculty::create([
            'name' => 'Prof. Window',
            'employee_no' => 'EMP-500',
            'faculty_type' => 'full_time',
            'max_teaching_load' => 24,
            'is_active' => true,
        ]);

        foreach ([1, 2] as $day) {
            FacultyAvailability::create([
                'faculty_id' => $this->faculty->id,
                'day_of_week' => $day,
                'start_time' => '07:00:00',
                'end_time' => '17:00:00',
            ]);
        }
    }

    private function save(array $availability)
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/faculties/{$this->faculty->id}/availability", [
                'availability' => $availability,
            ]);
    }

    #[Test]
    public function an_inverted_window_is_rejected(): void
    {
        $response = $this->save([
            ['day_of_week' => 1, 'start_time' => '17:00:00', 'end_time' => '07:00:00'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('availability.0.end_time');
        $this->assertStringContainsString('later than the start', $response->json('message'));
    }

    #[Test]
    public function a_zero_length_window_is_rejected(): void
    {
        $this->save([
            ['day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '09:00:00'],
        ])->assertStatus(422);
    }

    #[Test]
    public function half_a_window_is_rejected(): void
    {
        $this->save([
            ['day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => null],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('availability.0.end_time');

        $this->save([
            ['day_of_week' => 1, 'start_time' => null, 'end_time' => '11:00:00'],
        ])->assertStatus(422);
    }

    #[Test]
    public function a_window_that_is_not_a_clock_time_is_rejected(): void
    {
        foreach (['7:30 am', 'morning', '25:00', '09:70', ''] as $bad) {
            $this->save([
                ['day_of_week' => 1, 'start_time' => $bad, 'end_time' => '17:00:00'],
            ])->assertStatus(422);
        }
    }

    #[Test]
    public function a_day_outside_the_week_is_rejected(): void
    {
        $this->save([
            ['day_of_week' => 8, 'start_time' => '07:00:00', 'end_time' => '17:00:00'],
        ])->assertStatus(422);
    }

    #[Test]
    public function a_rejected_save_leaves_the_stored_windows_untouched(): void
    {
        $this->save([
            ['day_of_week' => 1, 'start_time' => '17:00:00', 'end_time' => '07:00:00'],
        ])->assertStatus(422);

        $this->assertSame(
            [1, 2],
            $this->faculty->availabilities()->orderBy('day_of_week')->pluck('day_of_week')->all()
        );
        $this->assertSame('17:00:00', $this->faculty->availabilities()->first()->end_time);
    }

    #[Test]
    public function both_clock_forms_are_accepted(): void
    {
        $response = $this->save([
            ['day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '12:00'],
            ['day_of_week' => 2, 'start_time' => '13:00:00', 'end_time' => '19:30:00'],
        ]);

        $response->assertOk();

        $this->assertSame(
            [1, 2],
            $this->faculty->availabilities()->orderBy('day_of_week')->pluck('day_of_week')->all()
        );
    }

    #[Test]
    public function a_faculty_member_can_still_clear_their_availability_entirely(): void
    {
        // An empty list is the documented way to fall back to the section's
        // own days, so it must keep working.
        $this->save([])->assertOk();

        $this->assertSame(0, $this->faculty->availabilities()->count());
    }
}
