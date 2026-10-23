<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsPublishedReferences;
use App\Models\Department;
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

    /**
     * The section a proposed assignment would land in, built without touching the
     * database: the gate runs before the section exists (create) or before it is
     * updated, and the rule it consults only reads a name and a department.
     */
    private function candidateSection(string $name, ?int $departmentId): Section
    {
        $section = new Section([
            'name' => $name,
            'department_id' => $departmentId,
        ]);

        $section->setRelation(
            'department',
            $departmentId === null ? null : Department::find($departmentId)
        );

        return $section;
    }

    /**
     * Validate that every subject assigned to a section may actually be scheduled
     * there. Returns one sentence per offending subject, or an empty array when
     * all are allowed.
     *
     * The judgement is made by the Subject model — a minor is allowed everywhere,
     * a major only where a department is authorized for it, an unclassified
     * subject nowhere — so the section editor, the subject list, the generation
     * gate and the session validator cannot disagree about what a department may
     * take.
     */
    private function subjectDepartmentConflicts(string $sectionName, ?int $departmentId, array $subjectIds): array
    {
        if (empty($subjectIds)) {
            return [];
        }

        $subjects = Subject::with('department')->whereIn('id', $subjectIds)->get();
        $target = null;

        $conflicts = [];

        foreach ($subjects as $subject) {
            $target ??= $this->candidateSection($sectionName, $departmentId);

            $reason = $subject->assignmentConflictReason($target);

            if ($reason !== null) {
                $conflicts[] = $reason;
            }
        }

        return $conflicts;
    }

    public function index()
    {
        return response()->json(Section::with(['department', 'subjects.department'])->get());
    }

    public function show(Section $section)
    {
        $section->load(['department', 'subjects.department']);

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
            //
            // If department_id is not supplied, it is auto-computed from the
            // section name prefix: BIT-* → BIT department, ELEC-* → ELEC department.
            // The client may explicitly override this by providing a department_id.
            'department_id' => 'sometimes|nullable|integer|exists:departments,id',
            'subject_ids' => 'sometimes|array',
            'subject_ids.*' => 'exists:subjects,id',
        ]);

        // Auto-compute department from name prefix if not explicitly provided
        $computedDepartmentId = null;
        if (!array_key_exists('department_id', $validated)) {
            $nameLower = strtolower($validated['name']);
            if (str_starts_with($nameLower, 'bit-')) {
                $computedDepartmentId = 1; // BIT
            } elseif (str_starts_with($nameLower, 'elec-')) {
                $computedDepartmentId = 2; // ELEC
            }
        }

        $effectiveDepartmentId = $validated['department_id'] ?? $computedDepartmentId;

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

            $conflicts = $this->subjectDepartmentConflicts(
                $validated['name'],
                $effectiveDepartmentId,
                $validated['subject_ids']
            );
            if (!empty($conflicts)) {
                return response()->json([
                    'message' => 'Some subjects do not belong to this section\'s department: '
                        . implode(' ', $conflicts),
                    'errors' => ['subject_ids' => $conflicts],
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

        // Set when supplied, including an explicit null (no department), so a
        // client can clear the owner as well as change it.
        // Fall back to computed prefix-based department if none supplied.
        $finalDepartmentId = array_key_exists('department_id', $validated)
            ? $validated['department_id']
            : $computedDepartmentId;

        $attributes['department_id'] = $finalDepartmentId;

        $section = Section::create($attributes);

        if (!empty($validated['subject_ids'])) {
            $section->subjects()->sync($validated['subject_ids']);
        }

        return response()->json($section->load(['department', 'subjects.department']), 201);
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
            'department_id' => 'sometimes|nullable|integer|exists:departments,id',
            'subject_ids' => 'sometimes|array',
            'subject_ids.*' => 'exists:subjects,id',
        ]);

        // Effective values: submitted changes merged over the section's current state
        $effectiveYearLevel = $validated['year_level'] ?? $section->year_level;
        $effectiveSemester = $validated['semester_name'] ?? $section->semester_name;
        $effectiveDepartmentId = array_key_exists('department_id', $validated)
            ? ($validated['department_id'] === null ? null : (int) $validated['department_id'])
            : ($section->department_id === null ? null : (int) $section->department_id);

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

        // Ownership is checked on the merged state too. Moving a section to
        // another department must be refused when it still holds a major of the
        // old one — otherwise the rule could be bypassed by editing the section
        // instead of its subject list.
        $departmentChanged = array_key_exists('department_id', $validated) && $effectiveDepartmentId !== ($section->department_id === null ? null : (int) $section->department_id);

        if (isset($validated['subject_ids']) || $departmentChanged) {
            $candidateIds = $validated['subject_ids'] ?? $section->subjects()->pluck('subjects.id')->all();

            $conflicts = $this->subjectDepartmentConflicts(
                $validated['name'] ?? $section->name,
                $effectiveDepartmentId,
                $candidateIds
            );
            if (!empty($conflicts)) {
                return response()->json([
                    'message' => 'Some subjects do not belong to this section\'s department: '
                        . implode(' ', $conflicts),
                    'errors' => ['subject_ids' => $conflicts],
                ], 422);
            }
        }

        $section->update(collect($validated)->except('subject_ids')->toArray());

        if (isset($validated['subject_ids'])) {
            $section->subjects()->sync($validated['subject_ids']);
        }

        return response()->json($section->load(['department', 'subjects.department']));
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
 * Department ownership: a section may only be assigned subjects that are shared
 * / minor (no owning department) or owned by the section's own department. The
 * check runs both when the subject list changes and when the section's
 * department changes, so neither edit can leave an invalid pairing behind.
 *
 * Also cleans AM/PM time input before validation and returns JSON responses.
 */