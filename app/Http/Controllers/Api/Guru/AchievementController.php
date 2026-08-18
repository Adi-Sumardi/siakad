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
            'tanggal_event' => 'nullable|date|before_or_equal:today',
            'tempat_event' => 'nullable|string|max:200',
            'sertifikat' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'foto_kegiatan' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'points_awarded' => 'nullable|integer|min:1|max:200',
        ]);

        $student = Student::visibleTo($request->user())->where('ulid', $validated['student_ulid'])->firstOrFail();

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
            'foto_kegiatan_path' => $request->hasFile('foto_kegiatan') ? $request->file('foto_kegiatan')->store('achievements/photos', 'local') : null,
            'source' => 'sekolah',
            'status' => 'verified',
            'recorded_by' => $request->user()->id,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        if (! empty($validated['points_awarded'])) {
            $term = Term::current();

            if ($term) {
                try {
                    $ledger->awardForAchievement($achievement, $term, $request->user(), (int) $validated['points_awarded']);
                    $achievement->forceFill(['point_awarded' => $validated['points_awarded']])->save();
                } catch (RuntimeException) {
                    // Achievement is already saved either way; a bad points
                    // value just means no ledger entry, not a failed request.
                }
            }
        }

        ActivityLog::record($request->user(), 'achievement.recorded', $achievement, ['student' => $student->nama_lengkap]);

        return response()->json(['achievement' => new AchievementResource($achievement)], 201);
    }
}
