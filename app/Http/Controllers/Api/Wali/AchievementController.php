<?php

namespace App\Http\Controllers\Api\Wali;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wali\StoreAchievementRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use App\Models\ActivityLog;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AchievementController extends Controller
{
    public function index(Request $request, string $ulid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        $achievements = $student->achievements()
            ->with(['recordedBy', 'verifiedBy'])
            ->orderByDesc('tanggal_event')
            ->get();

        return response()->json([
            'student' => ['ulid' => $student->ulid, 'nama_lengkap' => $student->nama_lengkap, 'nama_panggilan' => $student->nama_panggilan],
            'achievements' => AchievementResource::collection($achievements),
        ]);
    }

    /**
     * A guardian's own account of a win, not a teacher's - it waits for staff
     * to confirm it actually happened before it counts as anything, and never
     * carries points on its own.
     */
    public function store(StoreAchievementRequest $request, string $ulid): JsonResponse
    {
        $student = Student::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        $validated = $request->validated();

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
        ]);

        ActivityLog::record($request->user(), 'achievement.submitted', $achievement, ['student' => $student->nama_lengkap]);

        return response()->json(['achievement' => new AchievementResource($achievement)], 201);
    }
}
