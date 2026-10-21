<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsPublishedReferences;
use App\Models\Room;
use App\Models\ScheduleSession;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    use GuardsPublishedReferences;

    /**
     * Canonical room types. Mirrors frontend/src/constants/roomTypes.ts
     * (ROOM_TYPES) and SubjectController::LAB_ROOM_TYPES, so a room's type and
     * the lab_room_type a subject demands share one vocabulary.
     *
     * This matters because the solver matches a session to a room with an exact
     * string comparison (`r["type"] == room_type`). A room typed "Computer Lab"
     * or "lab" therefore never satisfies a laboratory session that needs
     * "computer_lab": the lab is reported unschedulable ("No room of type
     * 'computer_lab' exists") even though a suitable room plainly exists. The
     * free-text field let that mismatch be created from the API even though the
     * Rooms page only ever offers these four values.
     */
    private const ROOM_TYPES = ['lecture', 'computer_lab', 'science_lab', 'electronics_lab'];

    public function index()
    {
        return response()->json(Room::all());
    }

    public function show(Room $room)
    {
        return response()->json($room);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:rooms,name',
            'type' => ['required', 'string', Rule::in(self::ROOM_TYPES)],
            'capacity' => 'required|integer|min:1',
            'status' => 'sometimes|in:available,under_maintenance,inactive',
        ]);

        $room = Room::create($validated);

        return response()->json($room, 201);
    }

    public function update(Request $request, Room $room)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|unique:rooms,name,' . $room->id,
            'type' => ['sometimes', 'string', Rule::in(self::ROOM_TYPES)],
            'capacity' => 'sometimes|integer|min:1',
            'status' => 'sometimes|in:available,under_maintenance,inactive',
        ]);

        $room->update($validated);

        return response()->json($room);
    }

    public function destroy(Request $request, Room $room)
    {
        $published = ScheduleSession::where('room_id', $room->id)
            ->whereHas('schedule', fn ($query) => $query->where('status', 'published'))
            ->get(['schedule_id']);

        $conflict = $this->publishedReferenceConflict(
            $request,
            $published->pluck('schedule_id')->unique()->values()->all(),
            ScheduleSession::where('room_id', $room->id)->count(),
            $published->count()
        );

        if ($conflict) {
            return $conflict;
        }

        $room->delete();

        return response()->json(['message' => 'Room deleted successfully']);
    }
}


/**
 * RoomController
 *
 * Handles room API actions:
 * - index(): list all rooms
 * - show(): get one room
 * - store(): create a new room
 * - update(): edit an existing room
 * - destroy(): delete a room
 *
 * `type` is restricted to the canonical vocabulary (ROOM_TYPES) shared with
 * frontend/src/constants/roomTypes.ts and SubjectController, because the
 * solver matches rooms to sessions by exact string comparison.
 */