<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    /**
     * Canonical lab room types a subject's lab sessions can require.
     * Mirrors frontend/src/constants/roomTypes.ts (LAB_ROOM_TYPES) so both
     * sides of the room-type-matching constraint share one vocabulary.
     */
    private const LAB_ROOM_TYPES = ['computer_lab', 'science_lab', 'electronics_lab'];

    /**
     * Validation rules shared by store() and update().
     */
    private function subjectRules(?Subject $subject = null): array
    {
        $uniqueCode = $subject
            ? 'required|string|unique:subjects,code,' . $subject->id
            : 'required|string|unique:subjects';

        return [
            'code'          => $uniqueCode,
            'title'         => 'required|string',
            'year_level'    => 'required|integer',
            'semester_name' => 'required|string',
            'lecture_hours' => 'required|integer|min:0',
            'lab_hours'     => 'required|integer|min:0',
            'lab_room_type' => [
                'nullable',
                'string',
                Rule::in(self::LAB_ROOM_TYPES),
            ],
            'is_active'     => 'boolean',
        ];
    }

    /**
     * Lab-hours / lab-room-type consistency check. Returns error strings
     * keyed to lab_room_type / lab_hours for the validation error bag:
     * - lab_hours > 0 requires a valid canonical lab_room_type;
     * - lab_hours = 0 requires lab_room_type to be null (lecture-only),
     *   matching the existing data model (see seeder MATH1) and the
     *   Subjects page rule.
     */
    private function labConsistencyErrors(array $data): array
    {
        $errors = [];

        if (($data['lab_hours'] ?? 0) > 0 && empty($data['lab_room_type'])) {
            $errors['lab_room_type'][] =
                'Lab room type is required when lab hours are greater than 0.';
        }

        if (($data['lab_hours'] ?? 0) == 0 && !empty($data['lab_room_type'])) {
            $errors['lab_hours'][] =
                'Lab room type must be empty when lab hours are 0 '
                . '(this subject has no laboratory component).';
        }

        return $errors;
    }

    public function index()
    {
        $subjects = Subject::with('faculties')->get();
        return response()->json($subjects);
    }

    public function show(Subject $subject)
    {
        $subject->load('faculties');
        return response()->json($subject);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->subjectRules());

        $errors = $this->labConsistencyErrors($validated);
        if (!empty($errors)) {
            return response()->json([
                'message' => reset($errors),
                'errors'  => $errors,
            ], 422);
        }

        $subject = Subject::create($validated);
        return response()->json($subject, 201);
    }

    public function update(Request $request, Subject $subject)
    {
        $validated = $request->validate($this->subjectRules($subject));

        $errors = $this->labConsistencyErrors($validated);
        if (!empty($errors)) {
            return response()->json([
                'message' => reset($errors),
                'errors'  => $errors,
            ], 422);
        }

        $subject->update($validated);
        return response()->json($subject);
    }

    public function destroy(Subject $subject)
    {
        $subject->delete();
        return response()->json(['message' => 'Subject deleted successfully']);
    }
}


/**
 * SubjectController
 *
 * This controller manages subject API operations:
 * - index(): list all subjects with their faculties
 * - show(): get one subject with its faculties
 * - store(): create a new subject (validates lab_hours/lab_room_type consistency)
 * - update(): edit an existing subject (same validation)
 * - destroy(): delete a subject
 *
 * Lab consistency rule: lab_hours > 0 requires one of the canonical lab
 * room types (computer_lab, science_lab, electronics_lab); lab_hours = 0
 * requires lab_room_type to be null. Violations return HTTP 422.
 */