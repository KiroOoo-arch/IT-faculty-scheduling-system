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
}