<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Protects the published timetable from silent data loss.
 *
 * Deleting a room, subject or section clears the schedule sessions that
 * reference it (RESTRICT foreign keys would otherwise 500). That is fine for
 * drafts and archives, but a published schedule is the timetable of record —
 * quietly dropping one of its sessions produces a schedule that is simply
 * wrong. These helpers let a controller refuse the delete with 409 and the
 * exact scope of the loss, so the client can confirm and retry with ?force=1.
 *
 * The reported count is deliberately the *total* number of sessions that would
 * disappear, not just the published ones: deleting a room drops every session
 * that used it, so reporting only the published subset would understate the
 * loss by an order of magnitude (e.g. 1 published out of 29 total).
 */
trait GuardsPublishedReferences
{
    /**
     * @param  array<int,int>  $scheduleIds       published schedules that still depend on the record
     * @param  int             $totalSessions     every session the delete would remove
     * @param  int             $publishedSessions how many of those sit in a published schedule
     */
    protected function publishedReferenceConflict(
        Request $request,
        array $scheduleIds,
        int $totalSessions,
        int $publishedSessions
    ): ?JsonResponse {
        // An explicit second confirmation has already been given.
        if ($request->boolean('force') || empty($scheduleIds)) {
            return null;
        }

        $scheduleList = implode(', ', array_map(fn ($id) => "#{$id}", $scheduleIds));

        return response()->json([
            'message' => sprintf(
                'This record is still used by published schedule%s %s. Deleting it '
                    . 'would remove %d session%s in total, %d of them from the published timetable.',
                count($scheduleIds) === 1 ? '' : 's',
                $scheduleList,
                $totalSessions,
                $totalSessions === 1 ? '' : 's',
                $publishedSessions
            ),
            'requires_confirmation' => true,
            'published_schedule_ids' => array_values($scheduleIds),
            'sessions_at_risk' => $totalSessions,
            'published_sessions_at_risk' => $publishedSessions,
        ], 409);
    }
}
