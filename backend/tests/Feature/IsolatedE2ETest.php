<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Isolated Laravel workflow integration test.
 * This test verifies the Laravel scheduling system workflow end-to-end,
 * including schedule generation, status transitions, approval, publication,
 * and retrieval. It uses Http::fake() to mock the AI-engine requests
 * and verifies database connection isolation.
 * 
 * NOT a real AI-engine end-to-end test.
 */
class IsolatedE2ETest extends TestCase
{
    use DatabaseTransactions;

    private $admin;
    private $section;
    private $faculty;
    private $subject;
    private $room;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up admin user for authentication
        $this->admin = \App\Models\User::create([
            'name' => 'Department Head',
            'email' => 'admin@scheduling.test',
            'password' => bcrypt('secret'),
            'role' => 'admin',
        ]);

        // Set up a section for testing
        $this->section = \App\Models\Section::create([
            'name' => 'BSIT 3A',
            'year_level' => 3,
            'semester_name' => 'Fall',
            'preferred_days' => '[]',
            'preferred_start_time' => '08:00:00',
            'preferred_end_time' => '17:00:00',
            'academic_year' => '2026-2027',
        ]);

        // Set up a faculty
        $this->faculty = \App\Models\Faculty::create([
            'name' => 'Dr. Test Faculty',
            'department' => 'Computer Science',
            'faculty_type' => 'full_time',
        ]);

        // Set up a subject
        $this->subject = \App\Models\Subject::create([
            'code' => 'CPTR3',
            'title' => 'Computer Programming',
            'year_level' => 3,
            'semester_name' => 'Fall',
            'lecture_hours' => 2,
            'lab_hours' => 1,
            // A subject with no classification is refused by the generation
            // gate (Subject::assignmentConflictReason()). A minor/shared
            // subject is schedulable by any section without an owning
            // department, which is what this fixture needs.
            'classification' => \App\Models\Subject::CLASSIFICATION_MINOR,
        ]);

        // Set up a room
        $this->room = \App\Models\Room::create([
            'name' => 'Room A-101',
            'type' => 'lecture',
            'capacity' => 50,
            'building' => 'Main Building',
        ]);

        // Assign subject to faculty and section
        $this->faculty->subjects()->attach($this->subject->id);
        $this->section->subjects()->attach($this->subject->id);
    }

    /** Test: Verifies Laravel database connection isolation */
    public function test_laravel_connection_isolation(): void
    {
        // Verify the Laravel connection uses the isolated test database
        $dbName = DB::connection()->getDatabaseName();
        $sqlName = DB::select('SELECT current_database()')[0]->current_database;

        $this->assertEquals(
            'scheduling_system_testing_isolated',
            $dbName,
            'Database connection should use the isolated test database'
        );
        $this->assertEquals(
            'scheduling_system_testing_isolated',
            $sqlName,
            'SQL current_database() should return the isolated test database'
        );
    }

    /** Test: Admin authentication and schedule generation */
    public function test_admin_can_generate_schedule(): void
    {
        // Login as admin
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@scheduling.test',
            'password' => 'secret',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        // Use the token for subsequent requests
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ]);

        // Fake the AI-engine request
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

        // Generate schedule for the section
        $response = $this->postJson("/api/schedules/generate/{$this->section->id}", []);

        $response->assertStatus(200);
        $data = $response->json();

        // Verify schedule was created. ScheduleController@generate answers
        // {schedule_id, status, sessions, unscheduled}; the persisted
        // schedule's own status is 'draft' even when the engine reports OPTIMAL.
        $this->assertArrayHasKey('schedule_id', $data);
        $schedule = \App\Models\Schedule::find($data['schedule_id']);
        $this->assertNotNull($schedule);
        $this->assertEquals('draft', $schedule->status);
    }

    /** Test: Schedule retrieval and status validation */
    public function test_schedule_retrieval_and_status(): void
    {
        // Login as admin
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@scheduling.test',
            'password' => 'secret',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        // Use the token for subsequent requests
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ]);

        // Fake AI-engine request
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

        // Generate schedule
        $scheduleId = $this->postJson("/api/schedules/generate/{$this->section->id}", [])
            ->json('schedule_id');
        $this->assertNotNull($scheduleId, 'Schedule should be created');

        // Retrieve all schedules
        $response = $this->getJson('/api/schedules');
        $response->assertStatus(200);
        $this->assertGreaterThan(0, count($response->json()));

        // Retrieve the specific schedule by its own id: the route binds a
        // Schedule, not a Section, so a section id is the wrong identifier.
        $scheduleResponse = $this->getJson("/api/schedules/{$scheduleId}");
        $scheduleResponse->assertStatus(200);
    }

    /** Test: Schedule approval */
    public function test_schedule_approval(): void
    {
        // Login as admin
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@scheduling.test',
            'password' => 'secret',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ]);

        // Fake AI-engine request
        Http::fake([
            '127.0.0.1:8001/generate-schedule/*' => Http::response([
                'status' => 'OPTIMAL',
                'message' => null,
                'sessions' => [],
            ], 200),
        ]);

        // Generate schedule
        $scheduleId = $this->postJson("/api/schedules/generate/{$this->section->id}", [])
            ->json('schedule_id');

        $this->assertNotNull($scheduleId, 'Schedule should be created');

        $approveResponse = $this->patchJson("/api/schedules/{$scheduleId}/approve", []);
        $approveResponse->assertStatus(200);

        // Verify schedule is approved
        $updatedResponse = $this->getJson("/api/schedules/{$scheduleId}");
        $updatedResponse->assertStatus(200);
        $this->assertEquals('approved', $updatedResponse->json()['status'] ?? null);
    }

    /** Test: Schedule publication */
    public function test_schedule_publication(): void
    {
        // Login as admin
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@scheduling.test',
            'password' => 'secret',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ]);

        // Fake AI-engine request
        Http::fake([
            '127.0.0.1:8001/generate-schedule/*' => Http::response([
                'status' => 'OPTIMAL',
                'message' => null,
                'sessions' => [],
            ], 200),
        ]);

        // Generate schedule
        $scheduleId = $this->postJson("/api/schedules/generate/{$this->section->id}", [])
            ->json('schedule_id');

        $this->assertNotNull($scheduleId, 'Schedule should be created');

        // publish() only accepts an approved schedule, so approve the draft first
        $this->patchJson("/api/schedules/{$scheduleId}/approve", [])->assertStatus(200);

        $publishResponse = $this->patchJson("/api/schedules/{$scheduleId}/publish", []);
        $publishResponse->assertStatus(200);

        // Verify schedule is published
        $updatedResponse = $this->getJson("/api/schedules/{$scheduleId}");
        $updatedResponse->assertStatus(200);
        $this->assertEquals('published', $updatedResponse->json()['status'] ?? null);
    }

    /** Test: Schedule unpublish */
    public function test_schedule_unpublish(): void
    {
        // Login as admin
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@scheduling.test',
            'password' => 'secret',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ]);

        // Fake AI-engine request
        Http::fake([
            '127.0.0.1:8001/generate-schedule/*' => Http::response([
                'status' => 'OPTIMAL',
                'message' => null,
                'sessions' => [],
            ], 200),
        ]);

        // Generate schedule
        $scheduleId = $this->postJson("/api/schedules/generate/{$this->section->id}", [])
            ->json('schedule_id');
        $this->assertNotNull($scheduleId, 'Schedule should be created');

        // publish() requires an approved schedule; unpublish() requires a
        // published one.
        $this->patchJson("/api/schedules/{$scheduleId}/approve", [])->assertStatus(200);
        $this->patchJson("/api/schedules/{$scheduleId}/publish", [])->assertStatus(200);
        $this->patchJson("/api/schedules/{$scheduleId}/unpublish", [])->assertStatus(200);

        // Verify the schedule reverted to draft
        $response = $this->getJson("/api/schedules/{$scheduleId}");
        $response->assertStatus(200);
        $this->assertEquals('draft', $response->json()['status'] ?? null);
    }

    /** Test: Schedule rejection */
    public function test_schedule_rejection(): void
    {
        // Login as admin
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@scheduling.test',
            'password' => 'secret',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ]);

        // Fake AI-engine request. A feasible result is required here: reject()
        // only accepts a draft, and a non-feasible engine result is answered
        // with 422 before any draft is written.
        Http::fake([
            '127.0.0.1:8001/generate-schedule/*' => Http::response([
                'status' => 'OPTIMAL',
                'message' => null,
                'sessions' => [],
            ], 200),
        ]);

        // Generate schedule
        $scheduleId = $this->postJson("/api/schedules/generate/{$this->section->id}", [])
            ->json('schedule_id');

        $this->assertNotNull($scheduleId, 'Schedule should be created');

        $rejectResponse = $this->patchJson("/api/schedules/{$scheduleId}/reject", []);
        $rejectResponse->assertStatus(200);

        // Verify schedule is rejected
        $updatedResponse = $this->getJson("/api/schedules/{$scheduleId}");
        $updatedResponse->assertStatus(200);
        $this->assertEquals('rejected', $updatedResponse->json()['status'] ?? null);
    }

    /** Test: Conflict validation */
    public function test_conflict_validation(): void
    {
        // Login as admin
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@scheduling.test',
            'password' => 'secret',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ]);

        // Fake AI-engine request returning conflict
        Http::fake([
            '127.0.0.1:8001/generate-schedule/*' => Http::response([
                'status' => 'CONFLICT',
                'message' => 'Room booking conflict detected.',
                'sessions' => [],
            ], 200),
        ]);

        // A non-feasible engine result is a data problem the Department Head
        // can act on, so ScheduleController answers 422 with the engine's own
        // message and writes no draft.
        $response = $this->postJson("/api/schedules/generate/{$this->section->id}", []);
        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Room booking conflict detected.',
            (string) $response->json('message')
        );

        // Check conflicts report
        $conflictResponse = $this->getJson('/api/reports/conflicts');
        $conflictResponse->assertStatus(200);
    }

    /** Test: Session management */
    public function test_session_management(): void
    {
        // Login as admin
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@scheduling.test',
            'password' => 'secret',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ]);

        // Fake AI-engine request
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

        // Generate schedule
        $scheduleId = $this->postJson("/api/schedules/generate/{$this->section->id}", [])
            ->json('schedule_id');
        $this->assertNotNull($scheduleId, 'Schedule should be created');

        // Get sessions — the endpoint answers {sessions, hours}
        $sessionsResponse = $this->getJson("/api/schedules/{$scheduleId}/sessions");
        $sessionsResponse->assertStatus(200);
        $sessions = $sessionsResponse->json('sessions');
        $this->assertGreaterThan(0, count($sessions));

        // Update sessions batch — updateBatch() requires at least one entry and
        // every entry needs an id belonging to this schedule.
        $updateResponse = $this->putJson("/api/schedules/{$scheduleId}/sessions", [
            'sessions' => [
                ['id' => $sessions[0]['id']],
            ],
        ]);
        $updateResponse->assertStatus(200);
    }

    /** Test: Verifies the workflow ends with proper final retrieval */
    public function test_workflow_final_retrieval(): void
    {
        // Login as admin
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'admin@scheduling.test',
            'password' => 'secret',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ]);

        // Fake AI-engine request
        Http::fake([
            '127.0.0.1:8001/generate-schedule/*' => Http::response([
                'status' => 'OPTIMAL',
                'message' => null,
                'sessions' => [],
            ], 200),
        ]);

        // Generate schedule
        $scheduleId = $this->postJson("/api/schedules/generate/{$this->section->id}", [])
            ->json('schedule_id');

        $this->assertNotNull($scheduleId);

        // Approve
        $this->patchJson("/api/schedules/{$scheduleId}/approve", [])->assertStatus(200);

        // Publish
        $this->patchJson("/api/schedules/{$scheduleId}/publish", [])->assertStatus(200);

        // Final retrieval - should show published schedule
        $finalResponse = $this->getJson("/api/schedules/{$scheduleId}");
        $finalResponse->assertStatus(200);
        $this->assertEquals('published', $finalResponse->json()['status'] ?? null);
    }
}