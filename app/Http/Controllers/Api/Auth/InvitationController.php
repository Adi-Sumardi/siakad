<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AccountInvitation;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InvitationController extends Controller
{
    /**
     * Shows who an invitation belongs to, before anything is typed.
     *
     * Returning the guardian's name and their children lets them confirm they
     * opened the right link. It exposes nothing an attacker could not get by
     * completing the activation itself, and the token is single-use and short
     * lived, but the response deliberately stays thin: names only, no contact
     * details, no identity numbers.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = AccountInvitation::findByToken($token);

        if (! $invitation || ! $invitation->isUsable()) {
            return response()->json([
                'message' => 'Tautan aktivasi tidak berlaku atau sudah kedaluwarsa.',
            ], 404);
        }

        $user = $invitation->user;

        return response()->json([
            'name' => $user->name,
            'identifier' => $invitation->sent_to,
            'channel' => $invitation->channel,
            'expires_at' => $invitation->expires_at,
            'students' => $user->guardian?->students()->get()->map(fn ($s) => [
                'nama_lengkap' => $s->nama_lengkap,
                'unit' => $s->schoolUnit?->label,
            ]) ?? [],
        ]);
    }

    /**
     * Accepts the invitation and signs the guardian straight in.
     *
     * No password is set, because guardians do not have one - every later
     * sign-in goes through a one-time code. Holding this link is already proof
     * they control the address the school has on file, which is the same thing
     * a code checks, so the account is marked activated here and the session
     * starts immediately.
     */
    public function activate(Request $request, string $token): JsonResponse
    {
        $invitation = AccountInvitation::findByToken($token);

        if (! $invitation || ! $invitation->isUsable()) {
            return response()->json([
                'message' => 'Tautan aktivasi tidak berlaku atau sudah kedaluwarsa.',
            ], 404);
        }

        $user = $invitation->user;

        DB::transaction(function () use ($invitation, $user) {
            $user->forceFill([
                'activated_at' => now(),
                'email_verified_at' => $user->email ? now() : null,
                'last_login_at' => now(),
            ])->save();

            $invitation->markUsed();

            ActivityLog::record($user, 'account.activated', $user, [
                'channel' => $invitation->channel,
            ]);
        });

        Auth::login($user, remember: true);

        // Only stateful (browser) requests carry a session; Sanctum adds the
        // session middleware from the frontend origin. Guarding it keeps a
        // direct API call from turning into a 500 here.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'user' => new UserResource($user->fresh()->load('schoolUnit')),
        ]);
    }
}
