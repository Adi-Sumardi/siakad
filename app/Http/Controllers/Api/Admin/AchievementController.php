<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectAchievementRequest;
use App\Http\Requests\Admin\VerifyAchievementRequest;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use App\Models\ActivityLog;
use App\Models\Term;
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
            ->with(['student', 'recordedBy', 'verifiedBy'])
            ->when($request->string('status')->value(), fn ($q, $status) => $q->where('status', $status))
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
    public function verify(VerifyAchievementRequest $request, string $ulid, PointLedger $ledger): JsonResponse
    {
        $validated = $request->validated();

        $achievement = Achievement::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        // Term::current() depends on an admin-managed is_active flag, not a
        // date range - right after a semester ends this is routinely null
        // until someone activates the next term. Silently verifying without
        // the points an admin explicitly asked for used to report success
        // regardless (the frontend's toast unconditionally said "poin
        // ditambahkan"), so the request never surfaced that nothing was
        // actually credited.
        if (! empty($validated['points_awarded']) && ! Term::current()) {
            return response()->json([
                'message' => 'Tidak ada semester (term) yang sedang aktif, jadi poin tidak dapat dicatat. Aktifkan term terlebih dahulu, atau verifikasi tanpa poin.',
            ], 422);
        }

        // One transaction: a point-award failure must not leave the
        // achievement marked verified with nothing to show for it - the
        // admin would see an error, retry, and be told "sudah diputuskan
        // sebelumnya" for a request that never actually succeeded. The
        // status flip inside is an ATOMIC CLAIM (audit T52): two admins
        // clicking verify at the same moment must not both award points -
        // only the update that still finds status='pending' proceeds.
        try {
            $decided = DB::transaction(function () use ($achievement, $validated, $request, $ledger) {
                $claimed = Achievement::query()
                    ->whereKey($achievement->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'verified',
                        'verified_by' => $request->user()->id,
                        'verified_at' => now(),
                    ]);

                if ($claimed === 0) {
                    return false;
                }

                if (! empty($validated['points_awarded'])) {
                    $ledger->awardForAchievement($achievement, Term::current(), $request->user(), (int) $validated['points_awarded']);
                    $achievement->forceFill(['point_awarded' => $validated['points_awarded']])->save();
                }

                return true;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (($decided ?? true) === false) {
            return response()->json(['message' => 'Prestasi ini sudah diputuskan sebelumnya.'], 422);
        }

        ActivityLog::record($request->user(), 'achievement.verified', $achievement, [
            'student' => $achievement->student->nama_lengkap, 'points_awarded' => $validated['points_awarded'] ?? null,
        ]);

        return response()->json(['achievement' => new AchievementResource($achievement->fresh())]);
    }

    public function reject(RejectAchievementRequest $request, string $ulid): JsonResponse
    {
        $validated = $request->validated();

        $achievement = Achievement::visibleTo($request->user())->where('ulid', $ulid)->firstOrFail();

        // Atomic claim, same shape as verify() (audit T52).
        $claimed = Achievement::query()
            ->whereKey($achievement->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'rejection_reason' => $validated['reason'],
            ]);

        if ($claimed === 0) {
            return response()->json(['message' => 'Prestasi ini sudah diputuskan sebelumnya.'], 422);
        }

        ActivityLog::record($request->user(), 'achievement.rejected', $achievement, ['reason' => $validated['reason']]);

        return response()->json(['achievement' => new AchievementResource($achievement->fresh())]);
    }
}
