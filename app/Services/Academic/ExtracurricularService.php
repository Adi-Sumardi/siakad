<?php

namespace App\Services\Academic;

use App\Models\Extracurricular;
use App\Models\ExtracurricularMember;
use App\Models\Student;
use App\Models\User;
use RuntimeException;

/**
 * The only writer of extracurricular_members. Membership is admin/pembina
 * assigned, not self-registered - see docs/06-ROADMAP.md - so the checks
 * here are about not double-enrolling or overfilling a roster, not about
 * approval workflow.
 */
class ExtracurricularService
{
    public function assignStudent(Extracurricular $ekskul, Student $student, User $actor): ExtracurricularMember
    {
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
