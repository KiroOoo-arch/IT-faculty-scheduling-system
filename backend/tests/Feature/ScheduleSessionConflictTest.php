<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\FacultyAvailability;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleSession;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin session editor can only show a useful reason if the API actually
 * returns one, and it must not silently render an empty page when a token has
 * expired. These tests pin that contract:
 *  - a rejected edit returns HTTP 422 with the specific reasons in `conflicts`
 *  - a missing/invalid token returns HTTP 401 (normal unauthenticated behavior)
 */
class ScheduleSessionConflictTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Faculty $faculty;
    private ScheduleSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Department Head',
            'email' => 'admin@test.local',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $room = Room::create([
            'name' => 'R101',
            'type' => 'lecture',
            'capacity' => 40,
            'status' => 'available',
        ]);

        $subject = Subject::create([
            'code' => 'PROG1',
            'title' => 'Programming 1',
            'year_level' => 1,
            'semester_name' => '1st Semester',
            'lecture_hours' => 2,
            'lab_hours' => 0,
            'is_active' => true,
        ]);

        $this->faculty = Faculty::create([
            'name' => 'Prof. Availability',
            'employee_no' => 'EMP-001',
            'faculty_type' => 'full_time',
            'max_teaching_load' => 24,
            'is_active' => true,
        ]);

        // Declared availability: 07:00-17:00, Monday-Friday.
        foreach ([1, 2, 3, 4, 5] as $day) {
            FacultyAvailability::create([
                'faculty_id' => $this->faculty->id,
                'day_of_week' => $day,
                'start_time' => '07:00:00',
                'end_time' => '17:00:00',
            ]);
        }

        $section = Section::create([
            'name' => 'BSIT 1A',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00:00',
            'preferred_end_time' => '21:00:00',
            'student_count' => 30,
        ]);

        $schedule = Schedule::create(['section_id' => $section->id, 'status' => 'draft']);

        $this->session = ScheduleSession::create([
            'schedule_id' => $schedule->id,
            'subject_id' => $subject->id,
            'faculty_id' => $this->faculty->id,
            'room_id' => $room->id,
            'session_type' => 'lecture',
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ]);
    }

    #[Test]
    public function request_without_a_token_is_rejected_with_401(): void
    {
        $this->getJson('/api/schedules')->assertStatus(401);
    }

    #[Test]
    public function request_with_an_invalid_token_is_rejected_with_401_json(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer not-a-real-token',
            'Accept' => 'application/json',
        ])->getJson('/api/schedules')->assertStatus(401);
    }

    #[Test]
    public function in_window_session_edit_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$this->session->id}", [
                'day_of_week' => 1,
                'start_time' => '09:00',
                'end_time' => '11:00',
            ])
            ->assertOk();
    }

    #[Test]
    public function out_of_window_edit_returns_specific_conflict_reasons(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$this->session->id}", [
                'day_of_week' => 1,
                'start_time' => '18:00',
                'end_time' => '20:00',
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'conflicts']);

        $conflicts = $response->json('conflicts');
        $this->assertIsArray($conflicts);
        $this->assertNotEmpty($conflicts);

        // The detail must name the faculty and the rejected hours, not just say
        // "conflict" — this is exactly what the admin needs to read. Hours are
        // written on a 12-hour clock, because this string is shown verbatim in
        // the admin UI (and must not carry the stored `:00` seconds).
        $joined = implode(' ', $conflicts);
        $this->assertStringContainsString('Prof. Availability', $joined);
        $this->assertStringContainsString('6:00 PM', $joined);
        $this->assertStringContainsString('8:00 PM', $joined);
        $this->assertStringNotContainsString('18:00', $joined);
        $this->assertStringNotContainsString(':00:00', $joined);
    }

    #[Test]
    public function edit_onto_the_midday_break_returns_422(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$this->session->id}", [
                'day_of_week' => 1,
                'start_time' => '12:00',
                'end_time' => '14:00',
            ]);

        $response->assertStatus(422);

        // The reason must name the break, so the admin knows what to move it off.
        $conflicts = $response->json('conflicts');
        $this->assertIsArray($conflicts);
        $this->assertStringContainsString('break', strtolower(implode(' ', $conflicts)));

        // A rejected edit must leave the session untouched.
        $this->session->refresh();
        $this->assertSame('09:00:00', $this->session->start_time);
        $this->assertSame('11:00:00', $this->session->end_time);
    }

    #[Test]
    public function edit_onto_the_break_is_allowed_when_the_break_is_disabled(): void
    {
        Setting::put(Setting::LUNCH_ENABLED_KEY, 'false');

        $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$this->session->id}", [
                'day_of_week' => 1,
                'start_time' => '12:00',
                'end_time' => '14:00',
            ])
            ->assertOk();

        $this->session->refresh();
        $this->assertSame('12:00:00', $this->session->start_time);
    }

    #[Test]
    public function rejected_edit_does_not_change_the_session(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/api/schedules/sessions/{$this->session->id}", [
                'day_of_week' => 1,
                'start_time' => '18:00',
                'end_time' => '20:00',
            ])
            ->assertStatus(422);

        $this->session->refresh();
        $this->assertSame('09:00:00', $this->session->start_time);
        $this->assertSame('11:00:00', $this->session->end_time);
    }
}
