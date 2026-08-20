<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SchoolUnit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * List all users with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with('schoolUnit')
            ->when($request->string('search')->value(), function ($q, $search) {
                $q->where(function ($sq) use ($search) {
                    $sq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->string('role')->value(), fn ($q, $role) => $q->where('role', $role))
            ->when($request->string('unit')->value(), fn ($q, $unitCode) => $q->whereHas('schoolUnit', fn ($uq) => $uq->where('code', $unitCode)))
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
                        'guru' => 'Guru / Wali Kelas',
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
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'nullable|email|max:120|unique:users,email',
            'phone' => 'nullable|string|max:32',
            'role' => 'required|in:admin,admin_unit,guru,orangtua',
            'school_unit_ulid' => 'nullable|exists:school_units,ulid',
            'is_active' => 'boolean',
        ]);

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

        ActivityLog::record($request->user(), 'user.created', $user, ['role' => $user->role]);

        return response()->json(['user' => $user->load('schoolUnit')], 201);
    }

    /**
     * Update user details.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:120',
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => 'nullable|string|max:32',
            'role' => 'sometimes|in:admin,admin_unit,guru,orangtua',
            'school_unit_ulid' => 'nullable|exists:school_units,ulid',
            'is_active' => 'boolean',
        ]);

        if (array_key_exists('school_unit_ulid', $validated)) {
            $unit = ! empty($validated['school_unit_ulid'])
                ? SchoolUnit::where('ulid', $validated['school_unit_ulid'])->first()
                : null;
            $user->school_unit_id = $unit?->id;
        }

        $user->fill(collect($validated)->except(['school_unit_ulid'])->all());
        $user->save();

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
}
