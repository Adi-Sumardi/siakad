<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectAchievementRequest;
use App\Http\Requests\Admin\VerifyAchievementRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use App\Models\ActivityLog;
use App\Models\Term;
use App\Services\Kesiswaan\AchievementDecisionService;
use App\Services\Points\PointLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AchievementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $achievements = Achievement::query()
            ->visibleTo($request->user())
            ->with(['student', 'recordedBy', 'verifiedBy', 'teacher', 'schoolUnit'])
            ->when($request->string('status')->value(), fn ($q, $status) => $q->where('status', $status))
            // The Prestasi Guru tab filters by achiever (Poin 7): 'siswa'
            // (default, the historical list) or 'guru'.
            ->when($request->string('achiever_type')->value(), fn ($q, $type) => $q->where('achiever_type', $type))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['achievements' => AchievementResource::collection($achievements)]);
    }

    /**
     * Confirms a guardian's submission actually happened. Points are optional
     * and decided here - the judgement call the ERD describes as "how big was
     * this win, really", made once, at the moment someone independent signs
     * off on it.
     */
    public function verify(VerifyAchievementRequest $request, string $ulid, PointLedger $ledger, AchievementDecisionService $decisions): JsonResponse
    {
        $validated = $request->validated();

        $achievement = Achievement::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        if ($decisions->alreadyDecided($achievement)) {
            return response()->json(['message' => 'Prestasi ini sudah diputuskan sebelumnya.'], 422);
        }

        // The two verification lanes (Poin 7): student achievements keep
        // their historical admin/admin_unit deciders (scoped by visibleTo
        // above); teacher achievements are an admin_unit-of-the-unit call -
        // central admin sees the row and decides NOTHING (403 with a
        // reason, not a hidden button).
        try {
            [$achievement, $decided] = $decisions->verify(
                $achievement,
                $request->user(),
                ! empty($validated['points_awarded']) ? (int) $validated['points_awarded'] : null,
                lane: 'admin',
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
            // A teacher achievement has no student - the log names whoever
            // achieved instead (Poin 7).
            'student' => $achievement->student?->nama_lengkap ?? $achievement->teacher?->name,
            'points_awarded' => $validated['points_awarded'] ?? null,
        ]);

        return response()->json(['achievement' => new AchievementResource($achievement->fresh())]);
    }

    public function reject(RejectAchievementRequest $request, string $ulid, AchievementDecisionService $decisions): JsonResponse
    {
        $validated = $request->validated();

        $achievement = Achievement::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        // The two verification lanes (feature batch Poin 7) live in one
        // service so the gate cannot drift between callers. For an
        // ADMIN-facing reject: teacher achievements are an admin_unit's
        // call (central admin sees, decides nothing); student
        // achievements stay decidable here as they always were.
        if ($decisions->alreadyDecided($achievement)) {
            return response()->json(['message' => 'Prestasi ini sudah diputuskan sebelumnya.'], 422);
        }

        try {
            [$achievement, $decided] = $decisions->reject($achievement, $request->user(), $validated['reason'], lane: 'admin');
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        if (! $decided) {
            return response()->json(['message' => 'Prestasi ini sudah diputuskan sebelumnya.'], 422);
        }

        ActivityLog::record($request->user(), 'achievement.rejected', $achievement, ['reason' => $validated['reason']]);

        return response()->json(['achievement' => new AchievementResource($achievement->fresh())]);
    }
}
