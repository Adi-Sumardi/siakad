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
