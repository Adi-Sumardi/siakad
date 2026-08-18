<?php

namespace App\Services\Points;

use App\Models\Achievement;
use App\Models\PointRecord;
use App\Models\PointRule;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The only writer of point_records.
 *
 * Every row is signed and permanent - a correction is a revoke with a reason,
 * never an UPDATE or a DELETE (see docs/01-ARSITEKTUR.md D6). A running balance
 * is a SUM over this table, computed on read, not a column kept in step by
 * hand; that is what makes "why is my child at -40" answerable with a list of
 * events instead of a number nobody can explain.
 *
 * A record always traces to something: a catalogue rule (the normal path) or a
 * verified achievement (points awarded on verification). There is no path that
 * lets a caller hand in an arbitrary point value - a teacher applies a rule,
 * they do not invent a number.
 */
class PointLedger
{
    /**
     * Applies a catalogued rule to one student.
     */
    public function record(
        Student $student,
        Term $term,
        PointRule $rule,
        User $recordedBy,
        Carbon $occurredOn,
        string $description,
        ?string $evidencePath = null,
        ?string $evidenceName = null,
    ): PointRecord {
        if ($rule->requires_evidence && ! $evidencePath) {
            throw new RuntimeException("Aturan '{$rule->name}' mewajibkan bukti.");
        }

        return PointRecord::create([
            'student_id' => $student->id,
            'term_id' => $term->id,
            'point_rule_id' => $rule->id,
            'type' => $rule->type,
            'points' => $rule->signedPoints(),
            'occurred_on' => $occurredOn,
            'description' => $description,
            'evidence_path' => $evidencePath,
            'evidence_name' => $evidenceName,
            'recorded_by' => $recordedBy->id,
            'status' => 'recorded',
        ]);
    }

    /**
     * The same rule, applied to many students at once - a whole line late to
     * morning assembly is one action, not thirty identical ones clicked by
     * hand.
     *
     * @param  Collection<int, Student>  $students
     * @return Collection<int, PointRecord>
     */
    public function recordBulk(
        Collection $students,
        Term $term,
        PointRule $rule,
        User $recordedBy,
        Carbon $occurredOn,
        string $description,
    ): Collection {
        return $students->map(
            fn (Student $student) => $this->record($student, $term, $rule, $recordedBy, $occurredOn, $description)
        );
    }

    /**
     * Points awarded for a verified achievement. Bypasses the catalogue on
     * purpose - an achievement's value is a judgement call an admin makes at
     * verification time (how big was this win, really?), not a fixed rate.
     */
    public function awardForAchievement(Achievement $achievement, Term $term, User $awardedBy, int $points): PointRecord
    {
        if ($points <= 0) {
            throw new RuntimeException('Poin penghargaan harus lebih dari nol.');
        }

        return PointRecord::create([
            'student_id' => $achievement->student_id,
            'term_id' => $term->id,
            'related_achievement_id' => $achievement->id,
            'type' => 'merit',
            'points' => $points,
            'occurred_on' => $achievement->tanggal_event ?? now()->toDateString(),
            'description' => "Penghargaan prestasi: {$achievement->nama_prestasi}",
            'recorded_by' => $awardedBy->id,
            'status' => 'recorded',
        ]);
    }

    /**
     * Excludes the row from every balance from this point on. The row itself,
     * and the points it carried, stay exactly as written - only status and the
     * four columns that explain the reversal change.
     */
    public function revoke(PointRecord $record, User $revokedBy, string $reason): void
    {
        if (! $record->isActive()) {
            throw new RuntimeException('Catatan ini sudah dibatalkan sebelumnya.');
        }

        $record->forceFill([
            'status' => 'revoked',
            'revoked_by' => $revokedBy->id,
            'revoked_at' => now(),
            'revoke_reason' => $reason,
        ])->save();
    }

    /** SUM(points) over active rows for one student in one term - never a stored column. */
    public function balance(Student $student, Term $term): int
    {
        return (int) PointRecord::where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->active()
            ->sum('points');
    }
}
