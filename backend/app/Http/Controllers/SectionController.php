<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsPublishedReferences;
use App\Models\Schedule;
use App\Models\ScheduleSession;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    use GuardsPublishedReferences;

    /**
     * Validate that every subject assigned to a section belongs to the
     * section's year level and semester. Returns the offending subjects
     * keyed by code => reason, or an empty array when all match.
     */
    private function subjectMismatches(int $yearLevel, string $semesterName, array $subjectIds): array
    {
        if (empty($subjectIds)) {
            return [];
        }

        $subjects = Subject::whereIn('id', $subjectIds)->get();
        $mismatches = [];

        foreach ($subjects as $subject) {
            if ((int) $subject->year_level !== (int) $yearLevel
                || $subject->semester_name !== $semesterName) {
                $problems = [];
                if ((int) $subject->year_level !== (int) $yearLevel) {
                    $problems[] = "belongs to Year {$subject->year_level}";
                }
                if ($subject->semester_name !== $semesterName) {
                    $problems[] = "belongs to {$subject->semester_name}";
                }
                $mismatches[] = sprintf(
                    '%s %s; this section is Year %d, %s.',
                    $subject->code,
                    implode(' and ', $problems),
                    $yearLevel,
                    $semesterName
                );
            }
        }

        return $mismatches;
    }

    public function index()
    {
        return response()->json(Section::with('subjects')->get());
    }

    public function show(Section $section)
    {
        $section->load('subjects');

        return response()->json($section);
    }

    public function store(Request $request)
    {
        // FIX: Clean AM/PM from time before validation
        if ($request->has('preferred_start_time')) {
            $request->merge([
                'preferred_start_time' => explode(' ', $request->preferred_start_time)[0]
            ]);
        }
        if ($request->has('preferred_end_time')) {
            $request->merge([
                'preferred_end_time' => explode(' ', $request->preferred_end_time)[0]
            ]);
        }

        $validated = $request->validate([
            'name' => 'required|string',
            'year_level' => 'required|integer|min:1|max:4',
            'academic_year' => 'required|string',
            'semester_name' => 'required|string',
            // The days are fed straight into the solver as the variable domain
            // for every session's day, so an empty list or a value outside
            // 1–7 (Sunday) produces a section that either cannot be scheduled
            // at all or is scheduled on a day no screen can render.
            'preferred_days' => 'required|array|min:1',
            'preferred_days.*' => 'integer|between:1,7|distinct',
            'preferred_start_time' => 'required|date_format:H:i',
            'preferred_end_time' => 'required|date_format:H:i|after:preferred_start_time',
            'student_count' => 'sometimes|integer|min:1|max:1000',
            'subject_ids' => 'sometimes|array',
            'subject_ids.*' => 'exists:subjects,id',
        ]);

        if (!empty($validated['subject_ids'])) {
            $mismatches = $this->subjectMismatches(
                $validated['year_level'],
                $validated['semester_name'],
                $validated['subject_ids']
            );
            if (!empty($mismatches)) {
                return response()->json([
                    'message' => 'Some subjects do not match this section\'s year level and semester: '
                        . implode(' ', $mismatches),
                    'errors' => ['subject_ids' => $mismatches],
                ], 422);
            }
        }

        $attributes = [
            'name' => $validated['name'],
            'year_level' => $validated['year_level'],
            'academic_year' => $validated['academic_year'],
            'semester_name' => $validated['semester_name'],
            'preferred_days' => $validated['preferred_days'],
            'preferred_start_time' => $validated['preferred_start_time'],
            'preferred_end_time' => $validated['preferred_end_time'],
        ];

        // Class size is only set when supplied, so the column's own default
        // stays authoritative for clients that do not send it.
        if (isset($validated['student_count'])) {
            $attributes['student_count'] = $validated['student_count'];
        }

        $section = Section::create($attributes);

        if (!empty($validated['subject_ids'])) {
            $section->subjects()->sync($validated['subject_ids']);
        }

        return response()->json($section->load('subjects'), 201);
    }

    public function update(Request $request, Section $section)
    {
        // FIX: Clean AM/PM from time before validation
        if ($request->has('preferred_start_time')) {
            $request->merge([
                'preferred_start_time' => explode(' ', $request->preferred_start_time)[0]
            ]);
        }
        if ($request->has('preferred_end_time')) {
            $request->merge([
                'preferred_end_time' => explode(' ', $request->preferred_end_time)[0]
            ]);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'year_level' => 'sometimes|integer|min:1|max:4',
            'academic_year' => 'sometimes|string',
            'semester_name' => 'sometimes|string',
            'preferred_days' => 'sometimes|array|min:1',
            'preferred_days.*' => 'integer|between:1,7|distinct',
            'preferred_start_time' => 'sometimes|date_format:H:i',
            'preferred_end_time' => 'sometimes|date_format:H:i',
            'student_count' => 'sometimes|integer|min:1|max:1000',
            'subject_ids' => 'sometimes|array',
            'subject_ids.*' => 'exists:subjects,id',
        ]);

        // Effective values: submitted changes merged over the section's current state
        $effectiveYearLevel = $validated['year_level'] ?? $section->year_level;
        $effectiveSemester = $validated['semester_name'] ?? $section->semester_name;

        // The window is validated on the merged state, not on the payload: a
        // partial update that moves only one end can still invert the window
        // (send just an earlier `preferred_end_time`), and an inverted window
        // makes every generation attempt for the section infeasible.
        $effectiveStart = substr($validated['preferred_start_time'] ?? $section->preferred_start_time, 0, 5);
        $effectiveEnd = substr($validated['preferred_end_time'] ?? $section->preferred_end_time, 0, 5);

        if ($effectiveEnd <= $effectiveStart) {
            return response()->json([
                'message' => 'The preferred end time must be later than the preferred start time. '
                    . "This section would run {$effectiveStart} to {$effectiveEnd}.",
                'errors' => ['preferred_end_time' => [
                    'The preferred end time must be later than the preferred start time.',
                ]],
            ], 422);
        }

        if (isset($validated['subject_ids'])) {
            $mismatches = $this->subjectMismatches(
                $effectiveYearLevel,
                $effectiveSemester,
                $validated['subject_ids']
            );
            if (!empty($mismatches)) {
                return response()->json([
                    'message' => 'Some subjects do not match this section\'s year level and semester: '
                        . implode(' ', $mismatches),
                    'errors' => ['subject_ids' => $mismatches],
                ], 422);
            }
        }

        $section->update(collect($validated)->except('subject_ids')->toArray());

        if (isset($validated['subject_ids'])) {
            $section->subjects()->sync($validated['subject_ids']);
        }

        return response()->json($section->load('subjects'));
    }

    public function destroy(Request $request, Section $section)
    {
        $publishedIds = Schedule::where('section_id', $section->id)
            ->where('status', 'published')
            ->pluck('id')
            ->all();

        // Deleting a section cascades every schedule it owns, not just the
        // published one, so the total is every session across all of them.
        $allScheduleIds = Schedule::where('section_id', $section->id)->pluck('id')->all();

        $conflict = $this->publishedReferenceConflict(
            $request,
            $publishedIds,
            ScheduleSession::whereIn('schedule_id', $allScheduleIds)->count(),
            ScheduleSession::whereIn('schedule_id', $publishedIds)->count()
        );

        if ($conflict) {
            return $conflict;
        }

        $section->delete();

        return response()->json(['message' => 'Section deleted successfully']);
    }
}

/**
 * SectionController
 *
 * Handles section API actions:
 * - index(): list all sections with their subjects
 * - show(): get one section with its subjects
 * - store(): create a new section and attach subjects
 * - update(): edit an existing section and update subjects
 * - destroy(): delete a section
 *
 * Also cleans AM/PM time input before validation and returns JSON responses.
 */