<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
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
        $validated = $request->validate([
            'code'          => 'required|string|unique:subjects',
            'title'         => 'required|string',
            'year_level'    => 'required|integer',
            'semester_name' => 'required|string',
            'lecture_hours' => 'required|integer|min:0',
            'lab_hours'     => 'required|integer|min:0',
            'lab_room_type' => 'nullable|string',
            'is_active'     => 'boolean',
        ]);

        $subject = Subject::create($validated);
        return response()->json($subject, 201);
    }

    public function update(Request $request, Subject $subject)
    {
        $validated = $request->validate([
            'code'          => 'required|string|unique:subjects,code,' . $subject->id,
            'title'         => 'required|string',
            'year_level'    => 'required|integer',
            'semester_name' => 'required|string',
            'lecture_hours' => 'required|integer|min:0',
            'lab_hours'     => 'required|integer|min:0',
            'lab_room_type' => 'nullable|string',
            'is_active'     => 'boolean',
        ]);

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
 * - store(): create a new subject
 * - update(): edit an existing subject
 * - destroy(): delete a subject
 *
 * All methods return JSON responses and validate input before saving.
 */