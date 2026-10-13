<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role-based access control. Pass one or more allowed role slugs:
 *   Route::get('/x', ...)->middleware('role:admin,manager');
 *
 * Super-admins holding every permission (`*:*:*`) are always allowed.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$allowed): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        if ($user->isAdmin()) {
            return $next($request);
        }

        $roles = $user->roles->pluck('slug')->all();

        if (array_intersect($allowed, $roles)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ], 403);
    }
}
