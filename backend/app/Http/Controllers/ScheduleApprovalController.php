<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FormatsTimes;
use App\Models\Faculty;
use App\Models\Schedule;
use App\Models\ScheduleSession;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Concerns\WorkloadMerging;

/** 
 * ScheduleApprovalController
 * 
 * Manages schedule review and approval flow:
 * - index(): list schedules for review
 * - show(): view a single schedule
 * - approve(): approve a draft schedule
 * - publish(): publish an approved schedule
 * - unpublish(): revert published schedule to draft
 * - reject(): reject a draft schedule
 * - destroy(): delete any unpublished schedule (published must be unpublished first)
 *
 * Also checks for conflicts before publishing and restricts actions to admins.
 */
class ScheduleApprovalController extends Controller
{
    use FormatsTimes;

    /** 
     * List all schedules with their sessions, for review.
     * GET /api/schedules
     * 
     * Query parameters:
     * - academic_year: filter by academic year (e.g., 2026-2027)
     * - semester_name: filter by semester (e.g., 1st Semester, 2nd Semester)
     * - status: filter by schedule status (draft, approved, published)
     * - show_archived: include archived schedules when 'true'
     */
    public function index(Request $request)
    {
        $query = Schedule::with(['section', 'sessions.subject', 'sessions.faculty', 'sessions.room'])
            ->orderByDesc('created_at');

        // Academic year filter
        if ($request->has('academic_year')) {
            $query->whereHas('section', function ($q) use ($request) {
                $q->where('academic_year', $request->query('academic_year'));
            });
        }

        // Semester name filter
        if ($request->has('semester_name')) {
            $query->whereHas('section', function ($q) use ($request) {
                $q->where('semester_name', $request->query('semester_name'));
            });
        }

        // Status filter
        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->query('show_archived') !== 'true') {
            $query->where('status', '!=', 'archived');
        }

        return response()->json($query->get());
    }

    /** 
     * View a single schedule in detail.
     * GET /api/schedules/{schedule}
     */
    public function show(Schedule $schedule)
    {
        $schedule->load(['section', 'sessions.subject', 'sessions.faculty', 'sessions.room']);
        return response()->json($schedule);
    }

    /** 
     * Approve a draft schedule. Admin only.
     * PATCH /api/schedules/{schedule}/approve
     */
    public function approve(Request $request, Schedule $schedule)
    {
        $this->authorizeAdmin($request);

        if ($schedule->status !== 'draft') {
            return response()->json([
                'message' => "Only draft schedules can be approved. This schedule is currently '{$schedule->status}'.",
            ], 422);
        }

        $schedule->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json($schedule->load(['section', 'sessions']));
    }

    /** 
     * Publish an approved schedule, making it visible to faculty. Admin only.
     * PATCH /api/schedules/{schedule}/publish
     */
    public function publish(Request $request, Schedule $schedule)
    {
        $this->authorizeAdmin($request);

        if ($schedule->status !== 'approved') {
            return response()->json([
                'message' => "Only approved schedules can be published. This schedule is currently '{$schedule->status}'.",
            ], 422);
        }

        // The sections table stores academic_year and semester_name as
        // non-nullable strings and SectionController validates both as
        // required, so a schedule without a fully-termed section cannot be
        // published. Rejecting here prevents the term filters below from being
        // skipped (which would silently count schedules from unrelated terms).
        $section = $schedule->section;
        if (! $section
            || $section->academic_year === null || $section->academic_year === ''
            || $section->semester_name === null || $section->semester_name === '') {
            return response()->json([
                'message' => "Cannot publish: the schedule's section is missing required "
                    . 'academic-year or semester data.',
            ], 422);
        }

        $this->checkFacultyTeachingLoad($schedule);

        $conflicts = $this->findPublishConflicts($schedule);
        if (!empty($conflicts)) {
            return response()->json([
                'message' => 'Cannot publish: this schedule conflicts with another approved/published schedule.',
                'conflicts' => $conflicts,
            ], 422);
        }

        // Archive the section's superseded schedules and publish the new one
        // atomically so a failure mid-way cannot leave the section with two
        // live schedules or none.
        DB::transaction(function () use ($schedule) {
            Schedule::where('section_id', $schedule->section_id)
                ->where('id', '!=', $schedule->id)
                ->whereIn('status', ['published', 'approved'])
                ->update(['status' => 'archived']);

            $schedule->update(['status' => 'published']);
        });

        return response()->json($schedule->load(['section', 'sessions']));
    }

    /** 
     * Unpublish a schedule — revert to draft for editing.
     * Only allowed for admin role.
     * PATCH /api/schedules/{schedule}/unpublish
     */
    public function unpublish(Request $request, Schedule $schedule)
    {
        $this->authorizeAdmin($request);

        if ($schedule->status !== 'published') {
            return response()->json([
                'message' => 'Only published schedules can be unpublished.'
            ], 422);
        }

        $schedule->update([
            'status' => 'draft',
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return response()->json([
            'message' => 'Schedule unpublished and reverted to draft.',
            'schedule' => $schedule->load(['section', 'sessions']),
        ]);
    }

    /** 
     * Reject a draft schedule. Admin only.
     * PATCH /api/schedules/{schedule}/reject
     */
    public function reject(Request $request, Schedule $schedule)
    {
        $this->authorizeAdmin($request);

        if ($schedule->status !== 'draft') {
            return response()->json([
                'message' => "Only draft schedules can be rejected. This schedule is currently '{$schedule->status}'.",
            ], 422);
        }

        $schedule->update(['status' => 'rejected']);

        return response()->json($schedule);
    }

    /** 
     * Delete a schedule. Admin only.
     * DELETE /api/schedules/{schedule}
     * 
     * The one protected state is `published` — the schedule being distributed —
     * so it must be unpublished first, which keeps taking a live timetable off
     * the screen an explicit two-step act.
     * 
     * Every other state (draft, approved, rejected, archived) is a record
     * nothing is relying on yet, so it is deletable. `approved` matters most in
     * practice: an approved schedule is visible to publish-time conflict checks
     * for other sections, so discarding a stale one is how an admin clears a
     * clash without hand-editing sessions.
     */
    public function destroy(Request $request, Schedule $schedule)
    {
        $this->authorizeAdmin($request);

        if ($schedule->status === 'published') {
            return response()->json([
                'message' => "Only unpublished schedules can be deleted. "
                    . "This schedule is currently 'published' — unpublish it first.",
            ], 422);
        }

        $schedule->sessions()->delete();
        $schedule->delete();

        return response()->json(['message' => 'Schedule deleted successfully']);
    }

    /** 
     * Check if this schedule's sessions collide with other approved/published
     * schedules from DIFFERENT sections.
     */
    private function findPublishConflicts(Schedule $schedule): array
    {
        $conflicts = [];

        // The term lives on the section, not on the schedule: reading it off the
        // schedule yielded null, and `where('academic_year', null)` compiled to
        // `whereNull`, so every other section was filtered out and no conflict
        // could ever be reported.
        $section = $schedule->section;
        $academicYear = $section?->academic_year;
        $semester = $section?->semester_name;

        $others = ScheduleSession::with('schedule.section', 'room')
            ->where('schedule_id', '!=', $schedule->id)
            ->whereHas('schedule', function ($q) use ($schedule, $academicYear, $semester) {
                $q->where('section_id', '!=', $schedule->section_id)
                  ->whereIn('status', ['approved', 'published']);

                if ($academicYear !== null && $semester !== null) {
                    $q->whereHas('section', function ($s) use ($academicYear, $semester) {
                        $s->where('academic_year', $academicYear)
                          ->where('semester_name', $semester);
                    });
                }
            })
            ->get();

        foreach ($schedule->sessions as $a) {
            foreach ($others as $b) {
                if ($a->day_of_week != $b->day_of_week) continue;
                if (!($a->start_time < $b->end_time && $b->start_time < $a->end_time)) continue;

                if ($a->room_id === $b->room_id) {
                    $conflicts[] = "Room '{$b->room->name}' is booked day {$a->day_of_week} "
                        . "{$this->twelveHourRange($a->start_time, $a->end_time)} "
                        . "(schedule #{$b->schedule_id}, section {$b->schedule->section->name}).";
                }
                if ($a->faculty_id === $b->faculty_id) {
                    $conflicts[] = "Faculty is already teaching day {$a->day_of_week} "
                        . "{$this->twelveHourRange($a->start_time, $a->end_time)} "
                        . "(schedule #{$b->schedule_id}).";
                }
            }
        }

        return array_unique($conflicts);
    }

    private function authorizeAdmin(Request $request): void
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Only the Department Head can approve, publish, or reject schedules.');
        }
    }

    /**
     * Refuse publication when the schedule would push one of its faculty past
     * their configured `max_teaching_load`.
     *
     * Both sides of the comparison use the same unit: the existing workload is
     * merged minutes per weekday across the term's other approved/published
     * schedules, the proposed load is this schedule's minutes for the same
     * faculty, and only the total is converted to hours because
     * `max_teaching_load` is stored in hours.
     */
    private function checkFacultyTeachingLoad(Schedule $schedule): void
    {
        $targetFaculties = $schedule->sessions->pluck('faculty_id')->filter()->unique();

        foreach ($targetFaculties as $facultyId) {
            $faculty = Faculty::find($facultyId);
            if (! $faculty) {
                continue;
            }

            // `max_teaching_load` is stored in hours; everything calculated here
            // is minutes, so the conversion happens only for this comparison.
            $maxLoad = (float) $faculty->max_teaching_load;
            if ($maxLoad <= 0) {
                continue;
            }

            // Workload from OTHER approved/published schedules in the same term.
            $existingMinutes = $this->calculateExistingWorkloadMinutes($faculty, $schedule);

            // Minutes this schedule adds for the same faculty member, merged
            // with the same per-weekday rules as the existing workload and the
            // report so overlapping or adjacent sessions are not double-counted.
            $proposedSessions = $schedule->sessions->filter(
                fn ($session) => (int) $session->faculty_id === (int) $facultyId
            );
            $proposedMinutes = $this->mergedMinutesByDay($proposedSessions);

            $totalHours = ($existingMinutes + $proposedMinutes) / 60;

            if ($totalHours > $maxLoad) {
                throw new HttpResponseException(response()->json([
                    'message' => "Cannot publish: Faculty '{$faculty->display_name}' would exceed teaching load. "
                        . 'Existing: '.round($existingMinutes / 60, 1).'h, '
                        . 'Proposed: '.round($proposedMinutes / 60, 1).'h, '
                        . 'Total: '.round($totalHours, 1).'h, '
                        . "Maximum: {$maxLoad}h.",
                    ], 422));
            }
        }
    }

    /**
     * Calculate total unique occupied minutes from OTHER approved/published schedules
     * for a faculty member in the same academic year and semester, excluding the
     * target schedule's sessions.
     * Does NOT merge intervals across different weekdays.
     */
    private function calculateExistingWorkloadMinutes(Faculty $faculty, Schedule $excludeSchedule): int
    {
        $section = $excludeSchedule->section;
        $academicYear = $section?->academic_year;
        $semester = $section?->semester_name;

        // publish() archives every other published/approved schedule in the
        // target's own section before marking the new one published, so those
        // schedules' sessions will not exist in the post-publish state and must
        // not count against the load. Excluding them here lets a timetable be
        // replaced without its own superseded load blocking the replacement.
        $archivedScheduleIds = $section
            ? Schedule::where('section_id', $section->id)
                ->whereIn('status', ['published', 'approved'])
                ->where('id', '!=', $excludeSchedule->id)
                ->pluck('id')
            : collect();

        $sessions = ScheduleSession::where('faculty_id', $faculty->id)
            ->whereNotIn('schedule_id', $archivedScheduleIds)
            ->whereHas('schedule', function ($q) use ($excludeSchedule, $academicYear, $semester) {
                // `schedules` is keyed by `id`; the table has no `schedule_id`.
                $q->where('id', '!=', $excludeSchedule->id)
                  ->whereIn('status', ['approved', 'published']);

                if ($academicYear !== null && $semester !== null) {
                    $q->whereHas('section', function ($s) use ($academicYear, $semester) {
                        $s->where('academic_year', $academicYear)
                          ->where('semester_name', $semester);
                    });
                }
            })
            ->get();

        return $this->mergedMinutesByDay($sessions);
    }

}
