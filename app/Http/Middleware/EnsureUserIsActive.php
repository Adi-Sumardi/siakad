<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level "still employed/enrolled" gate for endpoints every role shares
 * (`role:` cannot express "any role").
 *
 * The role middleware already refuses deactivated users, but a few lanes are
 * role-agnostic on purpose - /auth/me and the private /files/* downloads gate
 * by row ownership, not by role. Without this check a deactivated account
 * keeps reading its profile and files until its session happens to expire,
 * because nothing else stands between the cookie and the response.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            return response()->json(['message' => 'Akun ini dinonaktifkan.'], 403);
        }

        return $next($request);
    }
}
