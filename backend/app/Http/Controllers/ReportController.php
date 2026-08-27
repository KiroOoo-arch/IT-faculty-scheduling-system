<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleSession;
use App\Models\ScheduleGenerationLog;
use App\Models\Section;
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

    /**
     * Schedule Status Overview — count of schedules per status.
     */
    public function scheduleStatusOverview()
    {
        $statuses = Schedule::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->get();

        $total = $statuses->sum('count');

        return response()->json([
            'statuses' => $statuses,
            'total' => $total,
        ]);
    }

    /**
     * Section Summary — total sessions, hours, and faculty count per section.
     */
    public function sectionSummary()
    {
        $sections = Section::with('subjects')->get()->map(function ($section) {
            $sessions = ScheduleSession::whereHas('schedule', function ($q) use ($section) {
                $q->where('section_id', $section->id)
                  ->where('status', 'published');
            })->get();

            $totalSessions = $sessions->count();

            $totalHours = $sessions->sum(fn ($session) => (
                (strtotime($session->end_time) - strtotime($session->start_time)) / 3600
            ));

            $facultyCount = $sessions->pluck('faculty_id')->unique()->count();

            return [
                'section_id' => $section->id,
                'name' => $section->name,
                'year_level' => $section->year_level,
                'academic_year' => $section->academic_year,
                'semester_name' => $section->semester_name,
                'total_sessions' => $totalSessions,
                'total_hours' => round($totalHours, 1),
                'faculty_count' => $facultyCount,
                'subjects_count' => $section->subjects->count(),
            ];
        });

        return response()->json($sections);
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