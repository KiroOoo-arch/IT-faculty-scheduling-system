<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\Subject;
use Illuminate\Http\Request;

class SectionController extends Controller
{
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
            'preferred_days' => 'required|array',
            'preferred_start_time' => 'required|date_format:H:i',
            'preferred_end_time' => 'required|date_format:H:i|after:preferred_start_time',
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

        $section = Section::create([
            'name' => $validated['name'],
            'year_level' => $validated['year_level'],
            'academic_year' => $validated['academic_year'],
            'semester_name' => $validated['semester_name'],
            'preferred_days' => $validated['preferred_days'],
            'preferred_start_time' => $validated['preferred_start_time'],
            'preferred_end_time' => $validated['preferred_end_time'],
        ]);

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
            'preferred_days' => 'sometimes|array',
            'preferred_start_time' => 'sometimes|date_format:H:i',
            'preferred_end_time' => 'sometimes|date_format:H:i',
            'subject_ids' => 'sometimes|array',
            'subject_ids.*' => 'exists:subjects,id',
        ]);

        // Effective values: submitted changes merged over the section's current state
        $effectiveYearLevel = $validated['year_level'] ?? $section->year_level;
        $effectiveSemester = $validated['semester_name'] ?? $section->semester_name;

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

    public function destroy(Section $section)
    {
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