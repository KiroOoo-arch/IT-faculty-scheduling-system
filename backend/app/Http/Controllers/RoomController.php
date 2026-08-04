<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller
{
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
            'type' => 'required|string',
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
            'type' => 'sometimes|string',
            'capacity' => 'sometimes|integer|min:1',
            'status' => 'sometimes|in:available,under_maintenance,inactive',
        ]);

        $room->update($validated);

        return response()->json($room);
    }

    public function destroy(Room $room)
    {
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
 */