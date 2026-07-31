<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use Illuminate\Http\Request;

class FacultyController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id'           => 'required|exists:users,id',
            'faculty_type'      => 'required|string|in:full_time,part_time',
            'max_teaching_load' => 'required|integer|min:1',
        ]);

        $faculty = Faculty::create($validated);
        return response()->json($faculty, 201);
    }

    public function update(Request $request, Faculty $faculty)
    {
        $validated = $request->validate([
            'user_id'           => 'required|exists:users,id',
            'faculty_type'      => 'required|string|in:full_time,part_time',
            'max_teaching_load' => 'required|integer|min:1',
        ]);

        $faculty->update($validated);
        return response()->json($faculty);
    }

    public function destroy(Faculty $faculty)
    {
        $faculty->delete();
        return response()->json(['message' => 'Faculty deleted successfully']);
    }
}
