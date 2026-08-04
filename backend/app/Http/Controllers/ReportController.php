<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\Room;
use App\Models\ScheduleSession;
use App\Models\ScheduleGenerationLog;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function facultyWorkload()
    {
        $faculties = Faculty::with('user')->get()->map(function ($faculty) {
            $hours = ScheduleSession::where('faculty_id', $faculty->id)
                ->whereHas('schedule', fn ($q) => $q->where('status', 'published'))
                ->get()
                ->sum(fn ($session) => (
                    strtotime($session->end_time) - strtotime($session->start_time)
                ) / 3600);

            return [
                'faculty_id' => $faculty->id,
                'name' => $faculty->user->name,
                'faculty_type' => $faculty->faculty_type,
                'assigned_hours' => round($hours, 1),
                'max_teaching_load' => $faculty->max_teaching_load,
                'utilization_percent' => $faculty->max_teaching_load > 0
                    ? round(($hours / $faculty->max_teaching_load) * 100, 1)
                    : null,
            ];
        });

        return response()->json($faculties);
    }

    public function roomUtilization()
    {
        $rooms = Room::all()->map(function ($room) {
            $hours = ScheduleSession::where('room_id', $room->id)
                ->whereHas('schedule', fn ($q) => $q->where('status', 'published'))
                ->get()
                ->sum(fn ($session) => (
                    strtotime($session->end_time) - strtotime($session->start_time)
                ) / 3600);

            return [
                'room_id' => $room->id,
                'name' => $room->name,
                'type' => $room->type,
                'capacity' => $room->capacity,
                'booked_hours_per_week' => round($hours, 1),
            ];
        });

        return response()->json($rooms);
    }

    public function conflicts(Request $request)
    {
        $logs = ScheduleGenerationLog::with(['section', 'requestedBy'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'section' => $log->section->name ?? 'Unknown',
                    'status' => $log->status,
                    'message' => $log->message,
                    'unscheduled_sessions' => $log->unscheduled_sessions,
                    'requested_by' => $log->requestedBy->name ?? 'Unknown',
                    'created_at' => $log->created_at,
                ];
            });

        return response()->json($logs);
    }
}


/**
 * ReportController
 *
 * Provides reporting endpoints:
 * - facultyWorkload(): calculate published workload per faculty
 * - roomUtilization(): calculate weekly booked hours per room
 * - conflicts(): list schedule generation logs and unscheduled session reasons
 */