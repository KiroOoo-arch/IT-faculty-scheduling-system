<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleSession;
use App\Models\ScheduleGenerationLog;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function facultyWorkload()
    {
        // Eager-load subjects: without this, reading them per row would fire one
        // query per faculty member.
        $faculties = Faculty::with('subjects')->get()->map(function ($faculty) {
            $hours = ScheduleSession::where('faculty_id', $faculty->id)
                ->whereHas('schedule', fn ($q) => $q->where('status', 'published'))
                ->get()
                ->sum(fn ($session) => (
                    strtotime($session->end_time) - strtotime($session->start_time)
                ) / 3600);

            return [
                'faculty_id' => $faculty->id,
                'name' => $faculty->name ?? ($faculty->user->name ?? 'Unknown Faculty'),
                'faculty_type' => $faculty->faculty_type,
                'assigned_hours' => round($hours, 1),
                'max_teaching_load' => $faculty->max_teaching_load,
                'utilization_percent' => $faculty->max_teaching_load > 0
                    ? round(($hours / $faculty->max_teaching_load) * 100, 1)
                    : null,
                'subjects' => $faculty->subjects->pluck('code')->values(),
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
            ->get();

        // Resolve every subject the logs refer to once, so the admin sees
        // "GE 1 - Physics (Lecture)" instead of a bare subject_id. Done here
        // rather than in the solver: naming subjects is presentation, and it
        // also repairs logs written before this change.
        $subjectIds = $logs
            ->flatMap(fn ($log) => collect($log->unscheduled_sessions ?? [])->pluck('subject_id'))
            ->filter()
            ->unique()
            ->values();

        $subjects = Subject::whereIn('id', $subjectIds)->get()->keyBy('id');

        return response()->json(
            $logs->map(function ($log) use ($subjects) {
                $unscheduled = collect($log->unscheduled_sessions ?? [])
                    ->map(function ($session) use ($subjects) {
                        // Legacy rows may hold a plain string; leave those alone.
                        if (! is_array($session)) {
                            return $session;
                        }

                        $subject = $subjects->get($session['subject_id'] ?? null);

                        return array_merge($session, [
                            'subject_code' => $subject->code ?? null,
                            'subject_title' => $subject->title ?? null,
                        ]);
                    })
                    ->values();

                return [
                    'id' => $log->id,
                    'section' => $log->section->name ?? 'Unknown',
                    'status' => $log->status,
                    'message' => $log->message,
                    'unscheduled_sessions' => $unscheduled,
                    'requested_by' => $log->requestedBy->name ?? 'Unknown',
                    'created_at' => $log->created_at,
                ];
            })
        );
    }

    /**
     * Clear the generation-log history. Admin only (route middleware).
     * DELETE /api/reports/conflicts
     *
     * The history is an audit convenience, not the timetable itself, so it can
     * be wiped without touching any schedule, session, or master data.
     */
    public function clearGenerationLogs()
    {
        $deleted = ScheduleGenerationLog::query()->delete();

        return response()->json([
            'message' => "Cleared {$deleted} generation log(s).",
            'deleted' => $deleted,
        ]);
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
     * Faculty Schedule — every PUBLISHED session taught by one faculty member,
     * with the section, room and times needed to print a personal schedule.
     *
     * GET /api/reports/faculty/{faculty}/schedule
     *
     * Only published schedules are included: a draft is still editable and an
     * approved one is not yet the timetable students and faculty are given, so
     * handing either out would distribute something that can still change.
     */
    public function facultySchedule(Faculty $faculty)
    {
        $sessions = ScheduleSession::with(['subject', 'room', 'schedule.section'])
            ->where('faculty_id', $faculty->id)
            ->whereHas('schedule', fn ($q) => $q->where('status', 'published'))
            ->get()
            ->map(function ($session) {
                $section = $session->schedule->section ?? null;

                return [
                    'id' => $session->id,
                    'day_of_week' => $session->day_of_week,
                    'start_time' => substr($session->start_time, 0, 5),
                    'end_time' => substr($session->end_time, 0, 5),
                    'session_type' => $session->session_type,
                    'subject_code' => $session->subject->code ?? '—',
                    'subject_title' => $session->subject->title ?? '',
                    'room' => $session->room->name ?? '—',
                    'section' => $section->name ?? '—',
                    'section_year_level' => $section->year_level ?? null,
                    'semester_name' => $section->semester_name ?? null,
                    'academic_year' => $section->academic_year ?? null,
                    'hours' => round((
                        (strtotime($session->end_time) - strtotime($session->start_time)) / 3600
                    ), 1),
                ];
            })
            // Sort by day then start, so the printed sheet reads like a week.
            ->sortBy([['day_of_week', 'asc'], ['start_time', 'asc']])
            ->values();

        $perDay = [];
        foreach ($sessions as $s) {
            $perDay[$s['day_of_week']] = ($perDay[$s['day_of_week']] ?? 0) + $s['hours'];
        }

        return response()->json([
            'faculty' => [
                'id' => $faculty->id,
                'name' => $faculty->display_name,
                'faculty_type' => $faculty->faculty_type,
                'max_teaching_load' => $faculty->max_teaching_load,
            ],
            'sessions' => $sessions,
            'total_hours' => round($sessions->sum('hours'), 1),
            'hours_per_day' => $perDay,
            'distinct_sections' => $sessions->pluck('section')->unique()->values(),
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
 * - facultySchedule(): one faculty member's published sessions, for printing
 * - roomUtilization(): calculate weekly booked hours per room
 * - conflicts(): list schedule generation logs and unscheduled session reasons
 */