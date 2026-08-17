<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Models\LoginOtp;
use App\Services\Auth\OtpService;
use App\Services\Auth\OtpThrottled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Passwordless sign-in for guardians.
 *
 * Two steps: ask for a code, then send it back. Staff accounts keep a password
 * (see AuthController) - they sign in daily and an OTP round trip every morning
 * would be a tax, not a convenience.
 */
class OtpController extends Controller
{
    public function __construct(private OtpService $otp) {}

    /**
     * Sends a code to whichever channel the identifier implies.
     *
     * The response is the same whether or not the account exists. Telling an
     * unknown address apart from a known one turns this into a way to find out
     * which families attend the school.
     */
    public function request(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => 'required|string|max:200',
        ]);

        $identifier = $this->otp->normalise($validated['identifier']);
        $channel = $this->otp->channelFor($identifier);

        // Per-identifier and per-IP, because either one alone is easy to slip:
        // one address hammered from many IPs, or one IP walking a list of them.
        foreach (["otp:id:{$identifier}", 'otp:ip:'.$request->ip()] as $key) {
            if (RateLimiter::tooManyAttempts($key, 5)) {
                throw ValidationException::withMessages([
                    'identifier' => 'Terlalu banyak permintaan kode. Coba lagi dalam '
                        .RateLimiter::availableIn($key).' detik.',
                ]);
            }
            RateLimiter::hit($key, 600);
        }

        $user = $this->otp->findUser($identifier);
        $genericResponse = response()->json([
            'channel' => $channel,
            'identifier' => $this->mask($identifier, $channel),
            'expires_in_minutes' => LoginOtp::TTL_MINUTES,
            'resend_after_seconds' => LoginOtp::RESEND_COOLDOWN_SECONDS,
        ]);

        if (! $user || ! $user->is_active) {
            // Deliberately silent to the caller, loud in the log: a burst of
            // these is what an enumeration attempt looks like.
            Log::info('[OTP] Requested for unknown or inactive account', [
                'identifier' => $this->mask($identifier, $channel),
                'ip' => $request->ip(),
            ]);

            return $genericResponse;
        }

        try {
            $this->otp->issue($user, $identifier, $request->ip());
        } catch (OtpThrottled $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'retry_after_seconds' => $e->retryAfterSeconds,
            ], 429);
        }

        return $genericResponse;
    }

    /** Checks the code and, if it matches, starts the session. */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => 'required|string|max:200',
            'code' => 'required|string|size:6',
        ]);

        $identifier = $this->otp->normalise($validated['identifier']);
        $user = $this->otp->verify($identifier, $validated['code']);

        if (! $user) {
            throw ValidationException::withMessages([
                'code' => 'Kode salah atau sudah kedaluwarsa. Minta kode baru bila perlu.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'identifier' => 'Akun ini dinonaktifkan. Hubungi tata usaha sekolah.',
            ]);
        }

        // First successful code doubles as activation: proving control of the
        // address the school already has on file is exactly what activation
        // was ever checking.
        $user->forceFill([
            'activated_at' => $user->activated_at ?? now(),
            'last_login_at' => now(),
            'email_verified_at' => $user->email && ! $user->email_verified_at ? now() : $user->email_verified_at,
        ])->save();

        Auth::login($user, remember: true);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        ActivityLog::record($user, 'auth.otp_login', $user, [
            'channel' => $this->otp->channelFor($identifier),
        ]);

        return response()->json([
            'user' => new UserResource($user->load('schoolUnit')),
        ]);
    }

    /**
     * Enough for the guardian to recognise where the code went, not enough to
     * confirm an address to someone who guessed it.
     */
    private function mask(string $identifier, string $channel): string
    {
        if ($channel === 'email') {
            [$name, $domain] = array_pad(explode('@', $identifier, 2), 2, '');

            return mb_substr($name, 0, 2).str_repeat('*', max(1, mb_strlen($name) - 2)).'@'.$domain;
        }

        return str_repeat('*', max(0, mb_strlen($identifier) - 4)).mb_substr($identifier, -4);
    }
}
