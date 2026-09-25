<?php

namespace App\Services\Academic;

use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Kenaikan kelas: closes one enrollment row and, for the two outcomes that
 * continue the student's education, opens a fresh one for the new academic
 * year. enrollments.status has carried promoted/repeated/left/graduated
 * since the very first migration - this is the first code that ever writes
 * anything but 'active' into it. See docs/03-ERD.md: "kenaikan kelas adalah
 * menambah baris, bukan menimpa classroom_id".
 */
class PromotionService
{
    /**
     * Candidate destination classrooms for one outcome, in the new academic
     * year. `promoted` must be either the source's own unit or one of its
     * next-jenjang units (SchoolUnit::nextUnits()) - a matching unit alone
     * isn't enough, since this school runs multiple independent
     * tingkat-numbering conventions across parallel units (two SMP campuses,
     * two SMA campuses). Expected grade is "one up" within the same jenjang
     * but the destination's ENTRY rung when crossing one (RA's next step is
     * TK's 0, TK-B's is SD's 1). `repeated` never crosses a unit boundary at
     * all - you can only repeat where you already were.
     *
     * @return array{same_unit: Collection<int, Classroom>, other: Collection<int, Classroom>}
     */
    public function eligibleTargetClassrooms(Classroom $source, AcademicYear $newYear, string $outcome): array
    {
        $validUnitIds = $this->validTargetUnitIds($source, $outcome);

        $candidates = Classroom::where('academic_year_id', $newYear->id)
            ->where('is_active', true)
            ->whereIn('school_unit_id', $validUnitIds)
            ->with('schoolUnit')
            ->orderBy('name')
            ->get()
            ->filter(fn (Classroom $c) => (int) $c->tingkat === $this->expectedTingkatFor($source, $outcome, $c->schoolUnit));

        return [
            'same_unit' => $candidates->where('school_unit_id', $source->school_unit_id)->values(),
            'other' => $candidates->where('school_unit_id', '!=', $source->school_unit_id)->values(),
        ];
    }

    /**
     * The grade rung a student should land on in $targetUnit: their current
     * rung for `repeated`, rung+1 within the same jenjang, or the
     * destination jenjang's entry rung when crossing (SchoolUnit::
     * ENTRY_TINGKAT - null falls back to +1 so an unmapped group keeps the
     * historical behaviour rather than blocking everything).
     */
    private function expectedTingkatFor(Classroom $source, string $outcome, SchoolUnit $targetUnit): int
    {
        if ($outcome === 'repeated') {
            return (int) $source->tingkat;
        }

        if ($targetUnit->jenjang_group !== $source->schoolUnit->jenjang_group) {
            return SchoolUnit::entryTingkatFor((string) $targetUnit->jenjang_group)
                ?? (int) $source->tingkat + 1;
        }

        return (int) $source->tingkat + 1;
    }

    /** @return Collection<int, int> */
    private function validTargetUnitIds(Classroom $source, string $outcome): Collection
    {
        if ($outcome === 'repeated') {
            return collect([$source->school_unit_id]);
        }

        return $source->schoolUnit->nextUnits()->pluck('id')->push($source->school_unit_id);
    }

    /**
     * Promotes a whole batch in one transaction. Each entry closes the
     * student's enrollment in $source ('promoted'/'repeated'/'graduated'/
     * 'left' - copied straight onto the row) and, only for the two outcomes
     * that continue schooling here, opens a new active enrollment in the
     * chosen target classroom.
     *
     * @param  Collection<int, array{student: Student, outcome: string, target_classroom: ?Classroom}>  $entries
     * @return Collection<int, Enrollment>
     */
    public function promoteBatch(Classroom $source, AcademicYear $newYear, Collection $entries, User $actor): Collection
    {
        // Seats this batch has already claimed per target classroom (audit
        // T49-b): each entry's capacity check must see the entries ahead of
        // it, or a 40-strong batch walks into a 30-seat room uncounted.
        $inflow = [];

        return DB::transaction(function () use ($source, $newYear, $entries, &$inflow) {
            return $entries->map(function (array $entry) use ($source, $newYear, &$inflow) {
                /** @var Student $student */
                $student = $entry['student'];
                $outcome = $entry['outcome'];
                /** @var ?Classroom $target */
                $target = $entry['target_classroom'] ?? null;

                $current = Enrollment::where('student_id', $student->id)
                    ->where('classroom_id', $source->id)
                    ->where('academic_year_id', $source->academic_year_id)
                    ->where('status', 'active')
                    ->first();

                if (! $current) {
                    throw new RuntimeException("{$student->nama_lengkap} tidak terdaftar aktif di kelas {$source->name} untuk tahun ajaran ini.");
                }

                if (in_array($outcome, ['promoted', 'repeated'], true)) {
                    $this->assertValidTarget($student, $source, $newYear, $outcome, $target, $inflow);
                }

                $current->forceFill([
                    'status' => $outcome,
                    'left_on' => $source->academicYear->ends_on,
                ])->save();

                if (! in_array($outcome, ['promoted', 'repeated'], true)) {
                    // graduated / left: the student's journey through this
                    // app's academic records ends here, on purpose - no new
                    // row. The student row itself must say so too - otherwise
                    // every "active students" count, the unplaced-classroom
                    // alert, and the point-threshold sweep keep treating
                    // alumni as current students.
                    $student->forceFill(['status' => $outcome === 'graduated' ? 'graduated' : 'transferred'])->save();

                    return $current;
                }

                $enrollment = Enrollment::create([
                    'student_id' => $student->id,
                    'classroom_id' => $target->id,
                    'academic_year_id' => $newYear->id,
                    'status' => 'active',
                    'joined_on' => $newYear->starts_on,
                ]);

                $inflow[$target->id] = ($inflow[$target->id] ?? 0) + 1;

                // The student ROW follows the move (audit T41): every
                // downstream system keys on students.school_unit_id - guru
                // scoping (visibleTo), the daily-attendance roster and
                // auto-alpa sweep, FeeRate::resolve's unit rate table,
                // dashboards and student lists. Leaving it on the source
                // unit made a promoted RA->TK student invisible to the TK
                // side while the RA side kept counting them (and billed RA
                // rates). Same transaction as the enrollment swap, so the
                // two can never disagree.
                if ((int) $target->school_unit_id !== (int) $student->school_unit_id) {
                    $student->forceFill(['school_unit_id' => $target->school_unit_id])->save();
                }

                return $enrollment;
            });
        });
    }

    /**
     * Reverts a classroom's executed promotion in one transaction (audit
     * T49-a): each closed source enrollment goes back to 'active', the one
     * enrollment this promotion opened is deleted, and the student row
     * (status + unit - T41's move) returns to the source world. Skips -
     * never orphans - any student who already has grades or bills in the
     * target year, or who has moved on to yet another classroom since;
     * their names come back so the operator knows what is left to fix by
     * hand. A wrong batch used to be a database-only operation.
     *
     * @return array{undone: int, skipped: list<string>}
     */
    public function undoBatch(Classroom $source, User $actor): array
    {
        return DB::transaction(function () use ($source, $actor) {
            $closed = $source->enrollments()
                ->whereIn('status', ['promoted', 'repeated', 'graduated', 'left'])
                ->with('student')
                ->get();

            if ($closed->isEmpty()) {
                throw new RuntimeException('Tidak ada hasil promosi yang bisa dibatalkan untuk kelas ini.');
            }

            $undone = 0;
            $skipped = [];

            foreach ($closed as $sourceEnrollment) {
                $student = $sourceEnrollment->student;

                if (! $student) {
                    continue;
                }

                // Everything the promotion opened sits in enrollments with a
                // higher id; more than one means the student has moved on
                // again through another classroom - undoing here would tear
                // a journey this source is no longer the start of.
                $newer = Enrollment::where('student_id', $student->id)
                    ->where('id', '>', $sourceEnrollment->id)
                    ->orderByDesc('id')
                    ->get();

                if ($newer->count() > 1) {
                    $skipped[] = $student->nama_lengkap.' (sudah pindah lagi)';

                    continue;
                }

                $target = $newer->first();

                if ($target) {
                    $hasGrades = Grade::where('student_id', $student->id)
                        ->whereHas('term', fn ($q) => $q->where('academic_year_id', $target->academic_year_id))
                        ->exists();
                    $hasBills = Bill::where('student_id', $student->id)
                        ->where('academic_year_id', $target->academic_year_id)
                        ->exists();

                    if ($target->status !== 'active' || $hasGrades || $hasBills) {
                        $skipped[] = $student->nama_lengkap.' (sudah ada nilai/tagihan di tahun tujuan)';

                        continue;
                    }

                    $target->delete();
                }

                $sourceEnrollment->forceFill([
                    'status' => 'active',
                    'left_on' => null,
                ])->save();

                $student->forceFill([
                    'status' => 'active',
                    'school_unit_id' => $source->school_unit_id,
                ])->save();

                $undone++;
            }

            if ($undone === 0) {
                throw new RuntimeException('Semua siswa sudah punya nilai atau tagihan di tahun ajaran tujuan - pembatalan otomatis tidak aman, perbaiki manual.');
            }

            ActivityLog::record($actor, 'promotion.undone', $source, [
                'undone' => $undone,
                'skipped' => $skipped,
            ]);

            return ['undone' => $undone, 'skipped' => $skipped];
        });
    }

    /**
     * The real gate - eligibleTargetClassrooms() is only advisory (it builds
     * the picker's option list), so every one of its constraints is
     * re-checked here independently rather than trusted from the request.
     * A caller could otherwise submit any target_classroom_ulid it can name,
     * regardless of what the picker ever offered.
     */
    private function assertValidTarget(Student $student, Classroom $source, AcademicYear $newYear, string $outcome, ?Classroom $target, array $inflow = []): void
    {
        if (! $target) {
            throw new RuntimeException("Kelas tujuan wajib diisi untuk {$student->nama_lengkap}.");
        }

        if (! $target->is_active) {
            throw new RuntimeException("Kelas tujuan untuk {$student->nama_lengkap} sudah tidak aktif.");
        }

        if ((int) $target->academic_year_id !== (int) $newYear->id) {
            throw new RuntimeException("Kelas tujuan untuk {$student->nama_lengkap} bukan kelas di tahun ajaran yang dituju.");
        }

        $sourceYear = $source->academicYear;

        // Promotion is a forward move: a target year that starts on/before
        // the source year is a data mistake (or a picker fed the wrong
        // list), never a promotion.
        $sourceStart = (int) ($sourceYear->starts_on?->year ?? substr((string) $sourceYear->year, 0, 4));
        $targetStart = (int) ($newYear->starts_on?->year ?? substr((string) $newYear->year, 0, 4));

        if ((int) $newYear->getKey() !== (int) $sourceYear->getKey() && $targetStart <= $sourceStart) {
            throw new RuntimeException(
                "Tahun ajaran tujuan untuk {$student->nama_lengkap} harus SETELAH tahun ajaran sumber ({$sourceYear->year})."
            );
        }

        $expectedTingkat = $this->expectedTingkatFor($source, $outcome, $target->schoolUnit);

        if ((int) $target->tingkat !== $expectedTingkat) {
            throw new RuntimeException("Kelas tujuan untuk {$student->nama_lengkap} bertingkat {$target->tingkat}, seharusnya {$expectedTingkat}.");
        }

        if (! $this->validTargetUnitIds($source, $outcome)->contains($target->school_unit_id)) {
            throw new RuntimeException("Kelas tujuan untuk {$student->nama_lengkap} bukan unit yang bisa dituju dari {$source->schoolUnit->label}.");
        }

        // Capacity, read under the row lock this transaction already holds
        // (audit T49-b): neither the picker's list nor this gate ever
        // counted seats - the pattern ExtracurricularService::assignStudent
        // got right on day one. Active enrollments plus the seats earlier
        // entries of THIS batch have already claimed.
        $capacity = (int) (Classroom::whereKey($target->id)->lockForUpdate()->value('capacity') ?? 0);

        if ($capacity > 0) {
            $active = Enrollment::where('classroom_id', $target->id)->where('status', 'active')->count();
            $claimed = (int) ($inflow[$target->id] ?? 0);

            if ($active + $claimed >= $capacity) {
                throw new RuntimeException(
                    "Kelas tujuan {$target->name} sudah penuh (kapasitas {$capacity}) untuk {$student->nama_lengkap}."
                );
            }
        }

        $alreadyEnrolled = Enrollment::where('student_id', $student->id)
            ->where('academic_year_id', $newYear->id)
            ->exists();

        if ($alreadyEnrolled) {
            throw new RuntimeException("{$student->nama_lengkap} sudah punya pendaftaran di tahun ajaran tujuan.");
        }
    }
}
