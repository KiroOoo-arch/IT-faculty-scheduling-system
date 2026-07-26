<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MyScheduleController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $faculty = $user->faculty; // requires a User -> Faculty relationship

        if (!$faculty) {
            return response()->json(['message' => 'No faculty record linked to this user.'], 404);
        }

        $sessions = $faculty->scheduleSessions()
            ->with(['subject', 'room', 'schedule'])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'faculty' => [
                'name' => $user->name,
                'faculty_type' => $faculty->faculty_type,
            ],
            'sessions' => $sessions,
        ]);
    }
}