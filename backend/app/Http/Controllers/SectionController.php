<?php

namespace App\Http\Controllers;

use App\Models\Section;
use Illuminate\Http\Request;

class SectionController extends Controller
{
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
