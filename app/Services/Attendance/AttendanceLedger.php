<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of attendance_records.
 *
 * Every row also feeds a stored rollup on the student's active enrollment
 * (absent_count/sick_count/permit_count) - kept there for a named
 * performance reason (a homeroom teacher opens that list daily and it must
 * not scan a year of attendance rows). This service recomputes those three
 * counters from the ledger rather than incrementing them, and only at
 * session-close time (see AttendanceSessionService::close() callers), not
 * per check-in - a classroom of students checking in within the same minute
 * must not each trigger their own recompute query.
 */
class AttendanceLedger
{
    /** Whether this student already has a live mark in this session - the "once per session" rule, enforced here so both lookup() and checkIn() controllers can ask the same question. */
    public function hasCheckedIn(AttendanceSession $session, Student $student): bool
    {
        return AttendanceRecord::where('attendance_session_id', $session->id)
            ->where('student_id', $student->id)
            ->active()
            ->exists();
    }

    /**
     * Self-service check-in. Locks the session row for the duration of the
     * check-then-insert so two near-simultaneous scans for the same student
     * (a flaky retry, a double-tap) can't both pass hasCheckedIn() before
     * either has written - the second waits for the lock and then sees the
     * first's row. Throws if there's no active term rather than writing a
     * term_id the schema does not allow to be null.
     */
    public function checkIn(AttendanceSession $session, Student $student): AttendanceRecord
    {
        return DB::transaction(function () use ($session, $student) {
            AttendanceSession::whereKey($session->id)->lockForUpdate()->first();

            if ($this->hasCheckedIn($session, $student)) {
                throw new RuntimeException('Sudah tercatat hadir sebelumnya.');
            }

            $schedule = $session->classSchedule;
            $term = Term::current();

            if (! $term) {
                throw new RuntimeException('Tidak ada semester aktif - presensi tidak bisa dicatat saat ini.');
            }

            return AttendanceRecord::create([
                'student_id' => $student->id,
                'attendance_session_id' => $session->id,
                'classroom_id' => $schedule->classroom_id,
                'term_id' => $term->id,
                'attendance_status' => 'hadir',
                'occurred_on' => $session->occurred_on,
                'source' => 'self',
                'recorded_by' => null,
                'record_status' => 'recorded',
            ]);
        });
    }

    /**
     * A teacher marking the rest of a session's roster - students who never
     * checked in themselves (sick, permitted absence, unexcused, or a
     * teacher-witnessed "hadir" for someone whose scan didn't go through).
     * Resubmitting for a student who already has a live mark in this session
     * supersedes it (revoke the old, write the new) rather than refusing -
     * the same idempotent-by-resubmission shape recordBulk always had.
     *
     * @param  Collection<int, array{student: Student, status: string, description?: ?string}>  $entries
     * @return Collection<int, AttendanceRecord>
     */
    public function recordBulk(Collection $entries, AttendanceSession $session, User $recordedBy): Collection
    {
        $schedule = $session->classSchedule;
        $term = Term::current();

        if ($entries->isNotEmpty() && ! $term) {
            throw new RuntimeException('Tidak ada semester aktif - presensi tidak bisa dicatat saat ini.');
        }

        return DB::transaction(function () use ($entries, $session, $schedule, $term, $recordedBy) {
            return $entries->map(function (array $entry) use ($session, $schedule, $term, $recordedBy) {
                /** @var Student $student */
                $student = $entry['student'];

                $existing = AttendanceRecord::where('attendance_session_id', $session->id)
                    ->where('student_id', $student->id)
                    ->active()
                    ->first();

                if ($existing) {
                    $this->revoke($existing, $recordedBy, 'Diperbarui melalui penyelesaian presensi oleh guru.', false);
                }

                return AttendanceRecord::create([
                    'student_id' => $student->id,
                    'attendance_session_id' => $session->id,
                    'classroom_id' => $schedule->classroom_id,
                    'term_id' => $term?->id,
                    'attendance_status' => $entry['status'],
                    'occurred_on' => $session->occurred_on,
                    'source' => 'guru',
                    'description' => $entry['description'] ?? null,
                    'recorded_by' => $recordedBy->id,
                    'record_status' => 'recorded',
                ]);
            });
        });
    }

    /** Excludes the row from every rollup/report from now on; the row itself stays on file. */
    public function revoke(AttendanceRecord $record, User $revokedBy, string $reason, bool $syncRollup = true): void
    {
        if (! $record->isActive()) {
            throw new RuntimeException('Catatan presensi ini sudah dibatalkan sebelumnya.');
        }

        $record->forceFill([
            'record_status' => 'revoked',
            'revoked_by' => $revokedBy->id,
            'revoked_at' => now(),
            'revoke_reason' => $reason,
        ])->save();

        if ($syncRollup) {
            $this->syncEnrollmentRollup($record->student);
        }
    }

    /** H/S/I/A counts for one student within one term - computed on read. */
    public function summary(Student $student, Term $term): array
    {
        $counts = AttendanceRecord::where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->active()
            ->selectRaw('attendance_status, count(*) as total')
            ->groupBy('attendance_status')
            ->pluck('total', 'attendance_status');

        return [
            'hadir' => (int) ($counts['hadir'] ?? 0),
            'sakit' => (int) ($counts['sakit'] ?? 0),
            'izin' => (int) ($counts['izin'] ?? 0),
            'alpa' => (int) ($counts['alpa'] ?? 0),
        ];
    }

    /**
     * Recomputes enrollments.sick_count/permit_count/absent_count for the
     * student's current enrollment, scoped to that enrollment's academic
     * year (an enrollment is one row per student per year; a new year means
     * a new enrollment row with counters starting fresh).
     */
    public function syncEnrollmentRollup(Student $student): void
    {
        $enrollment = $student->currentEnrollment();
        if (! $enrollment) {
            return;
        }

        $counts = AttendanceRecord::where('student_id', $student->id)
            ->whereHas('term', fn ($q) => $q->where('academic_year_id', $enrollment->academic_year_id))
            ->active()
            ->selectRaw('attendance_status, count(*) as total')
            ->groupBy('attendance_status')
            ->pluck('total', 'attendance_status');

        $enrollment->forceFill([
            'sick_count' => (int) ($counts['sakit'] ?? 0),
            'permit_count' => (int) ($counts['izin'] ?? 0),
            'absent_count' => (int) ($counts['alpa'] ?? 0),
        ])->save();
    }
}
