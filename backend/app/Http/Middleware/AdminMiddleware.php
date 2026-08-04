<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

##Purpose: blocks requests unless the authenticated user has an admin role. If the user is not an admin, it returns a 403 Forbidden response with a message indicating that admin access is required.

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Admin access required.'], 403);
        }

        return $next($request);
    }
}
