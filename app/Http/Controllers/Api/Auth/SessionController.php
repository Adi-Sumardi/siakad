<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * What is left of session handling once passwords are gone: reading the current
 * session and ending it. Starting one is OtpController's job, for everyone -
 * guardians and staff alike.
 */
class SessionController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->load('schoolUnit')),
        ]);
    }

    /**
     * The welcome splash was seen - it shows once per account (wali, guru,
     * admin unit today). Idempotent: the first time is kept.
     */
    public function welcomed(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->welcomed_at) {
            $user->forceFill(['welcomed_at' => now()])->save();
        }

        return response()->json([
            'user' => new UserResource($user->load('schoolUnit')),
        ]);
    }

    /**
     * The "Panduan Fitur" tour was seen (finished or skipped) - it also runs
     * once per account (wali, guru, admin unit today), and its automatic
     * offering keys off this the same way the splash keys off welcomed_at.
     * Idempotent: the first time is kept.
     */
    public function onboarded(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->onboarded_at) {
            $user->forceFill(['onboarded_at' => now()])->save();
        }

        return response()->json([
            'user' => new UserResource($user->load('schoolUnit')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        // Only stateful (browser) requests carry a session; guarding it keeps a
        // direct API call from turning into a 500.
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Berhasil keluar.']);
    }
}
