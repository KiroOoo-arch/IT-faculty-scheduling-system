<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        // A wrong password is an authentication failure, not a malformed
        // request. Returning 422 (via ValidationException) told the client the
        // request body was wrong and left it inspecting individual fields, when
        // the actionable answer is "these credentials are incorrect" — which is
        // what 401 means. The frontend reads `message`, so the copy is unchanged.
        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        // Defense-in-depth: only Admin/Department Head accounts may log in.
        // 403 rather than 401: the credentials were valid, so the account simply
        // is not permitted in — a different problem with a different fix.
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Only administrator accounts can access this system.',
            ], 403);
        }

        $user->tokens()->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'role']),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->only(['id', 'name', 'email', 'role']));
    }
}


/**
 * AuthController
 *
 * Handles authentication API actions:
 * - login(): authenticate an admin user and generate an API token
 *            (non-admin roles are rejected — faculty are records, not users)
 * - logout(): invalidate the current API token
 * - me(): retrieve information about the authenticated user
 */
