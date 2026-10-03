<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\ScheduleSession;
use App\Models\Section;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\ScheduleGenerationLog;

class ScheduleController extends Controller
{
    public function generate(Section $section)
    {
        // Pre-scheduling data-integrity gate: every subject assigned to the
        // section must match the section's year level and semester. Blocks
        // legacy/invalid assignments before the AI engine is ever called.
        $mismatches = [];
        foreach ($section->subjects as $subject) {
            if ((int) $subject->year_level !== (int) $section->year_level
                || $subject->semester_name !== $section->semester_name) {
                $problems = [];
                if ((int) $subject->year_level !== (int) $section->year_level) {
                    $problems[] = "belongs to Year {$subject->year_level}";
                }
                if ($subject->semester_name !== $section->semester_name) {
                    $problems[] = "belongs to {$subject->semester_name}";
                }
                $mismatches[] = sprintf(
                    '%s %s; this section is Year %d, %s.',
                    $subject->code,
                    implode(' and ', $problems),
                    $section->year_level,
                    $section->semester_name
                );
            }
        }

        if (!empty($mismatches)) {
            return response()->json([
                'message' => 'Schedule generation blocked: some assigned subjects do not match this section\'s year level and semester. Fix the section\'s subject assignments first. '
                    . implode(' ', $mismatches),
                'errors' => ['subject_ids' => $mismatches],
            ], 422);
        }

        // Call the Python AI engine. The section's existing draft is left
        // alone until we have a usable result: archiving up front meant a
        // failed or infeasible attempt replaced the draft with nothing, so a
        // transient engine outage silently destroyed the admin's work.
        //
        // A connection failure (engine not
        // running, port closed) throws instead of returning a response, so it
        // is handled explicitly: the attempt is logged and reported as 502.
        try {
            $response = Http::timeout(30)->post(
                "http://127.0.0.1:8001/generate-schedule/{$section->id}"
            );
        } catch (ConnectionException $e) {
            ScheduleGenerationLog::create([
                'section_id' => $section->id,
                'requested_by' => auth()->id(),
                'status' => 'failure',
                'message' => 'AI engine unreachable: ' . $e->getMessage(),
                'unscheduled_sessions' => null,
            ]);

            return response()->json([
                'error' => 'AI engine unreachable',
                'details' => $e->getMessage(),
            ], 502);
        }

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

        // FEASIBLE means every session was placed but the solver hit its time
        // budget before proving optimality (scheduler.py returns StatusName()
        // once scheduled_count === total), so it is a successful result and
        // must be treated like OPTIMAL/PARTIAL rather than as a failure.
        if (!in_array($result['status'], ['OPTIMAL', 'FEASIBLE', 'PARTIAL'])) {
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

        // Supersede the previous draft only now that the new one is real.
        Schedule::where('section_id', $section->id)
            ->where('status', 'draft')
            ->update(['status' => 'archived']);

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
                // The engine returns wall-clock HH:MM, which is the only form
                // that preserves a half-hour start such as 07:30. The old
                // sprintf('%02d:00', ...) rebuilt the time from whole hours and
                // would have rounded a 7:30 AM session to 7:00 AM.
                'start_time' => $session['start_time'] ?? sprintf('%02d:00', $session['start_hour']),
                'end_time' => $session['end_time'] ?? sprintf('%02d:00', $session['end_hour']),
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

/**
 * ScheduleController
 *
 * Generates a draft schedule for a section:
 * - archives old draft schedules
 * - calls the Python AI engine
 * - saves scheduled sessions
 * - logs success or failure and unscheduled sessions
 */