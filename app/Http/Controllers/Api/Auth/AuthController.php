<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Security\FieldEncrypter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private FieldEncrypter $encrypter) {}

    /**
     * Password sign-in, for staff accounts only.
     *
     * Guardians go through OtpController instead: they have no password, and
     * their identifier may be a phone number rather than an email, since a
     * guardian in PG/TK frequently has no address at all.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'identifier' => 'required|string|max:200',
            'password' => 'required|string',
        ]);

        $throttleKey = mb_strtolower($credentials['identifier']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'identifier' => 'Terlalu banyak percobaan. Coba lagi dalam '
                    .RateLimiter::availableIn($throttleKey).' detik.',
            ]);
        }

        $user = $this->findByIdentifier($credentials['identifier']);

        // A guardian has no password at all. Saying so plainly is safe - it
        // reveals nothing an attacker could not learn by requesting a code -
        // and without it they would sit at this screen retrying a password that
        // was never created.
        if ($user && ! $user->usesPasswordLogin()) {
            throw ValidationException::withMessages([
                'identifier' => 'Akun ini masuk dengan kode sekali pakai. Gunakan tombol "Kirim kode".',
            ]);
        }

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            // One message for "no such account" and "wrong password": telling
            // them apart turns this endpoint into a way to discover which
            // families attend the school.
            throw ValidationException::withMessages([
                'identifier' => 'Email/nomor HP atau kata sandi tidak cocok.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'identifier' => 'Akun ini dinonaktifkan. Hubungi tata usaha sekolah.',
            ]);
        }

        if (! $user->hasActivated()) {
            throw ValidationException::withMessages([
                'identifier' => 'Akun belum diaktifkan. Silakan buka tautan aktivasi yang kami kirim.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        Auth::login($user, remember: true);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'user' => new UserResource($user->load('schoolUnit')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->load('schoolUnit')),
        ]);
    }

    private function findByIdentifier(string $identifier): ?User
    {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return User::where('email', $identifier)->first();
        }

        // Phone is encrypted, so the lookup goes through the blind index -
        // a where() on the ciphertext column could never match.
        return User::where('phone_hash', $this->encrypter->blindIndex($identifier))->first();
    }
}
