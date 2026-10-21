<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\FacultyAvailability;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * schedules.generated_at is a naive `timestamp without time zone` column whose
 * default is CURRENT_TIMESTAMP. PostgreSQL converts that instant into the
 * SESSION timezone before storing it, so on a server configured for
 * Asia/Kuala_Lumpur the value was persisted 8 hours ahead of the PHP-written
 * created_at / updated_at — and then serialised with a `Z` that was never true
 * (approved_at < generated_at for 12 of 13 historical schedules).
 *
 * These tests pin the correction: the connection is pinned to UTC, the
 * generation path records generated_at from the application clock, and the
 * resulting instants agree with each other and with the JSON clients read.
 *
 * The application clock is frozen at a fixed instant far from "now", so a read
 * that leaks the database server's real clock (or its timezone) is unmistakable.
 */
class ScheduleTimestampTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private const FROZEN = '2026-03-15 09:00:00';

    private User $admin;

    private Section $section;

    private Subject $subject;

    private Faculty $faculty;

    private Room $room;

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
            'name' => 'BSIT 2A',
            'year_level' => 2,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
            'student_count' => 30,
        ]);

        $this->subject = Subject::create([
            'code' => 'PROG2',
            'classification' => 'minor',
            'title' => 'Programming 2',
            'classification' => 'minor',
            'year_level' => 2,
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => 0,
        ]);

        $this->faculty = Faculty::create([
            'name' => 'Prof. Test',
            'faculty_type' => 'full_time',
            'max_teaching_load' => 24,
            'is_active' => true,
        ]);

        $this->faculty->subjects()->attach($this->subject->id);

        FacultyAvailability::create([
            'faculty_id' => $this->faculty->id,
            'day_of_week' => 1,
            'start_time' => '07:00',
            'end_time' => '18:00',
        ]);

        $this->room = Room::create([
            'name' => 'R101',
            'type' => 'lecture',
            'capacity' => 40,
            'status' => 'available',
        ]);

        $this->section->subjects()->attach($this->subject->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * The engine answers with one placeable session, so the generation path
     * runs all the way through to Schedule::create() and ScheduleSession::create().
     */
    private function fakeAiEngine(): void
    {
        Http::fake([
            '127.0.0.1:8001/generate-schedule/*' => Http::response([
                'status' => 'OPTIMAL',
                'message' => null,
                'sessions' => [
                    [
                        'subject_id' => $this->subject->id,
                        'faculty_id' => $this->faculty->id,
                        'room_id' => $this->room->id,
                        'session_type' => 'lecture',
                        'day_of_week' => 1,
                        'start_hour' => 8,
                        'end_hour' => 10,
                        'is_scheduled' => true,
                    ],
                ],
            ], 200),
        ]);
    }

    /** Generate against the faked engine and return the schedule that was written. */
    private function generate(): Schedule
    {
        $this->fakeAiEngine();

        $this->actingAs($this->admin)
            ->postJson("/api/schedules/generate/{$this->section->id}")
            ->assertOk();

        return Schedule::query()->latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------------
    // 1. The write path
    // -----------------------------------------------------------------------

    #[Test]
    public function generated_schedule_records_generated_at_from_the_application_clock(): void
    {
        Carbon::setTestNow(self::FROZEN);

        $schedule = $this->generate();

        // "within five seconds of the application clock" — the column must be
        // stamped by the app, not by the database server's CURRENT_TIMESTAMP.
        $this->assertLessThan(
            5,
            abs($schedule->generated_at->diffInSeconds(now())),
            'generated_at is not the application clock — it is being read from the '.
            'database server clock (or its timezone) instead.'
        );

        $this->assertSame(
            self::FROZEN,
            $schedule->generated_at->utc()->format('Y-m-d H:i:s'),
            'generated_at was stored as a different instant than the app clock.'
        );
    }

    #[Test]
    public function generated_at_and_created_at_record_the_same_instant(): void
    {
        Carbon::setTestNow(self::FROZEN);

        $schedule = $this->generate();

        // The historical defect was visible INSIDE one row: created_at and
        // generated_at described the same event 8 hours apart.
        $this->assertSame(
            $schedule->created_at->utc()->format('Y-m-d H:i:s'),
            $schedule->generated_at->utc()->format('Y-m-d H:i:s'),
            'generated_at and created_at disagree on the same row.'
        );
    }

    // -----------------------------------------------------------------------
    // 2. The connection itself
    // -----------------------------------------------------------------------

    #[Test]
    public function the_active_postgres_connection_reports_utc(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        // Without `timezone` in config/database.php this returns whatever the
        // server's postgresql.conf says (Asia/Kuala_Lumpur on the dev machine),
        // which is what silently shifted a CURRENT_TIMESTAMP default.
        $timezone = DB::connection()
            ->selectOne("select current_setting('TimeZone') as tz")
            ->tz;

        $this->assertSame(
            'UTC',
            $timezone,
            'The PostgreSQL session timezone is not pinned to UTC, so database-side '.
            'defaults will be stored in the server local zone.'
        );
    }

    // -----------------------------------------------------------------------
    // 3. Ordering between the two timestamps
    // -----------------------------------------------------------------------

    #[Test]
    public function an_approval_after_generation_is_never_earlier_than_generated_at(): void
    {
        Carbon::setTestNow(self::FROZEN);

        $schedule = $this->generate();

        // Five minutes later, on the same frozen-then-advanced clock.
        Carbon::setTestNow('2026-03-15 09:05:00');

        $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$schedule->id}/approve")
            ->assertOk();

        $schedule->refresh();

        $this->assertTrue(
            $schedule->approved_at->greaterThanOrEqualTo($schedule->generated_at),
            'approved_at is earlier than generated_at — a schedule appears to have '.
            'been approved before it was generated.'
        );

        $this->assertSame('2026-03-15 09:05:00', $schedule->approved_at->utc()->format('Y-m-d H:i:s'));
    }

    // -----------------------------------------------------------------------
    // 4. Serialisation
    // -----------------------------------------------------------------------

    #[Test]
    public function generated_at_is_serialised_as_the_instant_that_was_stored(): void
    {
        Carbon::setTestNow(self::FROZEN);

        $schedule = $this->generate();

        $json = $this->actingAs($this->admin)
            ->getJson("/api/schedules/{$schedule->id}")
            ->assertOk()
            ->json();

        // The `Z` claim must be true: the instant emitted is the instant stored.
        $this->assertSame('2026-03-15T09:00:00.000000Z', $json['generated_at']);
        $this->assertSame('2026-03-15T09:00:00.000000Z', $json['created_at']);

        $this->assertLessThan(
            1,
            abs(Carbon::parse($json['generated_at'])->diffInSeconds(Carbon::parse($json['created_at']))),
            'The API emits generated_at and created_at as different instants.'
        );
    }

    // -----------------------------------------------------------------------
    // 5. Nothing else moved
    // -----------------------------------------------------------------------

    #[Test]
    public function php_written_created_at_and_updated_at_are_unchanged(): void
    {
        Carbon::setTestNow(self::FROZEN);

        // Room is an ordinary Eloquent model untouched by the fix: if pinning
        // the connection (or a wrong change to app.timezone) shifted anything,
        // this is where it shows.
        $room = Room::create([
            'name' => 'R-UNCHANGED',
            'type' => 'lecture',
            'capacity' => 40,
            'status' => 'available',
        ]);

        $this->assertSame(self::FROZEN, $room->created_at->utc()->format('Y-m-d H:i:s'));
        $this->assertLessThan(1, abs($room->created_at->diffInSeconds(now())), 'created_at is not the app clock.');

        Carbon::setTestNow('2026-03-15 10:30:00');
        $room->update(['capacity' => 55]);
        $room->refresh();

        $this->assertSame(self::FROZEN, $room->created_at->utc()->format('Y-m-d H:i:s'), 'created_at moved.');
        $this->assertSame('2026-03-15 10:30:00', $room->updated_at->utc()->format('Y-m-d H:i:s'), 'updated_at is wrong.');

        // The stored naive values are UTC wall clock, and they round-trip out
        // unchanged — no compensation is being applied anywhere.
        $raw = DB::table('rooms')->where('id', $room->id)->first();
        $this->assertSame('2026-03-15 09:00:00', $raw->created_at);
        $this->assertSame('2026-03-15 10:30:00', $raw->updated_at);

        $array = $room->toArray();
        $this->assertSame('2026-03-15T09:00:00.000000Z', $array['created_at']);
        $this->assertSame('2026-03-15T10:30:00.000000Z', $array['updated_at']);
    }

    // -----------------------------------------------------------------------
    // 6. Ordering of the schedule list
    // -----------------------------------------------------------------------

    #[Test]
    public function the_schedule_list_orders_by_created_at_not_generated_at(): void
    {
        // Two rows whose created_at order deliberately contradicts their
        // generated_at order, so the two possible orderings are distinguishable.
        Carbon::setTestNow('2026-03-15 09:00:00');
        $olderCreatedButNewestGenerated = Schedule::create([
            'section_id' => $this->section->id,
            'status' => 'draft',
            'generated_at' => '2030-01-01 00:00:00',
        ]);

        Carbon::setTestNow('2026-03-15 10:00:00');
        $newerCreatedButOldestGenerated = Schedule::create([
            'section_id' => $this->section->id,
            'status' => 'draft',
            'generated_at' => '2020-01-01 00:00:00',
        ]);

        Carbon::setTestNow();

        $rows = $this->actingAs($this->admin)
            ->getJson('/api/schedules')
            ->assertOk()
            ->json();

        // Newest created first — ordering must stay on created_at (a UTC column
        // the app writes), never on generated_at.
        $this->assertSame(
            [$newerCreatedButOldestGenerated->id, $olderCreatedButNewestGenerated->id],
            array_column($rows, 'id'),
            'The schedule list is no longer ordered by created_at.'
        );
    }
}
