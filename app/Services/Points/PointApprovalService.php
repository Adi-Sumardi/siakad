<?php

namespace App\Services\Points;

use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\PointApproval;
use App\Models\PointRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The approval ladder a teacher-written point record climbs before it counts
 * (mentor model, 15 Okt 2026):
 *
 *   pelanggaran: guru -> wali kelas -> waka kesiswaan -> kepala sekolah (berat only)
 *   apresiasi:   guru -> wali kelas
 *
 * While climbing the row is 'pending' and sits outside every balance. The
 * last approval flips it to 'recorded'; any rejection ends it as 'rejected'.
 *
 * A stage is skipped - and the skip written down - when nobody needs to look:
 * the recorder (or an earlier approver) already holds that seat, or the class
 * has no wali kelas. Waka/kepsek stages are never skipped for vacancy: the
 * unit's admin_unit decides them instead until the seat is filled, so a
 * 'berat' case can never slide through unseen.
 */
class PointApprovalService
{
    /** @return list<string> the stages this record has to clear, in order */
    public function stagesFor(PointRecord $record): array
    {
        if ($record->type === 'merit') {
            return ['wali_kelas'];
        }

        return $record->severity === 'berat'
            ? ['wali_kelas', 'waka_kesiswaan', 'kepala_sekolah']
            : ['wali_kelas', 'waka_kesiswaan'];
    }

    /** Puts a freshly written (pending) record on its first open stage - or straight to recorded. */
    public function start(PointRecord $record, User $recorder): PointRecord
    {
        return $this->advance($record, null, [$recorder->id]);
    }

    public function approve(PointRecord $record, User $user, ?string $note = null): PointRecord
    {
        return DB::transaction(function () use ($record, $user, $note) {
            $record = PointRecord::lockForUpdate()->findOrFail($record->id);
            $this->assertCanDecide($record, $user);

            PointApproval::create([
                'point_record_id' => $record->id,
                'stage' => $record->approval_stage,
                'decision' => 'approved',
                'decided_by' => $user->id,
                'note' => $note,
            ]);

            $deciders = $record->approvals()->whereNotNull('decided_by')->pluck('decided_by')
                ->push($record->recorded_by)->filter()->unique()->values()->all();

            return $this->advance($record, $record->approval_stage, $deciders);
        });
    }

    public function reject(PointRecord $record, User $user, string $reason): PointRecord
    {
        return DB::transaction(function () use ($record, $user, $reason) {
            $record = PointRecord::lockForUpdate()->findOrFail($record->id);
            $this->assertCanDecide($record, $user);

            PointApproval::create([
                'point_record_id' => $record->id,
                'stage' => $record->approval_stage,
                'decision' => 'rejected',
                'decided_by' => $user->id,
                'note' => $reason,
            ]);

            $record->forceFill(['status' => 'rejected', 'approval_stage' => null])->save();

            return $record;
        });
    }

    /**
     * Moves past $from to the next stage that actually needs someone. Seats
     * already held by $deciders (recorder + earlier approvers) are skipped,
     * so a wali kelas recording for their own class does not approve
     * themselves, and nobody approves the same row twice.
     *
     * @param  list<int>  $deciders
     */
    private function advance(PointRecord $record, ?string $from, array $deciders): PointRecord
    {
        $stages = $this->stagesFor($record);
        $next = $from === null ? 0 : array_search($from, $stages, true) + 1;

        for ($i = $next; $i < count($stages); $i++) {
            $stage = $stages[$i];
            $skipNote = $this->skipReason($record, $stage, $deciders);

            if ($skipNote === null) {
                $record->forceFill(['status' => 'pending', 'approval_stage' => $stage])->save();

                return $record;
            }

            PointApproval::create([
                'point_record_id' => $record->id,
                'stage' => $stage,
                'decision' => 'skipped',
                'note' => $skipNote,
            ]);
        }

        $record->forceFill(['status' => 'recorded', 'approval_stage' => null])->save();

        return $record;
    }

    /** Why this stage needs nobody, or null when it does. @param list<int> $deciders */
    private function skipReason(PointRecord $record, string $stage, array $deciders): ?string
    {
        if ($stage === 'wali_kelas') {
            $homeroomId = $this->homeroomTeacherId($record);

            if ($homeroomId === null) {
                return 'Kelas siswa belum punya wali kelas.';
            }

            return in_array($homeroomId, $deciders, true) ? 'Dicatat/disetujui langsung oleh wali kelas.' : null;
        }

        $role = $stage; // waka_kesiswaan / kepala_sekolah share the role name
        $seatHolders = $this->seatHolders($record, $role)->pluck('id')->all();

        return array_intersect($seatHolders, $deciders) ? 'Dicatat/disetujui langsung oleh '.PointApproval::STAGE_LABEL[$stage].'.' : null;
    }

    public function canDecide(PointRecord $record, User $user): bool
    {
        if (! $record->isPending() || ! $record->approval_stage) {
            return false;
        }

        $unitId = $record->student?->school_unit_id;

        return match ($record->approval_stage) {
            'wali_kelas' => $user->role === 'guru' && $this->homeroomTeacherId($record) === $user->id,
            'waka_kesiswaan', 'kepala_sekolah' => $this->holdsSeat($user, $record->approval_stage, $unitId),
            default => false,
        };
    }

    private function assertCanDecide(PointRecord $record, User $user): void
    {
        if (! $record->isPending()) {
            throw new RuntimeException('Catatan ini sudah diputuskan.');
        }

        if (! $this->canDecide($record, $user)) {
            throw new RuntimeException('Catatan ini sedang menunggu persetujuan '.PointApproval::STAGE_LABEL[$record->approval_stage].', bukan Anda.');
        }
    }

    /**
     * The seat's own role in the student's unit; while the unit has nobody
     * active in that role, its admin_unit sits in for it (never vacancy-skip).
     */
    private function holdsSeat(User $user, string $role, ?int $unitId): bool
    {
        if (! $unitId || (int) $user->school_unit_id !== (int) $unitId) {
            return false;
        }

        if ($user->role === $role) {
            return true;
        }

        return $user->role === 'admin_unit' && ! User::where('role', $role)->where('school_unit_id', $unitId)->where('is_active', true)->exists();
    }

    private function seatHolders(PointRecord $record, string $role)
    {
        return User::where('role', $role)
            ->where('school_unit_id', $record->student?->school_unit_id)
            ->where('is_active', true)
            ->get(['id']);
    }

    /** The wali kelas of the room the student sat in during the record's term. */
    public function homeroomTeacherId(PointRecord $record): ?int
    {
        $yearId = $record->term?->academic_year_id;

        $classroomId = Enrollment::where('student_id', $record->student_id)
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->orderByRaw("status = 'active' desc")
            ->value('classroom_id');

        return $classroomId ? Classroom::whereKey($classroomId)->value('homeroom_teacher_id') : null;
    }

    /**
     * Every pending record this user may decide right now. Waka/kepsek (or
     * admin_unit sitting in) see their unit's stage; a guru sees the wali
     * kelas stage of students enrolled in a room they homeroom.
     */
    public function queueFor(User $user): Builder
    {
        $query = PointRecord::query()->pending();

        if ($user->role === 'guru') {
            $roomIds = Classroom::where('homeroom_teacher_id', $user->id)->pluck('id');

            return $query->where('approval_stage', 'wali_kelas')
                ->whereHas('student.enrollments', fn ($q) => $q->whereIn('classroom_id', $roomIds)->whereColumn('enrollments.academic_year_id', DB::raw(
                    '(select academic_year_id from terms where terms.id = point_records.term_id)'
                )));
        }

        $stages = collect(['waka_kesiswaan', 'kepala_sekolah'])
            ->filter(fn ($role) => $this->holdsSeat($user, $role, $user->school_unit_id))
            ->values();

        if ($stages->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('approval_stage', $stages)
            ->whereHas('student', fn ($q) => $q->where('school_unit_id', $user->school_unit_id));
    }
}
