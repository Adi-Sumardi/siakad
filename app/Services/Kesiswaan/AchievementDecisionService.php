<?php

namespace App\Services\Kesiswaan;

use App\Models\Achievement;
use App\Models\Term;
use App\Models\User;
use App\Services\Points\PointLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The two verification lanes for achievements (feature batch Poin 7) - TWO
 * DIFFERENT FLOWS, deliberately not unified:
 *
 *  (a) STUDENT achievements - proposed by a teacher or the family, decided
 *      by the student's OWN homeroom teacher (wali kelas). Nobody else:
 *      a teacher who is not that child's wali kelas gets a 403, not a
 *      hidden button.
 *
 *  (b) TEACHER achievements - proposed by the teacher themself, decided by
 *      an admin_unit of the school unit the teacher serves. Central admin
 *      may see everything but decides nothing here (the school's explicit
 *      instruction); the API refuses, it does not merely hide the button.
 *
 * Both lanes share the atomic claim (audit T52): only the request that
 * flips pending -> decided wins; concurrent double-clicks produce one
 * decision, and the points (if any) are written exactly once, inside the
 * same transaction.
 */
class AchievementDecisionService
{
    /**
     * A STATE failure (422 upstream), as opposed to the permission
     * refusals which surface as 403: the decider is allowed, the world
     * just is not ready (no active term to file points under).
     */
    public static function termMissing(): RuntimeException
    {
        return new RuntimeException('Tidak ada semester aktif - poin tidak bisa dicatat. Verifikasi tanpa poin, atau aktifkan term terlebih dahulu.');
    }

    public function __construct(private PointLedger $ledger) {}

    /**
     * Who may decide THIS achievement, and why not (null = allowed).
     * Kept public so the API layer can turn it into a 403 with a reason -
     * the enforcement lives here, not in the frontend.
     *
     * $lane picks the calling surface: 'admin' (the admin controller,
     * where student achievements have always been decided by admin OR
     * admin_unit) or 'guru' (the teacher surface, where a student
     * achievement is decided ONLY by that child's homeroom teacher).
     */
    public function refusalFor(Achievement $achievement, User $actor, string $lane = 'admin'): ?string
    {
        // "Already decided" is not a permission question - callers surface
        // it as 422 (state), while permission refusals surface as 403.
        if ($achievement->status !== 'pending') {
            return null; // handled separately by alreadyDecided()
        }

        if ($achievement->achiever_type === 'guru') {
            // Teacher achievements: an admin_unit of the teacher's own unit
            // decides - central admin sees everything and decides nothing
                // here (the school's explicit instruction, Poin 7).
            if ($actor->role !== 'admin_unit') {
                return 'Prestasi guru hanya bisa diverifikasi oleh admin unit sekolahnya.';
            }

            if ((int) $achievement->school_unit_id !== (int) $actor->school_unit_id) {
                return 'Prestasi guru ini bukan di unit Anda.';
            }

            return null;
        }

        // Student achievement.
        if ($lane === 'admin') {
            // The historical admin surface: admin and admin_unit decide,
            // scoped by visibleTo() upstream - unchanged.
            return in_array($actor->role, ['admin', 'admin_unit'], true) ? null : 'Prestasi siswa hanya bisa diverifikasi oleh admin atau wali kelasnya.';
        }

        // The teacher surface: ONLY the homeroom teacher of the student's
        // ACTIVE classroom in the ACTIVE term's year - the relation the
        // schema has carried since day one (classrooms.homeroom_teacher_id).
        if ($actor->role !== 'guru') {
            return 'Prestasi siswa hanya bisa diverifikasi oleh wali kelasnya.';
        }

        $term = Term::current();

        if (! $term) {
            return 'Belum ada semester aktif - aktivasi semester terlebih dahulu.';
        }

        $homeroomTeacherId = $achievement->student?->enrollments()
            ->where('status', 'active')
            ->where('academic_year_id', $term->academic_year_id)
            ->with('classroom')
            ->get()
            ->pluck('classroom.homeroom_teacher_id')
            ->filter()
            ->first();

        if (! $homeroomTeacherId || (int) $homeroomTeacherId !== (int) $actor->id) {
            return 'Hanya wali kelas siswa ini yang boleh memverifikasi prestasinya.';
        }

        return null;
    }

    /**
     * @return array{0: Achievement, 1: bool} the achievement and whether
     *                                           THIS call made the decision.
     */
    /** Not a permission question - the row is simply past deciding. */
    public function alreadyDecided(Achievement $achievement): bool
    {
        return $achievement->status !== 'pending';
    }

    public function verify(Achievement $achievement, User $actor, ?int $points = null, string $lane = 'admin'): array
    {
        if ($reason = $this->refusalFor($achievement, $actor, $lane)) {
            throw new RuntimeException($reason);
        }

        if ($points !== null && ! Term::current()) {
            throw self::termMissing();
        }

        $decided = DB::transaction(function () use ($achievement, $actor, $points) {
            // Atomic claim (audit T52): two concurrent verifications - two
            // tabs, a double-click, an admin racing the wali kelas - and
            // only the one that flips pending wins; the loser gets false
            // and reports "sudah diputuskan", never a second award.
            $claimed = Achievement::query()
                ->whereKey($achievement->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->update([
                    'status' => 'verified',
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                ]);

            if ($claimed !== 1) {
                return false;
            }

            if ($points !== null) {
                $this->ledger->awardForAchievement($achievement->fresh(), Term::current(), $actor, $points);
                $achievement->fresh()->forceFill(['point_awarded' => $points])->save();
            }

            return true;
        });

        return [$achievement->fresh(), $decided];
    }

    /** @return array{0: Achievement, 1: bool} */
    public function reject(Achievement $achievement, User $actor, string $reason, string $lane = 'admin'): array
    {
        if ($refusal = $this->refusalFor($achievement, $actor, $lane)) {
            throw new RuntimeException($refusal);
        }

        $decided = Achievement::query()
            ->whereKey($achievement->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'verified_by' => $actor->id,
                'verified_at' => now(),
            ]) === 1;

        return [$achievement->fresh(), $decided];
    }
}
