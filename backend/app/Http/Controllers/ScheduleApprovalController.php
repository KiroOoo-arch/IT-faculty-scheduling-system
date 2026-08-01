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

        // Archive old published schedules for this section
        Schedule::where('section_id', $schedule->section_id)
            ->where('id', '!=', $schedule->id)
            ->where('status', 'published')
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

    private function authorizeAdmin(Request $request): void
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Only the Department Head can approve, publish, or reject schedules.');
        }
    }
}
