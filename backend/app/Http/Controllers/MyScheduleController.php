<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use Illuminate\Http\Request;

class MyScheduleController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $faculty = Faculty::where('user_id', $user->id)->first();

        if (!$faculty) {
            return response()->json(['sessions' => []]);
        }

        $sessions = \App\Models\ScheduleSession::where('faculty_id', $faculty->id)
            ->whereHas('schedule', function ($q) {          // ← ADD THIS
                $q->where('status', 'published');           // ← ONLY published schedules
            })
            ->with(['subject', 'room'])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        // Flatten schedule data for the frontend
        $flattened = $sessions->map(function ($session) {
            return [
                'id' => $session->id,
                'subject' => $session->subject,
                'room' => $session->room,
                'session_type' => $session->session_type,
                'day_of_week' => $session->day_of_week,
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
            ];
        });

        return response()->json(['sessions' => $flattened]);
    }
}
