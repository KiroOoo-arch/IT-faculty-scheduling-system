<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleSession;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Faculty Teaching Load report and the publish-time load guard read the
 * same quantity — a faculty member's weekly teaching hours in a term — so they
 * are pinned together here.
 *
 * Two defects motivated these tests: the report merged intervals with
 * `$merged[array_pop($merged)][1]`, which indexes an array by an array and
 * throws a TypeError as soon as one faculty has two sessions on the same
 * weekday, and the publish guard referenced `Faculty` without importing it,
 * which surfaced as "Class App\Http\Controllers\Faculty not found". Both bugs
 * only appear once real sessions exist, so the fixtures always include them.
 */
class FacultyWorkloadReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculty $faculty;

    private Room $room;

    private Subject $subject;

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

        $this->faculty = Faculty::create([
            'name' => 'Prof. Load',
            'employee_no' => 'EMP-500',
            'faculty_type' => 'full_time',
            'max_teaching_load' => 24,
            'is_active' => true,
        ]);

        $this->room = Room::create([
            'name' => 'R301',
            'type' => 'lecture',
            'capacity' => 40,
            'status' => 'available',
        ]);

        $this->subject = Subject::create([
            'code' => 'NET1',
            'title' => 'Networking 1',
            'year_level' => 1,
            'classification' => 'minor',
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'is_active' => true,
        ]);

        $this->section = $this->makeSection('BSIT 1A');

        // The report lists the subject codes a faculty member is qualified for.
        $this->faculty->subjects()->attach($this->subject->id);
    }

    private function makeSection(string $name): Section
    {
        return Section::create([
            'name' => $name,
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3, 4, 5],
            'preferred_start_time' => '07:00:00',
            'preferred_end_time' => '21:00:00',
            'student_count' => 30,
        ]);
    }

    /**
     * A schedule at the given status, optionally with one Monday 09:00–11:00
     * meeting.
     */
    private function makeSchedule(string $status, ?Section $section = null, bool $withSession = true): Schedule
    {
        $schedule = Schedule::create([
            'section_id' => ($section ?? $this->section)->id,
            'status' => $status,
        ]);

        if ($withSession) {
            $this->makeSession($schedule, 1, '09:00:00', '11:00:00');
        }

        return $schedule;
    }

    private function makeSession(
        Schedule $schedule,
        int $day,
        string $start,
        string $end,
        ?Faculty $faculty = null,
        ?Room $room = null,
        ?Subject $subject = null,
    ): ScheduleSession {
        return ScheduleSession::create([
            'schedule_id' => $schedule->id,
            'subject_id' => ($subject ?? $this->subject)->id,
            'faculty_id' => ($faculty ?? $this->faculty)->id,
            'room_id' => ($room ?? $this->room)->id,
            'session_type' => 'lecture',
            'day_of_week' => $day,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    /** The report row for a faculty member, or null when they are absent. */
    private function reportRowFor(Faculty $faculty): ?array
    {
        $response = $this->actingAs($this->admin)->getJson('/api/reports/faculty-workload');
        $response->assertOk();

        return collect($response->json())->firstWhere('faculty_id', $faculty->id);
    }

    #[Test]
    public function faculty_workload_reports_published_hours_maximum_load_and_utilization(): void
    {
        $this->makeSchedule('published');

        $row = $this->reportRowFor($this->faculty);

        $this->assertNotNull($row, 'The report must include a faculty member with published work.');
        $this->assertSame('Prof. Load', $row['name']);
        $this->assertSame('full_time', $row['faculty_type']);

        // A two-hour Monday meeting is two hours per week, not 120 or 7200.
        $this->assertEqualsWithDelta(2.0, $row['assigned_hours'], 0.05);
        $this->assertSame(24, $row['max_teaching_load']);
        $this->assertEqualsWithDelta(8.3, $row['utilization_percent'], 0.05);
        $this->assertContains('NET1', $row['subjects']);
    }

    #[Test]
    public function faculty_workload_ignores_draft_and_approved_schedules(): void
    {
        $this->makeSchedule('published');
        $this->makeSchedule('draft', $this->makeSection('BSIT 1B'));
        $this->makeSchedule('approved', $this->makeSection('BSIT 1C'));

        $row = $this->reportRowFor($this->faculty);

        $this->assertEqualsWithDelta(
            2.0,
            $row['assigned_hours'],
            0.05,
            'Only published schedules may contribute to assigned hours.'
        );
    }

    #[Test]
    public function faculty_workload_counts_overlapping_sessions_on_the_same_day_once(): void
    {
        $schedule = $this->makeSchedule('published', null, false);

        // Two meetings that overlap by an hour: 09:00–11:00 and 10:00–12:00.
        $this->makeSession($schedule, 1, '09:00:00', '11:00:00');
        $this->makeSession($schedule, 1, '10:00:00', '12:00:00');

        $row = $this->reportRowFor($this->faculty);

        // Occupied time is 09:00–12:00 — three hours, not the four the two
        // durations add up to.
        $this->assertEqualsWithDelta(3.0, $row['assigned_hours'], 0.05);
    }

    #[Test]
    public function faculty_workload_keeps_different_weekdays_separate(): void
    {
        $schedule = $this->makeSchedule('published', null, false);

        $this->makeSession($schedule, 1, '09:00:00', '11:00:00');
        $this->makeSession($schedule, 2, '09:00:00', '11:00:00');

        $row = $this->reportRowFor($this->faculty);

        $this->assertEqualsWithDelta(4.0, $row['assigned_hours'], 0.05);
    }

    #[Test]
    public function publish_does_not_raise_a_missing_faculty_class_error(): void
    {
        $schedule = $this->makeSchedule('approved');

        // Before the import was added this threw
        // "Class App\\Http\\Controllers\\Faculty not found" and returned 500.
        $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$schedule->id}/publish")
            ->assertOk()
            ->assertJsonPath('status', 'published');

        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'published']);
    }

    #[Test]
    public function publish_is_refused_when_the_schedule_would_exceed_the_faculty_maximum_load(): void
    {
        $this->faculty->update(['max_teaching_load' => 2]);

        // Already-published two-hour load in another section of the same term.
        $this->makeSchedule('published', $this->makeSection('BSIT 1B'));

        // Publishing this approved two-hour meeting would reach four hours.
        $schedule = $this->makeSchedule('approved', $this->makeSection('BSIT 1C'));

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$schedule->id}/publish");

        $response->assertStatus(422);

        $message = $response->json('message');
        $this->assertStringContainsString('would exceed teaching load', $message);
        $this->assertStringContainsString('Maximum: 2h', $message);

        // The old message interpolated the expression literally; it must carry
        // the calculated values instead.
        $this->assertStringNotContainsString('round(', $message);
        $this->assertStringNotContainsString('{', $message);

        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'approved']);
    }

    #[Test]
    public function publish_is_allowed_when_the_faculty_member_is_within_their_maximum_load(): void
    {
        // 2h published + 2h proposed = 4h, well inside the 24h maximum. The
        // existing load sits on Tuesday so it is a load figure, not a clash.
        $blocker = $this->makeSchedule('published', $this->makeSection('BSIT 1B'), false);
        $this->makeSession($blocker, 2, '09:00:00', '11:00:00');

        $schedule = $this->makeSchedule('approved', $this->makeSection('BSIT 1C'));

        $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$schedule->id}/publish")
            ->assertOk()
            ->assertJsonPath('status', 'published');
    }

    #[Test]
    public function faculty_without_published_work_is_reported_with_zero_hours(): void
    {
        // No published schedules at all: the roster is still reported, each with
        // zero assigned hours, rather than fabricated rows or a missing entry.
        $response = $this->actingAs($this->admin)->getJson('/api/reports/faculty-workload');

        $response->assertOk();

        $row = collect($response->json())->firstWhere('faculty_id', $this->faculty->id);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(0.0, $row['assigned_hours'], 0.05);
        $this->assertEqualsWithDelta(0.0, $row['utilization_percent'], 0.05);
    }

    #[Test]
    public function publish_replaces_existing_approved_schedule_without_violating_load(): void
    {
        // Replace an approved schedule with a new one for the SAME section.
        // publish() archives the old schedule, so the guard must not count its
        // workload: 2h existing + 2h proposed would be 4h against a 4h maximum
        // only if both were live, but they never are at the same time.
        $existing = $this->makeSchedule('approved', $this->section, false);
        $this->makeSession($existing, 1, '09:00:00', '11:00:00');

        // The replacement carries 2 hours on Monday in the same section.
        $schedule = $this->makeSchedule('approved', $this->section, false);
        $this->makeSession($schedule, 1, '09:00:00', '11:00:00');

        $this->faculty->update(['max_teaching_load' => 4]);  // very low limit

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$schedule->id}/publish");

        // Should succeed because the existing approved schedule is archived.
        $response->assertOk()
            ->assertJsonPath('status', 'published');

        // The existing schedule should now be archived.
        $this->assertDatabaseHas('schedules', ['id' => $existing->id, 'status' => 'archived']);
        // The new schedule should be published.
        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'published']);
    }

    #[Test]
    public function publish_merges_overlapping_proposed_sessions_so_hours_are_not_double_counted(): void
    {
        // Two overlapping Monday meetings in the PROPOSED schedule: 09:00–11:00
        // and 10:00–12:00. Occupied time is 09:00–12:00 — three hours, not the
        // four the durations add up to. With a 3h maximum, a naive sum would
        // refuse this; merged intervals must allow it.
        $this->faculty->update(['max_teaching_load' => 3]);

        $schedule = $this->makeSchedule('approved', null, false);
        $this->makeSession($schedule, 1, '09:00:00', '11:00:00');
        $this->makeSession($schedule, 1, '10:00:00', '12:00:00');

        $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$schedule->id}/publish")
            ->assertOk()
            ->assertJsonPath('status', 'published');
    }

    #[Test]
    public function publish_merges_adjacent_proposed_sessions_on_the_same_weekday(): void
    {
        // Two back-to-back Monday meetings: 09:00–11:00 and 11:00–13:00. They
        // touch but do not overlap, so together they occupy 09:00–13:00 — four
        // hours. With a 4h maximum this is within the limit; the guard must
        // count them as four hours, not refuse on a double-counted total.
        $this->faculty->update(['max_teaching_load' => 4]);

        $schedule = $this->makeSchedule('approved', null, false);
        $this->makeSession($schedule, 1, '09:00:00', '11:00:00');
        $this->makeSession($schedule, 1, '11:00:00', '13:00:00');

        $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$schedule->id}/publish")
            ->assertOk()
            ->assertJsonPath('status', 'published');
    }

    #[Test]
    public function publish_refuses_proposed_sessions_that_exceed_the_limit_after_merging(): void
    {
        // Merged occupied time on Monday is 09:00–13:00 — four hours, against a
        // 3h maximum. The refusal message must carry the merged total (4h), not
        // the five hours the three sessions would sum to without merging.
        $this->faculty->update(['max_teaching_load' => 3]);

        $schedule = $this->makeSchedule('approved', null, false);
        $this->makeSession($schedule, 1, '09:00:00', '11:00:00');
        $this->makeSession($schedule, 1, '11:00:00', '12:00:00');
        $this->makeSession($schedule, 1, '12:00:00', '13:00:00');

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$schedule->id}/publish");

        $response->assertStatus(422);

        $message = $response->json('message');
        $this->assertStringContainsString('Proposed: 4h', $message);
        $this->assertStringContainsString('Total: 4h', $message);

        // The schedule must remain approved — not published, not archived.
        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'approved']);
    }

    #[Test]
    public function publish_keeps_proposed_sessions_on_different_weekdays_separate(): void
    {
        // A two-hour Monday meeting plus a two-hour Tuesday meeting occupy four
        // hours per week in total. Weekdays must not be merged into one another:
        // against a 3h maximum this is a refusal of 4h, not an allowance of 2h.
        $this->faculty->update(['max_teaching_load' => 3]);

        $schedule = $this->makeSchedule('approved', null, false);
        $this->makeSession($schedule, 1, '09:00:00', '11:00:00');
        $this->makeSession($schedule, 2, '09:00:00', '11:00:00');

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$schedule->id}/publish");

        $response->assertStatus(422);

        $message = $response->json('message');
        $this->assertStringContainsString('Total: 4h', $message);

        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'approved']);
    }

    #[Test]
    public function publish_is_refused_when_the_section_has_no_academic_year(): void
    {
        // The sections table stores academic_year as a non-nullable string and
        // SectionController validates it as required, so publishing a schedule
        // whose section lacks term data is invalid. The guard must reject it
        // with a clear message rather than silently skipping the term filter
        // (which would compare against schedules from unrelated terms).
        $section = $this->makeSection('BSIT 1D');
        $section->forceFill(['academic_year' => ''])->save();

        $schedule = $this->makeSchedule('approved', $section);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$schedule->id}/publish");

        $response->assertStatus(422);
        $this->assertStringContainsString('academic-year or semester', $response->json('message'));

        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'approved']);
    }

    #[Test]
    public function publish_is_refused_when_the_section_has_no_semester(): void
    {
        $section = $this->makeSection('BSIT 1E');
        $section->forceFill(['semester_name' => ''])->save();

        $schedule = $this->makeSchedule('approved', $section);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$schedule->id}/publish");

        $response->assertStatus(422);
        $this->assertStringContainsString('academic-year or semester', $response->json('message'));

        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'approved']);
    }

    #[Test]
    public function publish_failure_leaves_the_superseded_schedule_untouched(): void
    {
        // The archive-and-publish pair runs in one transaction. When the load
        // guard refuses, nothing is archived and the old schedule stays live —
        // a failure cannot leave the section with two live schedules or none.
        $this->faculty->update(['max_teaching_load' => 2]);

        $existing = $this->makeSchedule('published', $this->section, false);
        $this->makeSession($existing, 1, '09:00:00', '11:00:00');

        // The replacement is over the limit once the other section's load is
        // included, so publish must fail and leave both schedules as they were.
        $otherSection = $this->makeSection('BSIT 1F');
        $this->makeSession($this->makeSchedule('approved', $otherSection, false), 2, '09:00:00', '11:00:00');

        $schedule = $this->makeSchedule('approved', $this->section, false);
        $this->makeSession($schedule, 1, '09:00:00', '11:00:00');

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/schedules/{$schedule->id}/publish");

        $response->assertStatus(422);

        // Neither the superseded schedule nor the rejected one changed state.
        $this->assertDatabaseHas('schedules', ['id' => $existing->id, 'status' => 'published']);
        $this->assertDatabaseHas('schedules', ['id' => $schedule->id, 'status' => 'approved']);
    }
}
