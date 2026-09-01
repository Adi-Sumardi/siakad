<?php

namespace App\Services\Academic;

use App\Models\Extracurricular;
use App\Models\ExtracurricularMember;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of extracurricular_members. Membership is admin/pembina
 * assigned, not self-registered - see docs/06-ROADMAP.md - so the checks
 * here are about not double-enrolling or overfilling a roster, not about
 * approval workflow.
 */
class ExtracurricularService
{
    /**
     * Locks the activity row for the duration of the check-then-insert, so
     * two near-simultaneous assignments (an admin and a pembina both
     * enrolling a student into the last open slot at once) can't both pass
     * the duplicate/capacity checks before either has written - the table
     * has no DB-level constraint of its own to catch that.
     */
    public function assignStudent(Extracurricular $ekskul, Student $student, User $actor): ExtracurricularMember
    {
        return DB::transaction(function () use ($ekskul, $student, $actor) {
            $ekskul = Extracurricular::whereKey($ekskul->id)->lockForUpdate()->first();

            if ($ekskul->school_unit_id !== null && $ekskul->school_unit_id !== $student->school_unit_id) {
                throw new RuntimeException("{$student->nama_lengkap} bukan siswa unit yang sama dengan {$ekskul->name}.");
            }

            $alreadyActive = ExtracurricularMember::where('extracurricular_id', $ekskul->id)
                ->where('student_id', $student->id)
                ->where('status', 'active')
                ->exists();

            if ($alreadyActive) {
                throw new RuntimeException("{$student->nama_lengkap} sudah menjadi anggota aktif {$ekskul->name}.");
            }

            if ($ekskul->capacity !== null) {
                $activeCount = $ekskul->activeMembers()->count();

                if ($activeCount >= $ekskul->capacity) {
                    throw new RuntimeException("{$ekskul->name} sudah penuh ({$ekskul->capacity} siswa).");
                }
            }

            return ExtracurricularMember::create([
                'extracurricular_id' => $ekskul->id,
                'student_id' => $student->id,
                'academic_year_id' => $ekskul->academic_year_id,
                'status' => 'active',
                'joined_on' => now()->toDateString(),
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);
        });
    }

    /** Marks the roster row as left rather than deleting it - membership history stays on file. */
    public function removeStudent(ExtracurricularMember $member): void
    {
        if ($member->status !== 'active') {
            throw new RuntimeException('Anggota ini sudah tidak aktif.');
        }

        $member->forceFill([
            'status' => 'left',
            'left_on' => now()->toDateString(),
        ])->save();
    }
}
