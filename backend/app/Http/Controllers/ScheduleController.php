<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\ScheduleSession;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\ScheduleGenerationLog;

class ScheduleController extends Controller
{
    public function generate(Section $section)
    {
        // 👇 NEW: Archive old drafts so they don't pile up
        Schedule::where('section_id', $section->id)
            ->where('status', 'draft')
            ->update(['status' => 'archived']);

        // Call the Python AI engine
        $response = Http::timeout(30)->post(
            "http://127.0.0.1:8001/generate-schedule/{$section->id}"
        );

        if ($response->failed()) {
            ScheduleGenerationLog::create([
                'section_id' => $section->id,
                'requested_by' => auth()->id(),
                'status' => 'failure',
                'message' => 'AI engine request failed: ' . $response->body(),
                'unscheduled_sessions' => null,
            ]);

            return response()->json([
                'error' => 'AI engine request failed',
                'details' => $response->body(),
            ], 502);
        }

        $result = $response->json();

        if (!in_array($result['status'], ['OPTIMAL', 'PARTIAL'])) {
            ScheduleGenerationLog::create([
                'section_id' => $section->id,
                'requested_by' => auth()->id(),
                'status' => 'failure',
                'message' => $result['message'] ?? 'No feasible schedule found.',
                'unscheduled_sessions' => $result['sessions'] ?? [],
            ]);

            return response()->json([
                'status' => $result['status'],
                'message' => $result['message'] ?? 'No feasible schedule found.',
            ], 422);
        }

        $scheduled = array_filter($result['sessions'], fn ($s) => $s['is_scheduled'] === true);
        $unscheduled = array_filter($result['sessions'], fn ($s) => $s['is_scheduled'] === false);

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

        ScheduleGenerationLog::create([
            'section_id' => $section->id,
            'requested_by' => auth()->id(),
            'status' => strtolower($result['status']),
            'message' => count($unscheduled) > 0
                ? count($unscheduled) . ' session(s) could not be scheduled.'
                : 'All sessions scheduled successfully.',
            'unscheduled_sessions' => array_values($unscheduled),
        ]);

        return response()->json([
            'schedule_id' => $schedule->id,
            'status' => $result['status'],
            'sessions' => $schedule->sessions()->with(['subject', 'faculty', 'room'])->get(),
            'unscheduled' => array_values($unscheduled),
        ]);
    }
}
