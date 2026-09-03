<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use App\Models\ActivityLog;
use App\Models\Student;
use App\Models\Term;
use App\Services\Points\PointLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AchievementController extends Controller
{
    /**
     * A teacher recording a win they witnessed - trusted immediately, unlike a
     * guardian's own account of it. Points are optional and decided here, on
     * the spot, by the same person verifying it - there is no separate
     * verification step for a row that starts out already verified.
     */
    public function store(Request $request, PointLedger $ledger): JsonResponse
    {
        $validated = $request->validate([
            'student_ulid' => 'required|string',
            'nama_prestasi' => 'required|string|max:200',
            'kategori' => 'required|in:Akademik,Non-Akademik,Olahraga,Seni,Lainnya',
            'tingkat' => 'required|in:Kelas,Sekolah,Kecamatan,Kabupaten/Kota,Provinsi,Nasional,Internasional',
            'juara' => 'nullable|in:1,2,3,Harapan 1,Harapan 2,Harapan 3,Peserta',
            'nama_event' => 'nullable|string|max:200',
            'penyelenggara' => 'nullable|string|max:200',
            // Laravel's bare 'today' keyword resolves against
            // config('app.timezone'), which is UTC despite .env setting
            // Asia/Jakarta (config/app.php never reads the env var) - an
            // explicit Jakarta date avoids rejecting a same-day event as
            // "in the future" during the seven hours every morning UTC's
            // calendar date still lags Jakarta's.
            'tanggal_event' => ['nullable', 'date', 'before_or_equal:'.Carbon::today('Asia/Jakarta')->toDateString()],
            'tempat_event' => 'nullable|string|max:200',
            'sertifikat' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'foto_kegiatan' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'points_awarded' => 'nullable|integer|min:1|max:200',
        ]);

        $student = Student::visibleTo($request->user())->where('ulid', $validated['student_ulid'])->firstOrFail();

        // Term::current() depends on an admin-managed is_active flag, not a
        // date range - routinely null right after a semester ends until
        // someone activates the next one. Silently dropping the points a
        // teacher explicitly typed used to still report success (the
        // achievement saved either way, with point_awarded quietly left
        // null) - the same failure mode fixed in Admin\AchievementController.
        if (! empty($validated['points_awarded']) && ! Term::current()) {
            return response()->json([
                'message' => 'Tidak ada semester (term) yang sedang aktif, jadi poin tidak dapat dicatat. Aktifkan term terlebih dahulu, atau catat tanpa poin.',
            ], 422);
        }

        try {
            $achievement = DB::transaction(function () use ($request, $validated, $student, $ledger) {
                $achievement = Achievement::create([
                    'student_id' => $student->id,
                    'nama_prestasi' => $validated['nama_prestasi'],
                    'kategori' => $validated['kategori'],
                    'tingkat' => $validated['tingkat'],
                    'juara' => $validated['juara'] ?? null,
                    'nama_event' => $validated['nama_event'] ?? null,
                    'penyelenggara' => $validated['penyelenggara'] ?? null,
                    'tanggal_event' => $validated['tanggal_event'] ?? null,
                    'tempat_event' => $validated['tempat_event'] ?? null,
                    'sertifikat_path' => $request->hasFile('sertifikat') ? $request->file('sertifikat')->store('achievements/certificates', 'local') : null,
                    'sertifikat_name' => $request->file('sertifikat')?->getClientOriginalName(),
                    'foto_kegiatan_path' => $request->hasFile('foto_kegiatan') ? $request->file('foto_kegiatan')->store('achievements/photos', 'local') : null,
                    'foto_kegiatan_name' => $request->file('foto_kegiatan')?->getClientOriginalName(),
                    'source' => 'sekolah',
                    'status' => 'verified',
                    'recorded_by' => $request->user()->id,
                    'verified_by' => $request->user()->id,
                    'verified_at' => now(),
                ]);

                if (! empty($validated['points_awarded'])) {
                    $ledger->awardForAchievement($achievement, Term::current(), $request->user(), (int) $validated['points_awarded']);
                    $achievement->forceFill(['point_awarded' => $validated['points_awarded']])->save();
                }

                return $achievement;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ActivityLog::record($request->user(), 'achievement.recorded', $achievement, ['student' => $student->nama_lengkap]);

        return response()->json(['achievement' => new AchievementResource($achievement)], 201);
    }
}
