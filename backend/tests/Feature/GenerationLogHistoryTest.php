<?php

namespace Tests\Feature;

use App\Models\ScheduleGenerationLog;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The generation-log history is an audit convenience, so the admin can clear it
 * from Reports. Clearing must wipe the history and nothing else: the schedules,
 * sessions and master data are the timetable and must survive.
 */
class GenerationLogHistoryTest extends TestCase
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
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
            'student_count' => 30,
        ]);
    }

    private function makeLog(string $status = 'partial'): ScheduleGenerationLog
    {
        return ScheduleGenerationLog::create([
            'section_id' => $this->section->id,
            'requested_by' => $this->admin->id,
            'status' => $status,
            'message' => '1 session(s) could not be scheduled.',
            'unscheduled_sessions' => [[
                'subject_id' => 1,
                'session_type' => 'lecture',
                'is_scheduled' => false,
                'reason' => 'No free slot.',
            ]],
        ]);
    }

    #[Test]
    public function clearing_removes_every_generation_log(): void
    {
        $this->makeLog();
        $this->makeLog('optimal');
        $this->assertSame(2, ScheduleGenerationLog::count());

        $response = $this->actingAs($this->admin)->deleteJson('/api/reports/conflicts');

        $response->assertOk();
        $response->assertJsonPath('deleted', 2);
        $this->assertSame(0, ScheduleGenerationLog::count());
    }

    #[Test]
    public function clearing_leaves_master_data_untouched(): void
    {
        $this->makeLog();

        $this->actingAs($this->admin)->deleteJson('/api/reports/conflicts')->assertOk();

        $this->assertSame(1, Section::count());
        $this->assertSame(1, User::count());
    }

    #[Test]
    public function the_history_reads_empty_after_clearing(): void
    {
        $this->makeLog();

        $this->actingAs($this->admin)->deleteJson('/api/reports/conflicts')->assertOk();

        $this->actingAs($this->admin)
            ->getJson('/api/reports/conflicts')
            ->assertOk()
            ->assertJsonCount(0);
    }
}
