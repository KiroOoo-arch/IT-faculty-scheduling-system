<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\ScheduleSession;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ScheduleController extends Controller
{
    public function generate(Section $section)
    {
        // Call the Python AI engine
        $response = Http::timeout(30)->post(
            "http://127.0.0.1:8001/generate-schedule/{$section->id}"
        );

        if ($response->failed()) {
            return response()->json([
                'error' => 'AI engine request failed',
                'details' => $response->body(),
            ], 502);
        }

        $result = $response->json();

        // Accept both OPTIMAL and PARTIAL as save-worthy outcomes.
        // Anything else (e.g. INFEASIBLE, ERROR) means nothing could be scheduled.
        if (!in_array($result['status'], ['OPTIMAL', 'PARTIAL'])) {
            return response()->json([
                'status' => $result['status'],
                'message' => $result['message'] ?? 'No feasible schedule found.',
            ], 422);
        }

        // Split sessions into scheduled vs. unscheduled
        $scheduled = array_filter($result['sessions'], fn ($s) => $s['is_scheduled'] === true);
        $unscheduled = array_filter($result['sessions'], fn ($s) => $s['is_scheduled'] === false);

        // Persist only the successfully scheduled sessions
        $schedule = Schedule::create([
            'section_id' => $section->id,
            'status' => 'draft',
        ]);

        foreach ($scheduled as $session) {
            ScheduleSession::create([
                'schedule_id' => $schedule->id,
                'subject_id' => $session['subject_id'],
                'faculty_id' => $session['faculty_id'],
                'room_id' => $session['room_id'],
                'session_type' => $session['session_type'],
                'day_of_week' => $session['day_of_week'],
                'start_time' => sprintf('%02d:00', $session['start_hour']),
                'end_time' => sprintf('%02d:00', $session['end_hour']),
            ]);
        }

        return response()->json([
            'schedule_id' => $schedule->id,
            'status' => $result['status'],
            'sessions' => $schedule->sessions()->with(['subject', 'faculty', 'room'])->get(),
            'unscheduled' => array_values($unscheduled),
        ]);
    }
}