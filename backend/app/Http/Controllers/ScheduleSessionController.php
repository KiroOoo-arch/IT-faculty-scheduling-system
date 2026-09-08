<?php

namespace App\Http\Controllers;

use App\Models\ScheduleSession;
use App\Models\Faculty;
use App\Models\Room;
use Illuminate\Http\Request;

class ScheduleSessionController extends Controller
{
    /**
     * Manually update a session's day/time/room/faculty. Admin only.
     * Validates that the change doesn't create a new conflict.
     * PUT /api/schedules/sessions/{session}
     */
    public function update(Request $request, ScheduleSession $session)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Only the Department Head can edit schedule sessions.');
        }

        $schedule = $session->schedule;
        if (!in_array($schedule->status, ['draft', 'approved'])) {
            return response()->json([
                'message' => "Cannot edit a session on a '{$schedule->status}' schedule.",
            ], 422);
        }

        $validated = $request->validate([
            'day_of_week' => 'sometimes|integer|between:1,7',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'room_id' => 'sometimes|exists:rooms,id',
            'faculty_id' => 'sometimes|exists:faculties,id',
        ]);

        // Merge proposed changes onto current values, so we validate the FULL
        // resulting state, not just the fields that were sent.
        $proposed = [
            'day_of_week' => $validated['day_of_week'] ?? $session->day_of_week,
            'start_time' => $validated['start_time'] ?? $session->start_time,
            'end_time' => $validated['end_time'] ?? $session->end_time,
            'room_id' => $validated['room_id'] ?? $session->room_id,
            'faculty_id' => $validated['faculty_id'] ?? $session->faculty_id,
        ];

        $conflicts = $this->findConflicts($session, $proposed);
        if (!empty($conflicts)) {
            return response()->json([
                'message' => 'This change would create a conflict.',
                'conflicts' => $conflicts,
            ], 422);
        }

        $session->update($proposed);

        return response()->json($session->load(['subject', 'faculty', 'room']));
    }

    /**
     * Check the proposed session state against everything it must not collide with:
     * - the room's type must match what the subject needs (lecture vs lab)
     * - the faculty member's declared availability
     * - other sessions in the SAME schedule (same section can't be in 2 places)
     * - other sessions for the SAME faculty/room across approved/published schedules
     */
    private function findConflicts(ScheduleSession $session, array $proposed): array
    {
        $conflicts = [];

        // Room type check
        $room = Room::find($proposed['room_id']);
        $subject = $session->subject;
        $neededType = $session->session_type === 'laboratory' ? $subject->lab_room_type : 'lecture';
        if ($neededType && $room && $room->type !== $neededType) {
            $conflicts[] = "Room '{$room->name}' is type '{$room->type}', but this session needs '{$neededType}'.";
        }

        // Faculty availability check — only enforce if they HAVE declared availability
        $faculty = Faculty::with('availabilities')->find($proposed['faculty_id']);
        if ($faculty && $faculty->availabilities->isNotEmpty()) {
            $pStart = substr($proposed['start_time'], 0, 5);
            $pEnd   = substr($proposed['end_time'], 0, 5);

            $available = $faculty->availabilities->contains(function ($a) use ($proposed, $pStart, $pEnd) {
                $aStart = substr($a->start_time, 0, 5);
                $aEnd   = substr($a->end_time, 0, 5);

                return $a->day_of_week == $proposed['day_of_week']
                    && $pStart >= $aStart
                    && $pEnd <= $aEnd;
            });

            if (!$available) {
                $conflicts[] = "{$faculty->name} is not available on day {$proposed['day_of_week']} "
                    . "from {$pStart} to {$pEnd}.";
            }
        }

        // Overlap check: same section's own other sessions (can't be 2 places at once)
        $sectionSessions = $session->schedule->sessions()->where('id', '!=', $session->id)->get();
        foreach ($sectionSessions as $other) {
            if ($this->overlaps($proposed, $other)) {
                $conflicts[] = "Overlaps with another session in this same schedule ({$other->subject->code}).";
            }
        }

        // Overlap check: faculty/room double-booking across OTHER approved/published schedules
        $externalSessions = ScheduleSession::with('schedule')
            ->where('id', '!=', $session->id)
            ->where(function ($q) use ($proposed) {
                $q->where('faculty_id', $proposed['faculty_id'])
                  ->orWhere('room_id', $proposed['room_id']);
            })
            ->whereHas('schedule', function ($q) use ($session) {
                $q->where('section_id', '!=', $session->schedule->section_id)
                  ->whereIn('status', ['approved', 'published']);
            })
            ->get();

        foreach ($externalSessions as $other) {
            if (!$this->overlaps($proposed, $other)) {
                continue;
            }
            if ($other->faculty_id == $proposed['faculty_id']) {
                $conflicts[] = "Faculty is already booked elsewhere at this time (schedule #{$other->schedule_id}).";
            }
            if ($other->room_id == $proposed['room_id']) {
                $conflicts[] = "Room is already booked elsewhere at this time (schedule #{$other->schedule_id}).";
            }
        }

        return $conflicts;
    }

    private function overlaps(array $proposed, $other): bool
    {
        if ($proposed['day_of_week'] != $other->day_of_week) {
            return false;
        }
        return $proposed['start_time'] < $other->end_time && $other->start_time < $proposed['end_time'];
    }
}


/**
 * ScheduleSessionController
 *
 * Handles manual updates for schedule sessions:
 * - update(): change session day/time/room/faculty for admins
 *
 * It validates input, checks schedule status, and prevents conflicts:
 * - room type mismatch
 * - faculty availability
 * - overlapping sessions in the same schedule
 * - faculty/room double-booking in other approved/published schedules
 */