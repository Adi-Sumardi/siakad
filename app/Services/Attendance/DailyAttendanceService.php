<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\DailyRecord;
use App\Models\Enrollment;
use App\Models\DailySession;
use App\Models\DailyAttendanceSetting;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of daily_records, and the owner of the daily session
 * lifecycle (DESAIN-PRESENSI-HARIAN.md). Sessions open themselves from the
 * unit's settings - no human "opens" anything in the normal day - and the
 * morning window closes itself by sweeping every still-unmarked student into
 * an 'alpa' row, so a forgotten homeroom marking leaves a hole in the WA
 * notification, not in the data. Corrections supersede: re-marking a student
 * revokes the old row and writes a fresh one, exactly the shape
 * AttendanceLedger::recordBulk established for per-lesson attendance.
 *
 * Since the daily layer became the official attendance source (§8), the
 * H/S/I/A summaries the wali portal, the rapor PDF and the enrollment rollup
 * the watchlist reads are all computed here - from days, not lesson periods.
 *
 * All wall-clock reasoning runs on Carbon::now('Asia/Jakarta') - the daily
 * layer would otherwise roll over at 07:00 WIB while app.timezone is still
 * UTC (see the migration docblock).
 */
class DailyAttendanceService
{
    /** The description closeAndSweep stamps on its auto-alpa rows - resyncTodayWindows() finds them back by it. */
    public const AUTO_SWEEP_DESCRIPTION = 'Tidak tercatat hingga sesi ditutup otomatis.';

    public function __construct(
        private DailyAttendanceNotifier $notifier,
        private RotatingQrService $qr,
    ) {}

    /** First-or-create the unit's settings row, pre-filled with the jenjang's default mode and sane bells. */
    public function ensureSettings(SchoolUnit $unit): DailyAttendanceSetting
    {
        return DailyAttendanceSetting::firstOrCreate(
            ['school_unit_id' => $unit->id],
            [
                'enabled' => false,
                'days' => [1, 2, 3, 4, 5],
                'masuk_opens_at' => '06:30:00',
                'masuk_closes_at' => '08:00:00',
                'masuk_late_after' => '07:15:00',
                'pulang_enabled' => false,
                'pulang_opens_at' => '14:30:00',
                'pulang_closes_at' => '17:00:00',
                'intake_mode' => DailyAttendanceSetting::defaultModeFor($unit),
                'notify_masuk' => true,
                'notify_pulang' => true,
                'notify_absent' => true,
            ],
        );
    }

    /**
     * The scheduler's opening pass: for every unit whose settings say "today
     * is an attendance day", make sure today's masuk (and pulang) sessions
     * exist. firstOrCreate against the (unit, date, type) unique is the whole
     * idempotency story - a twice-fired cron creates nothing the second time
     * (R5). No active term means no school day as far as the ledger is
     * concerned: nothing to write records against, so nothing opens.
     *
     * @return Collection<int, DailySession>
     */
    public function ensureSessionsForDate(DailyAttendanceSetting $setting, ?Carbon $date = null): Collection
    {
        $date ??= Carbon::now('Asia/Jakarta');

        if (! $setting->enabled || ! $setting->runsOn($date->dayOfWeekIso) || ! Term::current()) {
            return collect();
        }

        $types = $setting->pulang_enabled ? ['masuk', 'pulang'] : ['masuk'];

        return collect($types)->map(fn (string $type) => $this->firstOrCreateSession($setting, $date, $type));
    }

    private function firstOrCreateSession(DailyAttendanceSetting $setting, Carbon $date, string $type): DailySession
    {
        [$opens, $closes, $lateAfter] = $this->windowTimes($setting, $type);

        try {
            return DailySession::firstOrCreate(
                ['school_unit_id' => $setting->school_unit_id, 'date' => $date->toDateString(), 'type' => $type],
                [
                    'opens_at' => $date->copy()->setTimeFromTimeString($opens),
                    'closes_at' => $date->copy()->setTimeFromTimeString($closes),
                    'late_after' => $lateAfter,
                    'status' => 'open',
                ],
            );
        } catch (QueryException) {
            // Lost the race to another process creating the same (unit, date,
            // type) - the unique index already caught it; whoever won is the
            // session to use. Same recovery shape as AttendanceSessionService::open().
            return DailySession::where('school_unit_id', $setting->school_unit_id)
                ->whereDate('date', $date->toDateString())
                ->where('type', $type)
                ->firstOrFail();
        }
    }

    /** The unit's current bells for one window type: [opens "HH:MM:SS", closes "HH:MM:SS", late_after or null]. */
    private function windowTimes(DailyAttendanceSetting $setting, string $type): array
    {
        return match ($type) {
            'masuk' => [$setting->masuk_opens_at, $setting->masuk_closes_at, $setting->masuk_late_after],
            'pulang' => [$setting->pulang_opens_at, $setting->pulang_closes_at, null],
        };
    }

    /**
     * Applies a bells edit to TODAY's sessions - the "picked up the same day"
     * promise the sweep command's docblock makes. The snapshot design (§7)
     * protects HISTORY, not a live window: a session still open gets its
     * window refreshed in place, and a session that already closed is
     * REOPENED when the new window still lies ahead - opened_by records who
     * did it, exactly what daily_sessions.opened_by was reserved for - and
     * the alpa rows its old close auto-swept are revoked with a reason: they
     * described a window that no longer exists, and their students must be
     * free to check in again. A closed session whose new window is also
     * behind us stays closed: that is history now.
     */
    public function resyncTodayWindows(DailyAttendanceSetting $setting, User $by, ?Carbon $now = null): void
    {
        $now ??= Carbon::now('Asia/Jakarta');

        $sessions = DailySession::where('school_unit_id', $setting->school_unit_id)
            ->whereDate('date', $now->toDateString())
            ->get();

        if (! $setting->enabled) {
            // The unit switched itself off mid-day: its open windows close
            // quietly - no auto-alpa is invented for a window the unit itself
            // withdrew.
            $sessions->where('status', 'open')
                ->each(fn (DailySession $s) => $s->forceFill(['status' => 'closed', 'closed_at' => $now])->save());

            return;
        }

        foreach ($sessions as $session) {
            // Pulang withdrawn mid-day: its open window closes quietly - no
            // alpa is invented for a window the unit itself took away.
            if ($session->type === 'pulang' && ! $setting->pulang_enabled) {
                if ($session->status === 'open') {
                    $session->forceFill(['status' => 'closed', 'closed_at' => $now])->save();
                }

                continue;
            }

            [$opens, $closes, $lateAfter] = $this->windowTimes($setting, $session->type);

            if ($session->status === 'open') {
                $session->forceFill([
                    'opens_at' => $now->copy()->setTimeFromTimeString($opens),
                    'closes_at' => $now->copy()->setTimeFromTimeString($closes),
                    'late_after' => $lateAfter,
                ])->save();

                continue;
            }

            $newClosesAt = $now->copy()->setTimeFromTimeString($closes);

            if ($newClosesAt->lte($now)) {
                continue; // the new window is over too - leave history alone
            }

            DB::transaction(function () use ($session, $opens, $newClosesAt, $lateAfter, $by, $now) {
                $session->forceFill([
                    'opens_at' => $now->copy()->setTimeFromTimeString($opens),
                    'closes_at' => $newClosesAt,
                    'late_after' => $lateAfter,
                    'status' => 'open',
                    'closed_at' => null,
                    'opened_by' => $by->id,
                ])->save();

                // Only the machine's own alpa rows, never a human's mark - a
                // corrected-to-sakit row was already superseded, and a TU's
                // manual alpa was somebody's decision, not the window's.
                DailyRecord::where('daily_session_id', $session->id)
                    ->active()
                    ->where('attendance_status', 'alpa')
                    ->where('description', self::AUTO_SWEEP_DESCRIPTION)
                    ->get()
                    ->each(function (DailyRecord $record) use ($by) {
                        $this->revoke($record, $by, 'Jendela absen diubah admin - alpa otomatis dibatalkan, siswa dapat absen ulang.');
                        $this->syncEnrollmentRollup($record->student);
                    });
            });
        }
    }

    /**
     * Everything a marking screen needs for one session: the unit's active
     * students, their current rombel, and any live mark. Optionally narrowed
     * to specific classrooms - that is how a homeroom teacher gets "their"
     * roster out of a unit-wide session.
     *
     * @param  Collection<int, int>|null  $classroomIds
     * @return Collection<int, array<string, mixed>>
     */
    public function roster(DailySession $session, ?Collection $classroomIds = null): Collection
    {
        $term = Term::current();
        $yearId = $term?->academic_year_id;

        $students = Student::query()
            ->active()
            ->where('school_unit_id', $session->school_unit_id)
            ->when($classroomIds !== null, function ($q) use ($classroomIds, $yearId) {
                // Placed students are narrowed to the given rooms; the
                // unplaced (belum ber-rombel) stay visible only when no
                // classroom filter is asked for - a homeroom teacher's board
                // is their own roster, not the unit's leftovers. An empty
                // room list fails CLOSED: it sees nobody, not everybody.
                if ($classroomIds->isEmpty()) {
                    return $q->whereRaw('1 = 0');
                }

                return $q->whereHas('enrollments', fn ($e) => $e->whereIn('classroom_id', $classroomIds)
                    ->where('status', 'active')
                    ->when($yearId, fn ($ey) => $ey->where('academic_year_id', $yearId)));
            })
            ->orderBy('nama_lengkap')
            ->get();

        // currentEnrollment() is a plain method, not an Eloquent relation, so
        // it cannot be eager loaded - one grouped query instead of N+1.
        $classroomByStudent = Enrollment::query()
            ->whereIn('student_id', $students->pluck('id'))
            ->where('status', 'active')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->with('classroom')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($group) => $group->sortByDesc('joined_on')->first()?->classroom?->name);

        $records = DailyRecord::where('daily_session_id', $session->id)
            ->active()
            ->get()
            ->keyBy('student_id');

        return $students->map(fn (Student $s) => [
            'ulid' => $s->ulid,
            'nama_lengkap' => $s->nama_lengkap,
            'nis' => $s->nis,
            'classroom' => $classroomByStudent->get($s->id),
            'record_ulid' => $records->get($s->id)?->ulid,
            'attendance_status' => $records->get($s->id)?->attendance_status,
            'is_late' => $records->get($s->id)?->is_late ?? false,
            'source' => $records->get($s->id)?->source,
            'marked_at' => $records->get($s->id)?->checked_in_at ?: $records->get($s->id)?->created_at,
        ]);
    }

    /**
     * One manual mark by a wali kelas or TU. 'terlambat' arrives from the UI
     * as its own choice; the ledger stores it as hadir + is_late so rollups
     * keep counting plain H/S/I/A (see the migration docblock). Re-marking a
     * student supersedes their previous live mark instead of failing - a
     * correction after a parent calls in must be one tap, not a revoke dance.
     */
    public function mark(
        DailySession $session,
        Student $student,
        string $status,
        User $by,
        string $source,
        ?string $description = null,
        ?Carbon $at = null,
    ): DailyRecord {
        if (! in_array($status, ['hadir', 'terlambat', 'sakit', 'izin', 'alpa'], true)) {
            throw new RuntimeException('Status presensi tidak dikenal.');
        }

        $term = Term::current();

        if (! $term) {
            throw new RuntimeException('Tidak ada semester aktif - presensi tidak bisa dicatat saat ini.');
        }

        $at ??= Carbon::now('Asia/Jakarta');
        $isLate = $status === 'terlambat';

        $record = DB::transaction(function () use ($session, $student, $status, $by, $source, $description, $at, $isLate, $term) {
            $existing = DailyRecord::where('daily_session_id', $session->id)
                ->where('student_id', $student->id)
                ->active()
                ->first();

            if ($existing) {
                $this->revoke($existing, $by, 'Diperbarui melalui penandaan ulang.');
            }

            return DailyRecord::create([
                'daily_session_id' => $session->id,
                'student_id' => $student->id,
                'classroom_id' => $student->currentEnrollment()?->classroom_id,
                'term_id' => $term->id,
                'date' => $session->date,
                'attendance_status' => $isLate ? 'hadir' : $status,
                'is_late' => $isLate,
                'source' => $source,
                'checked_in_at' => $isLate || $status === 'hadir' ? $at : null,
                'recorded_by' => $by->id,
                'description' => $description,
                'record_status' => 'recorded',
            ]);
        });

        // Outside the transaction on purpose: a WhatsApp send is a network
        // call, and holding row locks for its timeout would serialize every
        // concurrent mark behind one flaky gateway.
        $this->notifier->recorded($record);
        $this->syncEnrollmentRollup($student);

        return $record;
    }

    /**
     * A student's own gate check-in (mode gerbang, §5D). Every layer of the
     * anti-fraud stack is re-checked here, never trusted from the client:
     * rotating QR freshness, GPS radius, device-once, and the one-live-mark
     * rule - with the database's partial unique indexes as the final guard
     * under a session row lock, so two scans racing each other cannot both
     * pass the pre-checks.
     *
     * @param  array{device_id:?string, ip:?string, lat:?float, lng:?float, accuracy:?float, qr_code:?string}  $input
     */
    public function selfCheckIn(DailySession $session, Student $student, array $input): DailyRecord
    {
        if ($session->type !== 'masuk') {
            throw new RuntimeException('Check-in mandiri hanya untuk sesi absen masuk.');
        }

        $setting = $session->schoolUnit->dailyAttendanceSetting;

        if (! $setting || $setting->intake_mode !== 'gerbang') {
            throw new RuntimeException('Unit ini tidak memakai absen gerbang.');
        }

        $term = Term::current();

        if (! $term) {
            throw new RuntimeException('Tidak ada semester aktif - presensi tidak bisa dicatat saat ini.');
        }

        if ($setting->qr_required) {
            if (empty($input['qr_code'])) {
                throw new RuntimeException('Kode QR wajib - scan QR yang tampil di gerbang.');
            }

            if (! $this->qr->verify(RotatingQrService::dailyScope($session->ulid), (string) $input['qr_code'])) {
                throw new RuntimeException('Kode QR tidak dikenali atau sudah kedaluwarsa - scan ulang.');
            }
        }

        if ($setting->geo_required) {
            if (! isset($input['lat'], $input['lng'])) {
                throw new RuntimeException('Izinkan akses lokasi untuk absen di gerbang.');
            }

            $distance = $this->distanceMeters(
                (float) $input['lat'], (float) $input['lng'],
                (float) $setting->gate_lat, (float) $setting->gate_lng,
            );

            if ($distance > (float) $setting->geo_radius_m) {
                throw new RuntimeException('Posisi Anda terdeteksi di luar area sekolah.');
            }

            // The browser's own confidence in metres. A fix worse than half
            // the radius cannot distinguish "at the gate" from "past it", and
            // mock-location apps often hand out implausibly exact or absurdly
            // wide values - either way the position is not evidence (§6 layer 2).
            if (isset($input['accuracy']) && (float) $input['accuracy'] > (float) $setting->geo_radius_m / 2) {
                throw new RuntimeException(
                    'Sinyal GPS Anda kurang akurat (±'.round((float) $input['accuracy']).' m) - coba lagi di tempat terbuka.'
                );
            }
        }

        $at = Carbon::now('Asia/Jakarta');
        $isLate = $session->late_after !== null && $at->format('H:i:s') > $session->late_after->format('H:i:s');

        $deviceHash = ! empty($input['device_id']) ? hash('sha256', (string) $input['device_id']) : null;
        $ipHash = ! empty($input['ip']) ? hash('sha256', (string) $input['ip']) : null;

        try {
            $record = DB::transaction(function () use ($session, $student, $term, $at, $isLate, $deviceHash, $ipHash) {
                // Serialise concurrent scans for this session the same way
                // AttendanceLedger::checkIn does: lock the window, then look.
                DailySession::whereKey($session->id)->lockForUpdate()->first();

                if (DailyRecord::where('daily_session_id', $session->id)
                    ->where('student_id', $student->id)->active()->exists()) {
                    throw new RuntimeException('Sudah tercatat hadir sebelumnya.');
                }

                if ($deviceHash && DailyRecord::query()
                    ->where('device_hash', $deviceHash)
                    ->active()
                    ->whereDate('date', $session->date)
                    ->where('student_id', '!=', $student->id)
                    ->exists()) {
                    // One phone, one NIS per day, across BOTH windows (masuk
                    // and pulang are separate sessions, so a plain per-session
                    // device check let a phone check a friend in at pulang
                    // after checking its owner in at masuk). The owner's own
                    // repeat check-in at pulang is exempt - the rule binds the
                    // device to one student, not to one scan.
                    throw new RuntimeException('Perangkat ini sudah dipakai absen siswa lain hari ini.');
                }

                return DailyRecord::create([
                    'daily_session_id' => $session->id,
                    'student_id' => $student->id,
                    'classroom_id' => $student->currentEnrollment()?->classroom_id,
                    'term_id' => $term->id,
                    'date' => $session->date,
                    'attendance_status' => 'hadir',
                    'is_late' => $isLate,
                    'source' => 'self',
                    'checked_in_at' => $at,
                    'device_hash' => $deviceHash,
                    'ip_hash' => $ipHash,
                    'record_status' => 'recorded',
                ]);
            });
        } catch (QueryException) {
            // One of the partial uniques won a race the pre-checks missed.
            // Which one tripped decides the message the student sees.
            if (DailyRecord::where('daily_session_id', $session->id)
                ->where('student_id', $student->id)->active()->exists()) {
                throw new RuntimeException('Sudah tercatat hadir sebelumnya.');
            }

            throw new RuntimeException('Perangkat ini sudah dipakai absen siswa lain hari ini.');
        }

        $this->notifier->recorded($record);
        $this->syncEnrollmentRollup($student);

        return $record;
    }

    /** Great-circle distance in metres - the GPS radius check (§6 layer 2). */
    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Layer 5 of the anti-fraud stack: the students whose self check-ins at
     * this session share an IP hash with at least one other student. The
     * device-once rule already blocks same-phone buddy punching outright -
     * this catches the incognito-mode workaround, and only a human at the
     * gate can act on it, so it surfaces as a flag, never a block.
     *
     * @return Collection<int, string> student ULIDs to flag on the TU board
     */
    public function suspectedShares(DailySession $session): Collection
    {
        $rows = DailyRecord::where('daily_session_id', $session->id)
            ->active()
            ->where('source', 'self')
            ->whereNotNull('ip_hash')
            ->get(['student_id', 'ip_hash']);

        $suspectIds = $rows
            ->groupBy('ip_hash')
            ->filter(fn (Collection $group) => $group->pluck('student_id')->unique()->count() > 1)
            ->flatMap(fn (Collection $group) => $group->pluck('student_id'))
            ->unique();

        return Student::whereIn('id', $suspectIds)->pluck('ulid');
    }

    /**
     * The newest self check-ins of a session, newest first - the TU board's
     * live feed (§6 layer 4: a human at the gate glancing at names going by
     * is the cheapest fraud detector there is).
     *
     * @return Collection<int, array{ulid:string, nama_lengkap:string, nis:string, checked_in_at:string, is_late:bool}>
     */
    public function recentCheckIns(DailySession $session, int $limit = 8): Collection
    {
        return DailyRecord::where('daily_session_id', $session->id)
            ->active()
            ->where('source', 'self')
            ->with('student:id,ulid,nama_lengkap,nis')
            ->orderByDesc('checked_in_at')
            ->limit($limit)
            ->get()
            ->map(fn (DailyRecord $record) => [
                'ulid' => $record->student->ulid,
                'nama_lengkap' => $record->student->nama_lengkap,
                'nis' => $record->student->nis,
                'checked_in_at' => $record->checked_in_at?->format('H:i') ?? '',
                'is_late' => (bool) $record->is_late,
            ]);
    }

    /**
     * Cross-checks the two attendance layers for one masuk session: who was
     * hadir at the gate but left no lesson record at all (masuk lalu bolos),
     * and who has lesson records without a hadir day (masuk tanpa lewat
     * gerbang / ditandai sakit tapi ikut pelajaran). Only meaningful once the
     * unit actually ran lesson periods that day - a morning board before any
     * lesson opened would otherwise flag the whole school.
     *
     * @return array{available:bool, no_lesson:Collection<int, array{nama_lengkap:string, nis:string}>, no_gate:Collection<int, array{nama_lengkap:string, nis:string}>}
     */
    public function lessonDiscrepancy(DailySession $session): array
    {
        $lessonRanToday = AttendanceSession::query()
            ->whereDate('occurred_on', $session->date->toDateString())
            ->whereHas('classSchedule.classroom', fn ($q) => $q->where('school_unit_id', $session->school_unit_id))
            ->exists();

        if (! $lessonRanToday) {
            return ['available' => false, 'no_lesson' => collect(), 'no_gate' => collect()];
        }

        $dailyRecords = DailyRecord::where('daily_session_id', $session->id)
            ->active()
            ->get(['student_id', 'attendance_status'])
            ->keyBy('student_id');

        $lessonStudentIds = AttendanceRecord::query()
            ->whereDate('occurred_on', $session->date->toDateString())
            ->active()
            ->whereHas('student', fn ($q) => $q->where('school_unit_id', $session->school_unit_id))
            ->distinct()
            ->pluck('student_id');

        $students = Student::query()
            ->whereIn('id', $dailyRecords->keys()->merge($lessonStudentIds)->unique())
            ->get(['id', 'nama_lengkap', 'nis'])
            ->keyBy('id');

        $name = fn (int $id) => ['nama_lengkap' => $students[$id]->nama_lengkap, 'nis' => $students[$id]->nis];

        return [
            'available' => true,
            // Present at the gate, absent from every lesson period.
            'no_lesson' => $dailyRecords
                ->filter(fn ($r, $id) => $r->attendance_status === 'hadir' && ! $lessonStudentIds->contains($id))
                ->keys()
                ->map($name)
                ->values(),
            // Present in lessons, but the day itself is not a hadir day.
            'no_gate' => $lessonStudentIds
                ->filter(fn (int $id) => ! $dailyRecords->has($id) || $dailyRecords[$id]->attendance_status !== 'hadir')
                ->map($name)
                ->values(),
        ];
    }

    /**
     * H/S/I/A in DAYS for one student within one term - the official
     * attendance source (§8) behind the wali portal and the rapor PDF. Only
     * masuk windows count: a pulang row is the same day's story told twice.
     *
     * @return array{hadir:int, sakit:int, izin:int, alpa:int}
     */
    public function summary(Student $student, Term $term): array
    {
        $counts = DailyRecord::where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->active()
            ->whereHas('dailySession', fn ($q) => $q->where('type', 'masuk'))
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
     * Recomputes the enrollment's sick/permit/absent rollup from the daily
     * layer - the counters WatchlistService's absenteeism condition reads.
     * Recomputed, never incremented, and scoped to the enrollment's academic
     * year so a new year starts fresh (the contract the per-lesson rollup
     * established; only the source changed, per §8).
     */
    public function syncEnrollmentRollup(Student $student): void
    {
        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            return;
        }

        $counts = DailyRecord::where('student_id', $student->id)
            ->active()
            ->whereHas('dailySession', fn ($q) => $q->where('type', 'masuk'))
            ->whereHas('term', fn ($q) => $q->where('academic_year_id', $enrollment->academic_year_id))
            ->selectRaw('attendance_status, count(*) as total')
            ->groupBy('attendance_status')
            ->pluck('total', 'attendance_status');

        $enrollment->forceFill([
            'sick_count' => (int) ($counts['sakit'] ?? 0),
            'permit_count' => (int) ($counts['izin'] ?? 0),
            'absent_count' => (int) ($counts['alpa'] ?? 0),
        ])->save();
    }

    /** Excludes the row from every report; the row and its reasoning stay on file (D6). */
    public function revoke(DailyRecord $record, User $revokedBy, string $reason): void
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

    /**
     * The closing pass. A morning session sweeps every active student of the
     * unit who still has no live mark into an 'alpa' row and alerts their
     * guardians - the "data must not have holes because a human forgot"
     * guarantee (§5E). Pulang windows just close: an unscanned dismissal is
     * "belum tercatat pulang" in the report, not an absence to invent.
     *
     * @return int how many students were swept into alpa
     */
    public function closeAndSweep(DailySession $session, ?User $by = null): int
    {
        if ($session->status === 'closed') {
            return 0;
        }

        $term = Term::current();

        [$missed, $records] = DB::transaction(function () use ($session, $by, $term) {
            $session->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
            ])->save();

            if ($session->type !== 'masuk' || ! $term) {
                return [collect(), collect()];
            }

            $marked = DailyRecord::where('daily_session_id', $session->id)
                ->active()
                ->pluck('student_id');

            $missed = Student::query()
                ->active()
                ->where('school_unit_id', $session->school_unit_id)
                ->whereNotIn('id', $marked)
                ->get();

            $records = $missed->map(fn (Student $student) => DailyRecord::create([
                'daily_session_id' => $session->id,
                'student_id' => $student->id,
                'classroom_id' => $student->currentEnrollment()?->classroom_id,
                'term_id' => $term->id,
                'date' => $session->date,
                'attendance_status' => 'alpa',
                'source' => 'tu',
                'description' => self::AUTO_SWEEP_DESCRIPTION,
                'recorded_by' => $by?->id,
                'record_status' => 'recorded',
            ]));

            return [$missed, $records];
        });

        // Same post-commit rule as mark(): the gateway call never runs inside
        // the transaction that wrote the rows it reports on. The rollup rides
        // along for the same reason - a swept alpa changes the watchlist's
        // numbers, so it must land even if the WA send is what fails loudly.
        $records->each(function (DailyRecord $record) {
            $this->notifier->absent($record);
            $this->syncEnrollmentRollup($record->student);
        });

        return $missed->count();
    }

    /** Live counts for session dashboards - hadir incl. terlambat, S/I/A, and how many of the roster are still blank. */
    public function tally(DailySession $session): array
    {
        $roster = $this->roster($session);

        return [
            'total' => $roster->count(),
            'hadir' => $roster->where('attendance_status', 'hadir')->count(),
            'terlambat' => $roster->filter(fn ($r) => $r['attendance_status'] === 'hadir' && $r['is_late'])->count(),
            'sakit' => $roster->where('attendance_status', 'sakit')->count(),
            'izin' => $roster->where('attendance_status', 'izin')->count(),
            'alpa' => $roster->where('attendance_status', 'alpa')->count(),
            'belum' => $roster->where('attendance_status', null)->count(),
        ];
    }
}
