<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ScheduleApprovalController extends Controller
{
    /**
     * List all schedules with their sessions, for review.
     * GET /api/schedules
     */
    public function index()
    {
        $schedules = Schedule::with(['section', 'sessions.subject', 'sessions.faculty.user', 'sessions.room'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($schedules);
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

        $schedule->update(['status' => 'published']);

        return response()->json($schedule->load(['section', 'sessions']));
    }

    /**
     * Reject a draft schedule (e.g. the AI's result wasn't good enough). Admin only.
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
     * Small helper: only allow admins (Department Head) to approve/publish/reject.
     */
    private function authorizeAdmin(Request $request): void
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Only the Department Head can approve, publish, or reject schedules.');
        }
    }
}