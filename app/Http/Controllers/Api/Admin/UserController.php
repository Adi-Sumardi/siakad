<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetUserAccessRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\ActivityLog;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Handoff\AccountInvitationSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * List all users with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $caller = $request->user();

        $users = User::query()
            ->with('schoolUnit')
            // A per-unit admin's whole job on this page is onboarding their
            // own unit's teachers and parents - the two account kinds they
            // can create, so the two kinds the list shows them. Other staff
            // stays invisible; parents show whether they were imported with
            // this unit stamped on them or arrived via PMB linked to this
            // unit's student. Without this an admin_unit's GET here returned
            // every account system-wide, contact details included - the exact
            // cross-unit leak the role-check-alone-is-not-enough rule (see
            // the admin route group in routes/api.php) exists to prevent.
            ->when($caller->isUnitScoped(), function ($q) use ($caller) {
                $q->where(function ($sq) use ($caller) {
                    $sq->where(function ($uq) use ($caller) {
                        $uq->whereIn('role', ['guru', 'orangtua'])
                            ->where('school_unit_id', $caller->school_unit_id);
                    })->orWhere(function ($pq) use ($caller) {
                        $pq->where('role', 'orangtua')
                            ->whereHas('guardian.students', fn ($gq) => $gq->where('school_unit_id', $caller->school_unit_id));
                    });
                });
            })
            ->when($request->string('search')->value(), function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->string('role')->value(), fn ($q, $role) => $q->where('role', $role))
            ->when(! $caller->isUnitScoped() && $request->string('unit')->value(), fn ($q, $unitCode) => $q->whereHas('schoolUnit', fn ($uq) => $uq->where('code', $unitCode)))
            ->when($request->has('is_active') && $request->input('is_active') !== '', fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('role')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'users' => [
                'data' => $users->map(fn (User $u) => [
                    'ulid' => $u->ulid,
                    'name' => $u->name,
                    'email' => $u->email,
                    'phone' => $u->phone,
                    'role' => $u->role,
                    'role_label' => match ($u->role) {
                        'admin' => 'Administrator Pusat',
                        'admin_unit' => 'Tata Usaha / Admin Unit',
                        'guru' => 'Guru',
                        'orangtua' => 'Wali Murid',
                        default => $u->role,
                    },
                    'school_unit' => $u->schoolUnit ? [
                        'ulid' => $u->schoolUnit->ulid,
                        'code' => $u->schoolUnit->code,
                        'label' => $u->schoolUnit->label,
                    ] : null,
                    'is_active' => $u->is_active,
                    'activated_at' => $u->activated_at?->toIso8601String(),
                    'last_login_at' => $u->last_login_at?->toIso8601String(),
                    'created_at' => $u->created_at?->toIso8601String(),
                ]),
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'total' => $users->total(),
                    'per_page' => $users->perPage(),
                ],
            ],
        ]);
    }

    /**
     * Create a new user.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // A per-unit admin may onboard their own unit's teachers and parents,
        // nothing else - the controller forces the unit rather than trusting
        // the parameter, the same line BillingRunController draws.
        if ($request->user()->isUnitScoped()) {
            if (! in_array($validated['role'], ['guru', 'orangtua'], true)) {
                return response()->json(['message' => 'Admin unit hanya dapat membuat akun guru atau wali murid untuk unitnya sendiri.'], 422);
            }

            $validated['school_unit_ulid'] = $request->user()->schoolUnit->ulid;
        }

        if (empty($validated['email']) && empty($validated['phone'])) {
            return response()->json(['message' => 'Email atau Nomor HP/WhatsApp harus diisi untuk proses autentikasi OTP.'], 422);
        }

        $unit = ! empty($validated['school_unit_ulid'])
            ? SchoolUnit::where('ulid', $validated['school_unit_ulid'])->first()
            : null;

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'role' => $validated['role'],
            'school_unit_id' => $unit?->id,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // A parent account is only useful once a guardian row exists to
        // attach students to - students CSV import later matches wali by
        // phone/email and links the children through it. PMB handoff
        // creates its own; every other creation path starts one here.
        if ($user->role === 'orangtua' && ! Guardian::where('user_id', $user->id)->exists()) {
            Guardian::create([
                'user_id' => $user->id,
                'nama' => $user->name,
                'hubungan' => 'wali',
                'no_hp' => $user->phone,
                'email' => $user->email,
            ]);
        }

        // The staff record's mirrored contact (03-ERD) - the same
        // one-field-two-homes reasoning as the guardian block above, for
        // the staff roles. No-op for parents.
        StaffProfile::mirrorUserPhone($user);

        ActivityLog::record($request->user(), 'user.created', $user, ['role' => $user->role]);

        return response()->json(['user' => $user->load('schoolUnit')], 201);
    }

    /**
     * Update user details.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('school_unit_ulid', $validated)) {
            $unit = ! empty($validated['school_unit_ulid'])
                ? SchoolUnit::where('ulid', $validated['school_unit_ulid'])->first()
                : null;
            $user->school_unit_id = $unit?->id;
        }

        $user->fill(collect($validated)->except(['school_unit_ulid'])->all());
        $user->save();

        // Note: deactivation needs no token revocation here - this app has no
        // personal access tokens (auth is Sanctum SPA cookie sessions; User
        // has no HasApiTokens). Enforcement is the `active` middleware on
        // /auth/me + /files/*, which refuses the existing session on its next
        // request, and OTP login already refuses deactivated accounts.

        // Keep the staff record's mirrored contact in step when the shared
        // field changes. The request is `sometimes`, so an absent key means
        // "untouched", not "cleared".
        if (array_key_exists('phone', $validated)) {
            StaffProfile::mirrorUserPhone($user);
        }

        ActivityLog::record($request->user(), 'user.updated', $user, $validated);

        return response()->json(['user' => $user->fresh('schoolUnit')]);
    }

    /**
     * Delete user.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Anda tidak dapat menghapus akun Anda sendiri.'], 422);
        }

        ActivityLog::record($request->user(), 'user.deleted', $user, ['email' => $user->email]);
        $user->delete();

        return response()->json(['message' => 'User berhasil dihapus.']);
    }

    /**
     * "Reset Akses" - the lost-access lane. A parent or teacher who lost BOTH
     * their email and their phone has no OTP to receive and no other way in;
     * this sends a reset invitation to a NEW contact the admin collected in
     * person. Opening the link (InvitationController::activate) writes the
     * new contact onto the account. Central admin only, like every other
     * user write - the new contact becomes the account's only way in.
     */
    public function resetAccess(ResetUserAccessRequest $request, User $user, AccountInvitationSender $invitations): JsonResponse
    {
        if (! in_array($user->role, ['orangtua', 'guru'], true)) {
            return response()->json([
                'message' => 'Jalur reset hanya untuk akun wali murid dan guru - akun admin dikelola lewat jalur lain.',
            ], 422);
        }

        $contact = $request->normalized;

        // The new contact must not silently point at somebody ELSE's account:
        // a duplicate means OTP codes for one identifier reach two accounts.
        // Email compares in SQL (plaintext column); phone goes through the
        // blind index because the column is ciphertext.
        $existing = $request->channel === 'email'
            ? User::where('email', $contact)->where('id', '!=', $user->id)->first()
            : User::findByEncrypted('phone', $contact);

        if ($existing !== null && $existing->id !== $user->id) {
            return response()->json([
                'message' => 'Kontak ini sudah dipakai akun lain - tidak bisa menjadi kontak akun ini.',
            ], 422);
        }

        $result = $invitations->sendReset($user, $contact, $request->user());

        // Delivery failure is not issuance failure: the invitation row exists
        // and the retry sweep (or a manual resend from ruang kontrol) delivers
        // it - the same stance PMB handoff takes.
        if (! $result->success) {
            report(new \RuntimeException("Reset invitation for user {$user->ulid} failed to deliver: ".(string) $result->message));
        }

        ActivityLog::record($request->user(), 'user.reset_access_issued', $user, [
            'channel' => $request->channel,
            'sent_to' => $contact,
            'delivered' => $result->success,
        ]);

        $invitation = $user->invitations()->where('purpose', 'reset')->whereNull('used_at')->latest('id')->first();

        return response()->json([
            'invitation' => [
                'ulid' => $invitation?->ulid,
                'channel' => $request->channel,
                'sent_to' => $contact,
                'expires_at' => $invitation?->expires_at,
                'purpose' => 'reset',
            ],
            'delivered' => $result->success,
            'delivery_message' => $result->message,
        ], 201);
    }
}
