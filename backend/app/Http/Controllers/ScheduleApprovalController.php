<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\ScheduleSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ScheduleApprovalController extends Controller
{
    /**
     * List all schedules with their sessions, for review.
     * GET /api/schedules
     */
    public function index(Request $request)
    {
        $query = Schedule::with(['section', 'sessions.subject', 'sessions.faculty.user', 'sessions.room'])
            ->orderByDesc('created_at');

        // 👇 Hide archived by default unless ?show_archived=true
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
        $schedule->load(['section', 'sessions.subject', 'sessions.faculty.user', 'sessions.room']);

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

        // NEW: reject if it conflicts with other approved/published schedules
        $conflicts = $this->findPublishConflicts($schedule);
        if (!empty($conflicts)) {
            return response()->json([
                'message' => 'Cannot publish: this schedule conflicts with another approved/published schedule.',
                'conflicts' => $conflicts,
            ], 422);
        }

        // Archive old published/approved schedules for this section
        Schedule::where('section_id', $schedule->section_id)
            ->where('id', '!=', $schedule->id)
            ->whereIn('status', ['published', 'approved'])
            ->update(['status' => 'archived']);

        $schedule->update(['status' => 'published']);

        return response()->json($schedule->load(['section', 'sessions']));
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
     * Delete a schedule. Admin only. Only drafts and archived can be deleted.
     * DELETE /api/schedules/{schedule}
     */
    public function destroy(Request $request, Schedule $schedule)
    {
        $this->authorizeAdmin($request);

        if (!in_array($schedule->status, ['draft', 'archived'])) {
            return response()->json([
                'message' => "Only draft or archived schedules can be deleted.",
            ], 422);
        }

        $schedule->sessions()->delete();
        $schedule->delete();

        return response()->json(['message' => 'Schedule deleted successfully']);
    }

    /**
     * Check if this schedule's sessions collide with other approved/published
     * schedules from DIFFERENT sections (same room or same faculty overlap).
     */
    private function findPublishConflicts(Schedule $schedule): array
    {
        $conflicts = [];

        $others = ScheduleSession::with('schedule.section', 'room')
            ->where('schedule_id', '!=', $schedule->id)
            ->whereHas('schedule', function ($q) use ($schedule) {
                $q->where('section_id', '!=', $schedule->section_id)
                  ->whereIn('status', ['approved', 'published']);
            })
            ->get();

        foreach ($schedule->sessions as $a) {
            foreach ($others as $b) {
                if ($a->day_of_week != $b->day_of_week) {
                    continue;
                }
                if (!($a->start_time < $b->end_time && $b->start_time < $a->end_time)) {
                    continue;
                }

                if ($a->room_id === $b->room_id) {
                    $conflicts[] = "Room '{$b->room->name}' is booked day {$a->day_of_week} "
                        . "{$a->start_time}–{$a->end_time} (schedule #{$b->schedule_id}, section {$b->schedule->section->name}).";
                }
                if ($a->faculty_id === $b->faculty_id) {
                    $conflicts[] = "Faculty is already teaching day {$a->day_of_week} "
                        . "{$a->start_time}–{$a->end_time} (schedule #{$b->schedule_id}).";
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
}


/**
 * ScheduleApprovalController
 *
 * Manages schedule review and approval flow:
 * - index(): list schedules for review
 * - show(): view a single schedule
 * - approve(): approve a draft schedule
 * - publish(): publish an approved schedule
 * - reject(): reject a draft schedule
 * - destroy(): delete draft or archived schedules
 *
 * Also checks for conflicts before publishing and restricts actions to admins.
 */