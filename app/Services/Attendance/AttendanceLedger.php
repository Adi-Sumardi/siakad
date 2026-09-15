<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of attendance_records - the per-lesson layer that lives on
 * for SMP/SMA as attendance DETAIL (DESAIN-PRESENSI-HARIAN.md §8). The
 * official rollups moved to the daily layer: the H/S/I/A-in-days summary and
 * the enrollment counters the watchlist reads are recomputed by
 * DailyAttendanceService, never from these rows.
 */
class AttendanceLedger
{
    public function __construct(private RotatingQrService $qr) {}

    /** Whether this student already has a live mark in this session - the "once per session" rule, enforced here so both lookup() and checkIn() controllers can ask the same question. */
    public function hasCheckedIn(AttendanceSession $session, Student $student): bool
    {
        return AttendanceRecord::where('attendance_session_id', $session->id)
            ->where('student_id', $student->id)
            ->active()
            ->exists();
    }

    /**
     * Self-service check-in. The rotating code from the teacher's screen is
     * mandatory - without it the session's static token URL alone would be a
     * shareable credential - and the device-once rule (one phone, one NIS per
     * session, the same partial-index contract daily_records enforces) closes
     * the buddy-punching lane. Locks the session row for the duration of the
     * check-then-insert so two near-simultaneous scans for the same student
     * (a flaky retry, a double-tap) can't both pass hasCheckedIn() before
     * either has written - the second waits for the lock and then sees the
     * first's row. Throws if there's no active term rather than writing a
     * term_id the schema does not allow to be null.
     */
    public function checkIn(AttendanceSession $session, Student $student, ?string $deviceId = null, ?string $qrCode = null): AttendanceRecord
    {
        if (empty($qrCode)) {
            throw new RuntimeException('Kode QR wajib - scan QR yang tampil di layar guru.');
        }

        if (! $this->qr->verify(RotatingQrService::lessonScope($session->ulid), (string) $qrCode)) {
            throw new RuntimeException('Kode QR tidak dikenali atau sudah kedaluwarsa - scan ulang.');
        }

        $deviceHash = ! empty($deviceId) ? hash('sha256', (string) $deviceId) : null;

        try {
            return DB::transaction(function () use ($session, $student, $deviceHash) {
                AttendanceSession::whereKey($session->id)->lockForUpdate()->first();

                if ($this->hasCheckedIn($session, $student)) {
                    throw new RuntimeException('Sudah tercatat hadir sebelumnya.');
                }

                if ($deviceHash && AttendanceRecord::where('attendance_session_id', $session->id)
                    ->where('device_hash', $deviceHash)->active()->exists()) {
                    throw new RuntimeException('Perangkat ini sudah dipakai untuk presensi sesi ini.');
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
                    'device_hash' => $deviceHash,
                    'recorded_by' => null,
                    'record_status' => 'recorded',
                ]);
            });
        } catch (QueryException) {
            // The device partial unique won a race the pre-check missed -
            // the student pre-check cannot race itself (same student, same
            // lock), so the collision is the device index by elimination.
            throw new RuntimeException('Perangkat ini sudah dipakai untuk presensi sesi ini.');
        }
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
                    $this->revoke($existing, $recordedBy, 'Diperbarui melalui penyelesaian presensi oleh guru.');
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
    public function revoke(AttendanceRecord $record, User $revokedBy, string $reason): void
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
    }
}
