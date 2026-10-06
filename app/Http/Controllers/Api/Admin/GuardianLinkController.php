<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Guardian;
use App\Models\Student;
use App\Services\Notification\PhoneNumberFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The admin lane for guardian <-> student links (audit 6 Okt 2026 #9).
 * Links used to be written only by the PMB handoff and the importer, so a
 * parent account made on the Users page had no child, and a wrong link could
 * only be fixed in the database. The student is always reached through
 * Student::visibleTo() (R3: another unit's child is 404).
 *
 * Billing contact stays exactly one per student (partial unique index from
 * 2026-10-05): the first link becomes it, switching moves it, and unlinking
 * the current one hands it to the next guardian rather than leaving none.
 */
class GuardianLinkController extends Controller
{
    public function index(Request $request, string $ulid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        return response()->json([
            'guardians' => $student->guardians()->with('user')->get()->map(fn (Guardian $g) => $this->present($g)),
        ]);
    }

    /**
     * Finds guardians to link: by exact phone or email (blind index - those
     * columns are encrypted) or by name. A unit admin only sees guardians
     * already tied to their unit or tied to nobody yet (a fresh account) -
     * never another campus's families.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 3) {
            return response()->json(['guardians' => []]);
        }

        $exact = collect([
            ($phone = PhoneNumberFormatter::toWhatsAppFormat($q)) ? Guardian::findByEncrypted('no_hp', $phone) : null,
            str_contains($q, '@') ? Guardian::findByEncrypted('email', mb_strtolower($q)) : null,
        ])->filter();

        $byName = Guardian::query()
            ->where('nama', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%')
            ->limit(20)
            ->get();

        $user = $request->user();

        $results = $exact->merge($byName)->unique('id')
            ->filter(function (Guardian $g) use ($user) {
                if (! $user->isUnitScoped()) {
                    return true;
                }

                $units = $g->students()->pluck('school_unit_id');

                return $units->isEmpty() || $units->contains($user->school_unit_id);
            })
            ->take(20)
            ->values();

        return response()->json(['guardians' => $results->map(fn (Guardian $g) => $this->present($g->loadMissing('user')))]);
    }

    /** Links an existing guardian (guardian_ulid) or a new contact (nama + no_hp). */
    public function store(Request $request, string $ulid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        $validated = $request->validate([
            'guardian_ulid' => 'nullable|string',
            'nama' => 'required_without:guardian_ulid|nullable|string|max:150',
            'no_hp' => 'nullable|string|max:30',
            'relationship' => 'required|in:ayah,ibu,wali',
            'is_primary' => 'boolean',
        ]);

        if (! empty($validated['guardian_ulid'])) {
            $guardian = Guardian::where('ulid', $validated['guardian_ulid'])->firstOrFail();
        } else {
            $phone = null;

            if (! empty($validated['no_hp'])) {
                $phone = PhoneNumberFormatter::toWhatsAppFormat($validated['no_hp']);

                if (! $phone) {
                    return response()->json(['message' => 'Nomor HP tidak dikenali - isi dengan format 08xxxxxxxxxx.'], 422);
                }

                // Same person, same phone: reuse the row rather than mint a duplicate contact.
                if ($existing = Guardian::findByEncrypted('no_hp', $phone)) {
                    return response()->json([
                        'message' => "Nomor ini sudah terdaftar atas nama {$existing->nama} - cari lalu tautkan kontak tersebut.",
                    ], 422);
                }
            }

            $guardian = Guardian::create([
                'nama' => $validated['nama'],
                'hubungan' => $validated['relationship'],
                'no_hp' => $phone,
            ]);
        }

        if ($student->guardians()->whereKey($guardian->id)->exists()) {
            return response()->json(['message' => 'Wali ini sudah tertaut ke siswa tersebut.'], 422);
        }

        DB::transaction(function () use ($student, $guardian, $validated) {
            $isFirst = ! $student->guardians()->exists();
            $primary = $isFirst || ($validated['is_primary'] ?? false);

            if ($primary) {
                DB::table('student_guardians')->where('student_id', $student->id)->update(['is_primary' => false]);
            }

            $student->guardians()->attach($guardian->id, [
                'relationship' => $validated['relationship'],
                'is_primary' => $primary,
                // The first link becomes the billing contact; later ones are
                // switched explicitly (exactly one per student).
                'is_billing_contact' => $isFirst,
            ]);
        });

        ActivityLog::record($request->user(), 'guardian.linked', $student, [
            'student' => $student->nama_lengkap,
            'guardian' => $guardian->nama,
            'relationship' => $validated['relationship'],
        ]);

        return response()->json(['guardian' => $this->present($student->guardians()->with('user')->whereKey($guardian->id)->first())], 201);
    }

    public function update(Request $request, string $ulid, string $guardianUlid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();
        $guardian = $student->guardians()->where('guardians.ulid', $guardianUlid)->firstOrFail();

        $validated = $request->validate([
            'relationship' => 'sometimes|in:ayah,ibu,wali',
            'is_primary' => 'sometimes|accepted',
            'is_billing_contact' => 'sometimes|accepted',
        ]);

        DB::transaction(function () use ($student, $guardian, $validated) {
            $pivot = [];

            if (isset($validated['relationship'])) {
                $pivot['relationship'] = $validated['relationship'];
            }

            if (! empty($validated['is_primary'])) {
                DB::table('student_guardians')->where('student_id', $student->id)->update(['is_primary' => false]);
                $pivot['is_primary'] = true;
            }

            if (! empty($validated['is_billing_contact'])) {
                // Demote first: the partial unique index allows one TRUE at a time.
                DB::table('student_guardians')->where('student_id', $student->id)->update(['is_billing_contact' => false]);
                $pivot['is_billing_contact'] = true;
            }

            if ($pivot !== []) {
                $student->guardians()->updateExistingPivot($guardian->id, $pivot);
            }
        });

        ActivityLog::record($request->user(), 'guardian.link_updated', $student, [
            'guardian' => $guardian->nama,
            'changes' => array_keys($validated),
        ]);

        return response()->json(['guardian' => $this->present($student->guardians()->with('user')->whereKey($guardian->id)->first())]);
    }

    public function destroy(Request $request, string $ulid, string $guardianUlid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();
        $guardian = $student->guardians()->where('guardians.ulid', $guardianUlid)->firstOrFail();

        DB::transaction(function () use ($student, $guardian) {
            $wasBilling = (bool) $guardian->pivot->is_billing_contact;
            $wasPrimary = (bool) $guardian->pivot->is_primary;

            $student->guardians()->detach($guardian->id);

            // Hand the roles to the next guardian so reminders and receipts
            // keep a recipient.
            $next = $student->guardians()->orderBy('student_guardians.id')->first();

            if ($next && ($wasBilling || $wasPrimary)) {
                $student->guardians()->updateExistingPivot($next->id, array_filter([
                    'is_billing_contact' => $wasBilling ?: null,
                    'is_primary' => $wasPrimary ?: null,
                ]));
            }
        });

        ActivityLog::record($request->user(), 'guardian.unlinked', $student, [
            'student' => $student->nama_lengkap,
            'guardian' => $guardian->nama,
        ]);

        return response()->json(['status' => 'ok']);
    }

    private function present(Guardian $g): array
    {
        $phone = $g->no_hp;

        return [
            'ulid' => $g->ulid,
            'nama' => $g->nama,
            'hubungan' => $g->hubungan,
            // Masked: enough for an admin to recognise the right contact.
            'no_hp' => $phone ? substr($phone, 0, 4).str_repeat('•', max(0, strlen($phone) - 7)).substr($phone, -3) : null,
            'has_account' => (bool) $g->user_id,
            'account_active' => (bool) $g->user?->activated_at,
            'relationship' => $g->pivot?->relationship,
            'is_primary' => (bool) $g->pivot?->is_primary,
            'is_billing_contact' => (bool) $g->pivot?->is_billing_contact,
        ];
    }
}
