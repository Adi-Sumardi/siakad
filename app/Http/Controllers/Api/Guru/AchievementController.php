<?php

namespace App\Http\Controllers\Api\Guru;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectAchievementRequest;
use App\Http\Requests\Admin\VerifyAchievementRequest;
use App\Http\Requests\Guru\StoreAchievementRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use App\Models\ActivityLog;
use App\Models\Student;
use App\Services\Kesiswaan\AchievementDecisionService;
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
    public function store(StoreAchievementRequest $request, PointLedger $ledger): JsonResponse
    {
        $validated = $request->validated();

        $student = Student::visibleTo($request->user())->where('ulid', $validated['student_ulid'])->firstOrFail();

        // PENDING, never self-verified (feature batch Poin 7): the teacher
        // PROPOSES; the child's own homeroom teacher decides, and the
        // points are written at decision time, exactly once. The
        // points_awarded field the request may carry is recorded as the
        // PROPOSAL for the verifier to see - it awards nothing here.
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
            'status' => 'pending',
            'recorded_by' => $request->user()->id,
            'point_awarded' => $validated['points_awarded'] ?? null,
        ]);

        ActivityLog::record($request->user(), 'achievement.proposed', $achievement, ['student' => $student->nama_lengkap]);

        return response()->json(['achievement' => new AchievementResource($achievement)], 201);
    }

    /**
     * A teacher's OWN achievement (Poin 7): proposed here, decided by an
     * admin_unit of the unit the teacher serves - never the teacher, never
     * central admin. No points: the merit ledger is a student construct.
     */
    public function storeSelf(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama_prestasi' => 'required|string|max:200',
            'kategori' => 'required|in:Akademik,Non-Akademik,Olahraga,Seni,Lainnya',
            'tingkat' => 'required|in:Kelas,Sekolah,Kecamatan,Kabupaten/Kota,Provinsi,Nasional,Internasional',
            'juara' => 'nullable|in:1,2,3,Harapan 1,Harapan 2,Harapan 3,Peserta',
            'nama_event' => 'nullable|string|max:200',
            'penyelenggara' => 'nullable|string|max:200',
            'tanggal_event' => 'nullable|date|before_or_equal:'.now('Asia/Jakarta')->toDateString(),
            'tempat_event' => 'nullable|string|max:200',
        ]);

        $user = $request->user();

        if (! $user->school_unit_id) {
            return response()->json(['message' => 'Akun guru Anda belum terhubung ke unit sekolah mana pun.'], 422);
        }

        $achievement = Achievement::create([
            'achiever_type' => 'guru',
            'teacher_user_id' => $user->id,
            'school_unit_id' => $user->school_unit_id,
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
            'source' => 'sekolah',
            'status' => 'pending',
            'recorded_by' => $user->id,
        ]);

        ActivityLog::record($user, 'achievement.teacher_proposed', $achievement, ['teacher' => $user->name]);

        return response()->json(['achievement' => new AchievementResource($achievement)], 201);
    }

    /**
     * The homeroom lane (Poin 7a): a teacher verifies/rejects a STUDENT
     * achievement - only their own homeroom students', enforced in the
     * service (403 with a reason, never a silently hidden button).
     */
    public function verify(VerifyAchievementRequest $request, string $ulid, AchievementDecisionService $decisions): JsonResponse
    {
        $achievement = Achievement::where('ulid', $ulid)->firstOrFail();

        if ($decisions->alreadyDecided($achievement)) {
            return response()->json(['message' => 'Prestasi ini sudah diputuskan sebelumnya.'], 422);
        }

        try {
            [$achievement, $decided] = $decisions->verify(
                $achievement,
                $request->user(),
                ! empty($request->validated()['points_awarded']) ? (int) $request->validated()['points_awarded'] : null,
                lane: 'guru',
            );
        } catch (RuntimeException $e) {
            // Permission refusals are a 403; the no-active-term state
            // failure keeps its historical 422 (both shapes matter).
            $isTermMissing = str_contains($e->getMessage(), 'Tidak ada semester aktif');
            return response()->json(['message' => $e->getMessage()], $isTermMissing ? 422 : 403);
        }

        if (! $decided) {
            return response()->json(['message' => 'Prestasi ini sudah diputuskan sebelumnya.'], 422);
        }

        ActivityLog::record($request->user(), 'achievement.verified', $achievement, [
            'student' => $achievement->student?->nama_lengkap,
            'points_awarded' => $request->validated()['points_awarded'] ?? null,
        ]);

        return response()->json(['achievement' => new AchievementResource($achievement->fresh())]);
    }

    public function reject(RejectAchievementRequest $request, string $ulid, AchievementDecisionService $decisions): JsonResponse
    {
        $achievement = Achievement::where('ulid', $ulid)->firstOrFail();

        if ($decisions->alreadyDecided($achievement)) {
            return response()->json(['message' => 'Prestasi ini sudah diputuskan sebelumnya.'], 422);
        }

        try {
            [$achievement, $decided] = $decisions->reject($achievement, $request->user(), $request->validated()['reason'], lane: 'guru');
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        if (! $decided) {
            return response()->json(['message' => 'Prestasi ini sudah diputuskan sebelumnya.'], 422);
        }

        ActivityLog::record($request->user(), 'achievement.rejected', $achievement, ['reason' => $request->validated()['reason']]);

        return response()->json(['achievement' => new AchievementResource($achievement->fresh())]);
    }
}
