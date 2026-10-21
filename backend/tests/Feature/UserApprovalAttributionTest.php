<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Deleting a user who is credited with approving a schedule.
 *
 * schedules.approved_by is a NO ACTION foreign key onto users, so a raw
 * delete blows up with SQLSTATE[23503] while that link still exists — the
 * same failure mode the Subjects page used to have. The approval itself is
 * real history, so the intended behaviour is to keep the schedule and drop
 * the attribution (the column is nullable), rather than deleting the
 * approved timetable.
 */
class UserApprovalAttributionTest extends TestCase
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

    private function section(): Section
    {
        return Section::create([
            'name' => 'BSIT 1A',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3, 4, 5],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '15:00',
        ]);
    }

    #[Test]
    public function a_user_who_approved_a_schedule_can_be_deleted(): void
    {
        // A second admin, so the "last admin" guard does not mask the FK error.
        $approver = User::create([
            'name' => 'Second Admin',
            'email' => 'approver@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $schedule = Schedule::create([
            'section_id' => $this->section()->id,
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/users/{$approver->id}")
            ->assertOk();

        $this->assertSame(0, User::where('id', $approver->id)->count(), 'User survived the delete.');

        // The approved timetable must survive; only the attribution is cleared.
        $schedule->refresh();
        $this->assertSame('approved', $schedule->status, 'Approval history was destroyed.');
        $this->assertNull($schedule->approved_by, 'Stale approved_by reference left behind.');
    }

    #[Test]
    public function deleting_a_user_without_approvals_still_works(): void
    {
        $bystander = User::create([
            'name' => 'Third Admin',
            'email' => 'bystander@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/users/{$bystander->id}")
            ->assertOk();

        $this->assertSame(0, User::where('id', $bystander->id)->count());
    }
}
