<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level role gate: `->middleware('role:orangtua')`, or several roles
 * separated by commas.
 *
 * This only decides who may reach an endpoint. *Which rows* they see is a
 * separate question, answered by visibleTo() on the model - a role check alone
 * would happily let one unit's admin open another unit's student.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'Anda tidak punya akses ke bagian ini.'], 403);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Akun ini dinonaktifkan.'], 403);
        }

        return $next($request);
    }
}
