<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use Illuminate\Http\Request;

class FacultyController extends Controller
{
    public function index()
    {
        $faculties = Faculty::with(['user', 'subjects', 'availabilities'])->get();

        return response()->json($faculties);
    }

    public function show(Faculty $faculty)
    {
        $faculty->load(['user', 'subjects', 'availabilities']);

        return response()->json($faculty);
    }
}