<?php

namespace Tests\Feature;

use App\Models\Faculty;
use App\Models\FacultyAvailability;
use App\Models\Room;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies that a section can only be assigned subjects whose year level
 * and semester match the section's own, both when saving the section and
 * (as a legacy-data safeguard) when generating a schedule. Generation must
 * be blocked with 422 and the AI engine must never be called on mismatch.
 */
class SectionSubjectMatchingTest extends TestCase
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

    private function validSectionPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'BSIT 1A',
            'year_level' => 1,
            'academic_year' => '2026-2027',
            'semester_name' => '1st Semester',
            'preferred_days' => [1, 2, 3],
            'preferred_start_time' => '07:00',
            'preferred_end_time' => '18:00',
            'student_count' => 30,
            'subject_ids' => [],
        ], $overrides);
    }

    private function makeSection(array $overrides = []): Section
    {
        return Section::create($this->validSectionPayload($overrides));
    }

    #[Test]
    public function matching_year_and_semester_is_accepted(): void
    {
        $subject = Subject::create([
            'code' => 'PROG1', 'title' => 'Programming 1',
            'year_level' => 1, 'semester_name' => '1st Semester',
            'lecture_hours' => 2, 'lab_hours' => 3,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->validSectionPayload(['subject_ids' => [$subject->id]]));

        $response->assertCreated()
            ->assertJsonPath('subjects.0.code', 'PROG1');
    }

    #[Test]
    public function wrong_semester_subject_is_rejected_with_422_naming_the_subject(): void
    {
        $subject = Subject::create([
            'code' => 'PROG2', 'title' => 'Programming 2',
            'year_level' => 1, 'semester_name' => '2nd Semester',
            'lecture_hours' => 2, 'lab_hours' => 3,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->validSectionPayload(['subject_ids' => [$subject->id]]));

        $response->assertStatus(422);
        $this->assertStringContainsString('PROG2', $response->json('message'));
        $this->assertStringContainsString('2nd Semester', $response->json('message'));

        // Nothing was attached
        $this->assertSame(0, Section::count());
    }

    #[Test]
    public function wrong_year_subject_is_rejected_with_422_naming_the_subject(): void
    {
        $subject = Subject::create([
            'code' => 'CAPSTONE', 'title' => 'Capstone Project',
            'year_level' => 3, 'semester_name' => '1st Semester',
            'lecture_hours' => 2, 'lab_hours' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/sections', $this->validSectionPayload(['subject_ids' => [$subject->id]]));

        $response->assertStatus(422);
        $this->assertStringContainsString('CAPSTONE', $response->json('message'));
        $this->assertStringContainsString('Year 3', $response->json('message'));
    }

    #[Test]
    public function multiple_mismatched_subjects_are_all_reported(): void
    {
        $wrongSemester = Subject::create([
            'code' => 'MATH2', 'title' => 'Calculus 2',
            'year_level' => 1, 'semester_name' => '2nd Semester',
            'lecture_hours' => 3, 'lab_hours' => 0,
        ]);
        $wrongYear = Subject::create([
            'code' => 'NET2', 'title' => 'Networking 2',
            'year_level' => 2, 'semester_name' => '1st Semester',
            'lecture_hours' => 2, 'lab_hours' => 3,
        ]);
        $matching = Subject::create([
            'code' => 'PROG1', 'title' => 'Programming 1',
            'year_level' => 1, 'semester_name' => '1st Semester',
            'lecture_hours' => 2, 'lab_hours' => 3,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/sections', $this->validSectionPayload([
            'subject_ids' => [$matching->id, $wrongSemester->id, $wrongYear->id],
        ]));

        $response->assertStatus(422);
        $message = $response->json('message');
        $this->assertStringContainsString('MATH2', $message);
        $this->assertStringContainsString('NET2', $message);
        $this->assertStringNotContainsString('PROG1', $message);
    }

    #[Test]
    public function update_with_mismatched_subjects_is_rejected(): void
    {
        $section = $this->makeSection();

        $wrongSubject = Subject::create([
            'code' => 'SAD', 'title' => 'Software Analysis and Design',
            'year_level' => 3, 'semester_name' => '1st Semester',
            'lecture_hours' => 3, 'lab_hours' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/sections/{$section->id}", ['subject_ids' => [$wrongSubject->id]]);

        $response->assertStatus(422);
        $this->assertStringContainsString('SAD', $response->json('message'));

        // The invalid assignment was not persisted
        $this->assertSame(0, $section->subjects()->count());
    }

    #[Test]
    public function update_is_validated_against_effective_semester_after_a_semester_change(): void
    {
        $section = $this->makeSection();

        $secondSemSubject = Subject::create([
            'code' => 'PROG2', 'title' => 'Programming 2',
            'year_level' => 1, 'semester_name' => '2nd Semester',
            'lecture_hours' => 2, 'lab_hours' => 3,
        ]);

        // Changing the section to 2nd Semester while attaching a 2nd-sem subject is valid
        $response = $this->actingAs($this->admin)->putJson("/api/sections/{$section->id}", [
            'semester_name' => '2nd Semester',
            'subject_ids' => [$secondSemSubject->id],
        ]);

        $response->assertOk();
        $this->assertSame('2nd Semester', $section->fresh()->semester_name);
        $this->assertSame(1, $section->fresh()->subjects()->count());
    }

    #[Test]
    public function generation_with_valid_assignments_still_works(): void
    {
        $section = $this->makeSection();

        $subject = Subject::create([
            'code' => 'PROG1', 'title' => 'Programming 1',
            'year_level' => 1, 'semester_name' => '1st Semester',
            'lecture_hours' => 2, 'lab_hours' => 0,
        ]);
        $section->subjects()->attach($subject->id);

        $faculty = Faculty::create([
            'name' => 'Prof. Test', 'faculty_type' => 'full_time',
            'max_teaching_load' => 24, 'is_active' => true,
        ]);
        $faculty->subjects()->attach($subject->id);
        FacultyAvailability::create([
            'faculty_id' => $faculty->id,
            'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '18:00',
        ]);
        $room = Room::create(['name' => 'R101', 'type' => 'lecture', 'capacity' => 40, 'status' => 'available']);

        Http::fake([
            '127.0.0.1:8001/generate-schedule/*' => Http::response([
                'status' => 'OPTIMAL',
                'message' => null,
                'sessions' => [[
                    'subject_id' => $subject->id,
                    'faculty_id' => $faculty->id,
                    'room_id' => $room->id,
                    'session_type' => 'lecture',
                    'day_of_week' => 1,
                    'start_hour' => 8,
                    'end_hour' => 10,
                    'is_scheduled' => true,
                ]],
            ], 200),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/schedules/generate/{$section->id}")
            ->assertOk()
            ->assertJsonPath('status', 'OPTIMAL');

        Http::assertSentCount(1);
    }

    #[Test]
    public function generation_with_mismatched_legacy_assignment_is_blocked_and_fastapi_is_not_called(): void
    {
        $section = $this->makeSection(['semester_name' => '2nd Semester']);

        // Legacy bad data: a 1st-sem subject attached to a 2nd-sem section
        $wrongSubject = Subject::create([
            'code' => 'PROG1', 'title' => 'Programming 1',
            'year_level' => 1, 'semester_name' => '1st Semester',
            'lecture_hours' => 2, 'lab_hours' => 0,
        ]);
        $section->subjects()->attach($wrongSubject->id);

        Http::fake();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/schedules/generate/{$section->id}");

        $response->assertStatus(422);
        $this->assertStringContainsString('PROG1', $response->json('message'));

        // The AI engine must never have been contacted
        Http::assertNothingSent();
    }

    #[Test]
    public function generation_with_wrong_year_legacy_assignment_is_blocked(): void
    {
        $section = $this->makeSection(['year_level' => 1]);

        $wrongYearSubject = Subject::create([
            'code' => 'CAPSTONE', 'title' => 'Capstone Project',
            'year_level' => 3, 'semester_name' => '1st Semester',
            'lecture_hours' => 2, 'lab_hours' => 0,
        ]);
        $section->subjects()->attach($wrongYearSubject->id);

        Http::fake();

        $this->actingAs($this->admin)
            ->postJson("/api/schedules/generate/{$section->id}")
            ->assertStatus(422);

        Http::assertNothingSent();
    }
}
