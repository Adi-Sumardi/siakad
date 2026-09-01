<?php

namespace App\Services\Academic;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
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
     * year. `promoted` steps the grade level up by exactly one AND must be
     * either the source's own unit or one of its next-jenjang units
     * (SchoolUnit::nextUnits()) - a matching tingkat number alone isn't
     * enough, since this school runs multiple independent tingkat-numbering
     * conventions across parallel units (two SMP campuses, two SMA
     * campuses). `repeated` never crosses a unit boundary at all - you can
     * only repeat where you already were.
     *
     * @return array{same_unit: Collection<int, Classroom>, other: Collection<int, Classroom>}
     */
    public function eligibleTargetClassrooms(Classroom $source, AcademicYear $newYear, string $outcome): array
    {
        $targetTingkat = $outcome === 'repeated' ? $source->tingkat : $source->tingkat + 1;
        $validUnitIds = $this->validTargetUnitIds($source, $outcome);

        $candidates = Classroom::where('academic_year_id', $newYear->id)
            ->where('tingkat', $targetTingkat)
            ->where('is_active', true)
            ->whereIn('school_unit_id', $validUnitIds)
            ->with('schoolUnit')
            ->orderBy('name')
            ->get();

        return [
            'same_unit' => $candidates->where('school_unit_id', $source->school_unit_id)->values(),
            'other' => $candidates->where('school_unit_id', '!=', $source->school_unit_id)->values(),
        ];
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
        return DB::transaction(function () use ($source, $newYear, $entries) {
            return $entries->map(function (array $entry) use ($source, $newYear) {
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
                    $this->assertValidTarget($student, $source, $newYear, $outcome, $target);
                }

                $current->forceFill([
                    'status' => $outcome,
                    'left_on' => $source->academicYear->ends_on,
                ])->save();

                if (! in_array($outcome, ['promoted', 'repeated'], true)) {
                    // graduated / left: the student's journey through this
                    // app's academic records ends here, on purpose - no new
                    // row.
                    return $current;
                }

                return Enrollment::create([
                    'student_id' => $student->id,
                    'classroom_id' => $target->id,
                    'academic_year_id' => $newYear->id,
                    'status' => 'active',
                    'joined_on' => $newYear->starts_on,
                ]);
            });
        });
    }

    /**
     * The real gate - eligibleTargetClassrooms() is only advisory (it builds
     * the picker's option list), so every one of its constraints is
     * re-checked here independently rather than trusted from the request.
     * A caller could otherwise submit any target_classroom_ulid it can name,
     * regardless of what the picker ever offered.
     */
    private function assertValidTarget(Student $student, Classroom $source, AcademicYear $newYear, string $outcome, ?Classroom $target): void
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

        $expectedTingkat = $outcome === 'repeated' ? $source->tingkat : $source->tingkat + 1;

        if ((int) $target->tingkat !== $expectedTingkat) {
            throw new RuntimeException("Kelas tujuan untuk {$student->nama_lengkap} bertingkat {$target->tingkat}, seharusnya {$expectedTingkat}.");
        }

        if (! $this->validTargetUnitIds($source, $outcome)->contains($target->school_unit_id)) {
            throw new RuntimeException("Kelas tujuan untuk {$student->nama_lengkap} bukan unit yang bisa dituju dari {$source->schoolUnit->label}.");
        }

        $alreadyEnrolled = Enrollment::where('student_id', $student->id)
            ->where('academic_year_id', $newYear->id)
            ->exists();

        if ($alreadyEnrolled) {
            throw new RuntimeException("{$student->nama_lengkap} sudah punya pendaftaran di tahun ajaran tujuan.");
        }
    }
}
