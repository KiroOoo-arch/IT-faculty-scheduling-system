<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Faculty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::all());
    }

    public function show(User $user)
    {
        return response()->json($user);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string',
            'email'    => 'required|email|unique:users',
            'password' => 'required|string|min:6',
            'role'     => 'required|string|in:admin,faculty',
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $user = User::create($validated);
        return response()->json($user, 201);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name'     => 'sometimes|string',
            'email'    => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:6',
            'role'     => 'sometimes|string|in:admin,faculty',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);
        return response()->json($user);
    }

    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }

    public function createFaculty(Request $request)
    {
        $validated = $request->validate([
            'name'              => 'required|string',
            'email'             => 'required|email|unique:users',
            'password'          => 'required|string|min:6',
            'faculty_type'      => 'required|string|in:full_time,part_time',
            'max_teaching_load' => 'required|integer|min:1',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role'     => 'faculty',
        ]);

        $faculty = Faculty::create([
            'user_id'           => $user->id,
            'faculty_type'      => $validated['faculty_type'],
            'max_teaching_load' => $validated['max_teaching_load'],
        ]);

        return response()->json([
            'user'    => $user,
            'faculty' => $faculty,
        ], 201);
    }
}

/**
 * UserController
 *
 * Handles user API actions:
 * - index(): list all users
 * - show(): get one user
 * - store(): create a new user
 * - update(): edit an existing user
 * - destroy(): delete a user
 * - createFaculty(): create a faculty user and faculty profile
 */