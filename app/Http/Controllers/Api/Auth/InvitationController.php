<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AccountInvitation;
use App\Models\ActivityLog;
use App\Models\LoginOtp;
use App\Models\StaffProfile;
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

        // A deactivated account's link must not preview its data either
        // (audit T53-a): the invitation outlived the deactivation by up to
        // its 7-day TTL and used to hand out names and children.
        if (! $user->is_active) {
            return response()->json([
                'message' => 'Akun ini telah dinonaktifkan. Hubungi pihak sekolah bila ini tidak seharusnya.',
            ], 403);
        }

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
     *
     * A reset invitation goes one step further: the link was sent to a NEW
     * contact the old one could not reach, so opening it proves that contact -
     * and it is written onto the account (plus the guardian/staff mirrors)
     * before the session starts.
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

        // The OTP lane already refuses deactivated accounts; the invitation
        // lane must not be the side door (audit T53-a). Deactivation also
        // consumes live invitations where it happens, but a link issued
        // before that sweep still ends here.
        if (! $user->is_active) {
            return response()->json([
                'message' => 'Akun ini telah dinonaktifkan. Hubungi pihak sekolah bila ini tidak seharusnya.',
            ], 403);
        }

        $activated = DB::transaction(function () use ($invitation, $user) {
            // Atomic claim, not check-then-act (audit T52): two requests
            // holding the same token - two tabs, or the rightful holder
            // racing someone who intercepted the link - can both pass
            // isUsable() above before either writes. Only the request that
            // flips used_at here proceeds; the loser gets the same "link
            // not valid" answer a spent link already gives, instead of a
            // second session.
            $claimed = AccountInvitation::query()
                ->whereKey($invitation->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            if ($claimed === 0) {
                return false;
            }

            if ($invitation->purpose === 'reset') {
                // The encrypted cast keeps phone_hash (blind index) in step on
                // write, which is exactly what OTP lookup reads later.
                if ($invitation->channel === 'email') {
                    $user->email = $invitation->sent_to;
                    $user->email_verified_at = now();
                } else {
                    $user->phone = $invitation->sent_to;
                }

                // THE PROMISE, DELIVERED (audit T50): the invitation told the
                // guardian this new contact "menjadi satu-satunya cara masuk
                // akun Anda" - four leftovers used to break that, all revoked
                // here in the same transaction:
                //
                // 1. The OTHER channel stops resolving for login. A phone
                //    stolen then reset via a new email must never receive an
                //    OTP for this account again. The hash column is nulled
                //    explicitly - setAttribute() skips hash-sync on null, so
                //    a stale blind index would keep matching lookups.
                if ($invitation->channel === 'email') {
                    $user->phone = null;
                    $user->phone_hash = null;
                } else {
                    $user->email = null;
                    $user->email_verified_at = null;
                }

                // 2. Every other live invitation (either purpose) dies with
                //    this one - an activation link mailed earlier to the OLD
                //    contact was a still-working login credential for up to
                //    its 7-day TTL. (This invitation is already claimed
                //    above, so whereNull('used_at') excludes it.)
                AccountInvitation::query()
                    ->where('user_id', $user->id)
                    ->whereNull('used_at')
                    ->update(['used_at' => now()]);

                // 3. Codes already issued to the old identifiers are spent.
                LoginOtp::query()
                    ->where('user_id', $user->id)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now()]);

                // 4. Open sessions die: a thief holding the stolen device
                //    kept a live remember-me session straight through the
                //    reset otherwise. (The login below then starts a FRESH
                //    session for the legitimate holder.) The remember token
                //    itself is cleared in the forceFill further down.
                DB::table('sessions')->where('user_id', $user->id)->delete();

                // The one-field-two-homes mirrors every other contact write
                // (UserController::store): parents carry theirs on Guardian,
                // staff on StaffProfile - and the revoked channel's mirror is
                // cleared too, so reminders stop reaching the dead contact.
                // The cleared channel's HASH is nulled explicitly (audit
                // T60-a): setAttribute() skips hash-sync on null, so a stale
                // blind index kept matching the next PMB handoff's lookup -
                // which then wrote the dead contact straight back onto the
                // guardian, un-revoking it for every reminder lane.
                if ($user->role === 'orangtua' && $user->guardian) {
                    $kept = $invitation->channel === 'email' ? 'email' : 'no_hp';
                    $cleared = $invitation->channel === 'email' ? 'no_hp' : 'email';

                    $user->guardian->forceFill([
                        $kept => $invitation->sent_to,
                        $cleared => null,
                        $cleared.'_hash' => null,
                    ])->save();
                }
            }

            $user->forceFill([
                'activated_at' => now(),
                'email_verified_at' => $user->email ? ($user->email_verified_at ?? now()) : null,
                'last_login_at' => now(),
                // T50: the remember token belongs to the pre-reset world.
                'remember_token' => null,
            ])->save();

            if ($invitation->purpose === 'reset' && $user->role === 'guru') {
                StaffProfile::mirrorUserPhone($user);
            }

            ActivityLog::record($user, $invitation->purpose === 'reset' ? 'account.contact_reset' : 'account.activated', $user, [
                'channel' => $invitation->channel,
            ]);

            return true;
        });

        if ($activated === false) {
            return response()->json([
                'message' => 'Tautan aktivasi tidak berlaku atau sudah kedaluwarsa.',
            ], 404);
        }

        // Explicit session guard: an earlier auth:sanctum-authenticated
        // request in the same process leaves the ambient default pointing at
        // the (login-less) Sanctum request guard - the SPA session is what
        // this lane always means, same explicitness as SessionController.
        Auth::guard('web')->login($user, remember: true);

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
