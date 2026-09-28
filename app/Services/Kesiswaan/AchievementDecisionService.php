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
 * same transaction. Re-deciding a decided row is a 409 at the API and,
 * since the unique-index migration, impossible at the schema level too:
 * point_records accepts at most one ACTIVE merit row per achievement.
 */
class AchievementDecisionService
{
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
        // it as 409 Conflict (state), while permission refusals surface as
        // 403.
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

        if ($points !== null && $achievement->achiever_type === 'guru') {
            // The merit ledger is a student construct (point_records.
            // student_id is NOT NULL) - points on a teacher achievement
            // would die on the insert mid-transaction. Refuse up front
            // (Poin 6B) instead of surfacing a 500.
            throw AchievementStateFailure::pointsNotApplicable();
        }

        if ($points !== null && ! Term::current()) {
            throw AchievementStateFailure::termMissing();
        }

        $decided = DB::transaction(function () use ($achievement, $actor, $points) {
            // Atomic claim (audit T52): two concurrent verifications - two
            // tabs, a double-click, an admin racing the wali kelas - and
            // only the one that flips pending wins; the loser gets false
            // and reports "sudah diputuskan" (409), never a second award.
            // The same update writes the FINAL point_awarded: when the
            // decider grants no points, a teacher proposal's suggested
            // value dies here, so a decided row can never read "20 poin"
            // again with no ledger row behind it.
            $claimed = Achievement::query()
                ->whereKey($achievement->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->update([
                    'status' => 'verified',
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                    'point_awarded' => $points,
                ]);

            if ($claimed !== 1) {
                return false;
            }

            // Points exist ONLY on the pending -> verified transition, in
            // this transaction - never per endpoint call.
            if ($points !== null) {
                $this->ledger->awardForAchievement($achievement, Term::current(), $actor, $points);
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
