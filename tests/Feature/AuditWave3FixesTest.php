<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceSession;
use App\Models\ClassSchedule;
use App\Models\Classroom;
use App\Models\DailyAttendanceSetting;
use App\Models\DailyRecord;
use App\Models\DailySession;
use App\Models\Enrollment;
use App\Models\Holiday;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\AttendanceSessionService;
use App\Services\Attendance\DailyAttendanceService;
use App\Services\Attendance\RotatingQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * Gelombang 3 dari audit fungsional 2026-09-21: the attendance machine.
 * The gate honours its OPENING time (not just the closing one), a scan that
 * races the closing minute loses cleanly against the sweep, the afternoon
 * pulang window gets its own self-service lane on the same public link,
 * lesson sessions refuse to open on a calendar holiday, the sweep's --dry-run
 * writes nothing, and the one-phone-one-student rule spans BOTH layers
 * (gate and lesson) instead of each keeping its own ledger.
 */
class AuditWave3FixesTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $monday;

    private SchoolUnit $smp;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monday = Carbon::create(2026, 9, 14, 7, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($this->monday->copy()->utc());

        $this->smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMPI Al Azhar 12', 'jenjang_group' => 'smp']);

        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        $this->term = Term::create([
            'academic_year_id' => $year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gateSetting(array $overrides = []): DailyAttendanceSetting
    {
        /** @var DailyAttendanceService $service */
        $service = app(DailyAttendanceService::class);
        $setting = $service->ensureSettings($this->smp);

        $setting->forceFill(array_merge([
            'enabled' => true,
            'days' => [1, 2, 3, 4, 5],
            'intake_mode' => 'gerbang',
            'qr_required' => true,
            'geo_required' => true,
            'gate_lat' => -6.2000000,
            'gate_lng' => 106.8000000,
            'geo_radius_m' => 100,
            'public_slug' => 'publik-smp',
        ], $overrides))->save();

        return $setting->fresh();
    }

    /** @return array{0: DailySession, 1: ?DailySession} masuk session and (when enabled) the pulang one */
    private function sessionsForMonday(bool $pulang): array
    {
        /** @var DailyAttendanceService $service */
        $service = app(DailyAttendanceService::class);

        $sessions = $service->ensureSessionsForDate($this->gateSetting(['pulang_enabled' => $pulang]), $this->monday);

        return [
            $sessions->firstWhere('type', 'masuk'),
            $pulang ? $sessions->firstWhere('type', 'pulang') : null,
        ];
    }

    private function studentIn(string $nis): Student
    {
        $classroom = Classroom::create([
            'school_unit_id' => $this->smp->id,
            'academic_year_id' => $this->term->academic_year_id,
            'name' => '7-'.$nis, 'tingkat' => 7,
        ]);

        $student = Student::create([
            'nama_lengkap' => 'Siswa '.$nis, 'nama_panggilan' => 'Panggilan '.$nis,
            'nis' => $nis, 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->smp->id, 'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => $classroom->id,
            'academic_year_id' => $this->term->academic_year_id,
            'status' => 'active', 'joined_on' => '2026-07-01',
        ]);

        return $student->fresh();
    }

    private function gatePayload(DailySession $session, string $nis, string $device): array
    {
        return [
            'nis' => $nis,
            'qr_code' => app(RotatingQrService::class)->code(RotatingQrService::dailyScope($session->ulid)),
            'lat' => -6.2000100, 'lng' => 106.8000100,
            'device_id' => $device,
        ];
    }

    // ---- Opening edge ----------------------------------------------------------

    public function test_the_gate_refuses_scans_before_the_window_opens(): void
    {
        [$masuk] = $this->sessionsForMonday(false);
        $this->studentIn('30001');

        Carbon::setTestNow($this->monday->copy()->setTime(5, 0)->utc()); // 05:00 WIB, window opens 06:30

        $this->getJson('/api/absen/publik-smp')
            ->assertStatus(200)
            ->assertJsonPath('state', 'closed');

        $this->postJson('/api/absen/publik-smp/check-in', $this->gatePayload($masuk, '30001', 'device-early'))
            ->assertStatus(410)
            ->assertJsonPath('message', 'Jendela absen masuk belum dibuka - mulai pukul 06:30 WIB.');
    }

    // ---- Pulang lane ------------------------------------------------------------

    public function test_the_gate_serves_the_afternoon_pulang_window(): void
    {
        [$masuk, $pulang] = $this->sessionsForMonday(true);
        $student = $this->studentIn('30002');

        $masuk->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        Carbon::setTestNow($this->monday->copy()->setTime(15, 0)->utc()); // 15:00 WIB, pulang 14:30-17:00

        $this->getJson('/api/absen/publik-smp')
            ->assertStatus(200)
            ->assertJsonPath('state', 'open')
            ->assertJsonPath('session.type', 'pulang');

        $this->postJson('/api/absen/publik-smp/check-in', $this->gatePayload($pulang, '30002', 'device-pulang'))
            ->assertStatus(200);

        $this->assertDatabaseHas('daily_records', [
            'daily_session_id' => $pulang->id,
            'student_id' => $student->id,
            'attendance_status' => 'hadir',
            'source' => 'self',
        ]);

        // A repeat scan of the pulang window is refused with the pulang word.
        $this->postJson('/api/absen/publik-smp/check-in', $this->gatePayload($pulang, '30002', 'device-pulang'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Sudah tercatat pulang sebelumnya.');
    }

    // ---- Race at the closing minute ---------------------------------------------

    public function test_a_scan_landing_on_a_closed_window_is_refused_cleanly(): void
    {
        [$masuk] = $this->sessionsForMonday(false);
        $student = $this->studentIn('30003');

        // The sweep closed the window a breath before this scan arrived.
        $masuk->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Jendela absen belum dibuka atau sudah ditutup.');

        app(DailyAttendanceService::class)->selfCheckIn($masuk->fresh(), $student, [
            'device_id' => 'device-late',
            'qr_code' => app(RotatingQrService::class)->code(RotatingQrService::dailyScope($masuk->ulid)),
            'lat' => -6.2000100, 'lng' => 106.8000100,
        ]);
    }

    // ---- Lesson sessions on a holiday --------------------------------------------

    public function test_lesson_sessions_refuse_to_open_on_a_holiday(): void
    {
        Holiday::create(['date' => $this->monday->toDateString(), 'label' => 'Hari Raya']);

        $teacher = User::create([
            'name' => 'Guru', 'email' => 'guru'.uniqid().'@yapinet.id',
            'role' => 'guru', 'school_unit_id' => $this->smp->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
        $classroom = Classroom::create([
            'school_unit_id' => $this->smp->id,
            'academic_year_id' => $this->term->academic_year_id,
            'name' => '7-LIBUR', 'tingkat' => 7,
        ]);
        $subject = Subject::create(['code' => 'BINDO-'.uniqid(), 'name' => 'Bahasa Indonesia']);
        $schedule = ClassSchedule::create([
            'classroom_id' => $classroom->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
        ]);

        try {
            app(AttendanceSessionService::class)->open($schedule, $this->monday->copy()->startOfDay(), $teacher);
            $this->fail('Sesi pelajaran seharusnya ditolak di hari libur.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('hari libur', $e->getMessage());
        }

        $this->assertSame(0, AttendanceSession::count());
    }

    // ---- True dry-run --------------------------------------------------------------

    public function test_the_sweep_dry_run_leaves_nothing_behind(): void
    {
        $this->gateSetting(['intake_mode' => 'wali_kelas']);

        $this->artisan('attendance:daily-sweep', ['--dry-run' => true]);

        $this->assertSame(0, DailySession::count(), 'dry-run tidak boleh membuat sesi apa pun');

        $this->artisan('attendance:daily-sweep');

        $this->assertSame(1, DailySession::count(), 'run sungguhan tetap membuka sesi masuk');
    }

    // ---- One phone, one student - across BOTH layers -------------------------------

    public function test_one_phone_cannot_serve_two_students_across_gate_and_lesson_layers(): void
    {
        [$masuk] = $this->sessionsForMonday(false);
        $gateStudent = $this->studentIn('30004');
        $lessonStudent = $this->studentIn('30005');

        // The gate write: student A, device X, 07:00.
        $this->postJson('/api/absen/publik-smp/check-in', $this->gatePayload($masuk, '30004', 'device-X'))
            ->assertStatus(200);

        // The lesson layer must recognise the gate's device ledger.
        $teacher = User::create([
            'name' => 'Guru', 'email' => 'guru'.uniqid().'@yapinet.id',
            'role' => 'guru', 'school_unit_id' => $this->smp->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
        $subject = Subject::create(['code' => 'MTK-'.uniqid(), 'name' => 'Matematika']);
        $schedule = ClassSchedule::create([
            'classroom_id' => $lessonStudent->enrollments->first()->classroom_id,
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'day_of_week' => 1, 'start_time' => '07:30', 'end_time' => '23:59',
        ]);
        $lesson = app(AttendanceSessionService::class)->open($schedule, $this->monday->copy()->startOfDay(), $teacher);

        $ledger = app(AttendanceLedger::class);
        $qr = app(RotatingQrService::class)->code(RotatingQrService::lessonScope($lesson->ulid));

        try {
            $ledger->checkIn($lesson, $lessonStudent, 'device-X', $qr);
            $this->fail('HP yang dipakai siswa lain di gerbang seharusnya ditolak di sesi pelajaran.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Perangkat ini sudah dipakai absen siswa lain', $e->getMessage());
        }

        // ...and the reverse direction: a phone used in a lesson cannot then
        // serve a different student at the gate's pulang window.
        $ledger->checkIn($lesson, $lessonStudent, 'device-Y', $qr);
        [, $pulang] = $this->sessionsForMonday(true);
        Carbon::setTestNow($this->monday->copy()->setTime(15, 0)->utc());

        try {
            app(DailyAttendanceService::class)->selfCheckIn($pulang, $gateStudent, [
                'device_id' => 'device-Y',
                'qr_code' => app(RotatingQrService::class)->code(RotatingQrService::dailyScope($pulang->ulid)),
                'lat' => -6.2000100, 'lng' => 106.8000100,
            ]);
            $this->fail('HP yang dipakai siswa lain di pelajaran seharusnya ditolak di gerbang.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Perangkat ini sudah dipakai presensi siswa lain', $e->getMessage());
        }
    }
}
