<?php

namespace App\Http\Controllers;

use App\Models\User;
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
            'role'     => 'required|string|in:admin',
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
            'role'     => 'sometimes|string|in:admin',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);
        return response()->json($user);
    }

    public function destroy(Request $request, User $user)
    {
        // Safety guard: never delete yourself, and never delete the last admin
        // (otherwise the system would be permanently locked out).
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $adminCount = User::where('role', 'admin')->count();
        if ($user->role === 'admin' && $adminCount <= 1) {
            return response()->json(['message' => 'Cannot delete the last admin account.'], 422);
        }

        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }
}

/**
 * UserController
 *
 * Handles admin account API actions (faculty are records, not users):
 * - index(): list all users
 * - show(): get one user
 * - store(): create a new admin user
 * - update(): edit an existing user
 * - destroy(): delete a user (never the last admin or yourself)
 */
