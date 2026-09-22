<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\DailyAttendanceSetting;
use App\Models\DailyRecord;
use App\Models\DailySession;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Holiday;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Attendance\DailyAttendanceService;
use App\Services\Attendance\RotatingQrService;
use App\Services\Notification\NotificationResult;
use App\Services\Notification\WhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * The daily attendance layer (T14, DESAIN-PRESENSI-HARIAN.md): sessions that
 * open themselves from per-unit settings, the homeroom-teacher marking board
 * for mode wali_kelas, and the close-time alpa sweep that guarantees no data
 * holes. Scope follows the house rule throughout: another unit's session is
 * a 404, never a 403.
 */
class DailyAttendanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<array{to: string, message: string}>
     *
     * WhatsApp is no longer a channel for daily attendance - the fake stays
     * bound precisely so any accidental rewiring fails these tests loudly.
     */
    private array $sentWa = [];

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    private Term $term;

    /** A Monday, so day-of-week logic is deterministic regardless of when the suite runs. */
    private Carbon $monday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monday = Carbon::create(2026, 9, 14, 7, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($this->monday->copy()->utc());

        $this->app->bind(WhatsAppGateway::class, fn () => new class($this->sentWa) implements WhatsAppGateway
        {
            public function __construct(private array &$sent) {}

            public function sendMessage(string $phone, string $message): NotificationResult
            {
                $this->sent[] = compact('phone', 'message');

                return NotificationResult::ok();
            }
        });

        $this->sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'TKI/SDI Al Azhar 13', 'jenjang_group' => 'sd']);
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

    private function enabledSetting(SchoolUnit $unit, array $overrides = []): DailyAttendanceSetting
    {
        /** @var DailyAttendanceService $service */
        $service = app(DailyAttendanceService::class);
        $setting = $service->ensureSettings($unit);

        return $setting->forceFill(array_merge([
            'enabled' => true,
            'days' => [1, 2, 3, 4, 5],
        ], $overrides))->save()
            ? $setting->fresh()
            : $setting;
    }

    private function classroomIn(SchoolUnit $unit, ?User $homeroom = null): Classroom
    {
        return Classroom::create([
            'school_unit_id' => $unit->id,
            'academic_year_id' => $this->term->academic_year_id,
            'name' => '1-'.chr(65 + random_int(0, 25)).uniqid(), 'tingkat' => 1,
            'homeroom_teacher_id' => $homeroom?->id,
        ]);
    }

    private function studentIn(Classroom $classroom, string $nis): Student
    {
        $student = Student::create([
            'nama_lengkap' => 'Siswa '.$nis, 'nama_panggilan' => 'Panggilan '.$nis,
            'nis' => $nis, 'jenis_kelamin' => 'P',
            'school_unit_id' => $classroom->school_unit_id, 'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => $classroom->id,
            'academic_year_id' => $this->term->academic_year_id,
            'status' => 'active', 'joined_on' => '2026-07-01',
        ]);

        return $student;
    }

    private function guardianOf(Student $student, string $phone = '081234567890'): Guardian
    {
        $guardian = Guardian::create(['nama' => 'Wali '.$student->nis, 'hubungan' => 'ibu', 'no_hp' => $phone]);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ibu', 'is_primary' => true]);

        return $guardian;
    }

    private function staff(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    /** Opens Monday's sessions for the unit (the lazy path the API uses) and returns the masuk one. */
    private function masukSessionFor(SchoolUnit $unit): DailySession
    {
        /** @var DailyAttendanceService $service */
        $service = app(DailyAttendanceService::class);

        return $service->ensureSessionsForDate($service->ensureSettings($unit), $this->monday)
            ->firstWhere('type', 'masuk');
    }

    // --- Settings ----------------------------------------------------------

    public function test_settings_default_to_the_jenjangs_mode(): void
    {
        /** @var DailyAttendanceService $service */
        $service = app(DailyAttendanceService::class);

        $this->assertSame('wali_kelas', $service->ensureSettings($this->sd)->intake_mode);
        $this->assertSame('gerbang', $service->ensureSettings($this->smp)->intake_mode);
        // A disabled setting is the shipping default - no unit is surprised
        // by auto-alpa sweeps before it has configured itself.
        $this->assertFalse($service->ensureSettings($this->sd)->enabled);
    }

    public function test_admin_unit_reads_and_updates_their_own_unit_settings(): void
    {
        $admin = $this->staff('admin_unit', $this->sd);

        $this->actingAs($admin)->getJson('/api/admin/daily-attendance/settings')
            ->assertStatus(200)
            ->assertJsonPath('intake_mode', 'wali_kelas')
            ->assertJsonPath('enabled', false);

        $this->actingAs($admin)->patchJson('/api/admin/daily-attendance/settings', [
            'enabled' => true,
            'days' => [1, 2, 3, 4, 5],
            'masuk_opens_at' => '06:45',
            'masuk_closes_at' => '08:15',
            'masuk_late_after' => '07:20',
            'pulang_enabled' => true,
            'pulang_opens_at' => '14:30',
            'pulang_closes_at' => '17:00',
            'notify_pulang' => false,
        ])
            ->assertStatus(200)
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('pulang.enabled', true)
            ->assertJsonPath('notifications.pulang', false);

        $this->assertDatabaseHas('daily_settings', ['school_unit_id' => $this->sd->id, 'masuk_late_after' => '07:20:00']);
    }

    public function test_settings_reject_a_close_before_its_open(): void
    {
        $admin = $this->staff('admin_unit', $this->sd);

        $this->actingAs($admin)->patchJson('/api/admin/daily-attendance/settings', [
            'masuk_opens_at' => '08:00',
            'masuk_closes_at' => '07:00',
        ])->assertStatus(422);
    }

    public function test_central_admin_must_name_a_unit_for_settings(): void
    {
        $pusat = $this->staff('admin');

        $this->actingAs($pusat)->getJson('/api/admin/daily-attendance/settings')->assertStatus(404);
        $this->actingAs($pusat)->getJson('/api/admin/daily-attendance/settings?unit='.$this->sd->ulid)
            ->assertStatus(200)
            ->assertJsonPath('unit.ulid', $this->sd->ulid);
    }

    // --- Session lifecycle -------------------------------------------------

    public function test_sessions_open_themselves_on_configured_days_only(): void
    {
        $setting = $this->enabledSetting($this->sd, ['pulang_enabled' => true]);
        /** @var DailyAttendanceService $service */
        $service = app(DailyAttendanceService::class);

        $sessions = $service->ensureSessionsForDate($setting, $this->monday);
        $this->assertSame(['masuk', 'pulang'], $sessions->pluck('type')->sort()->values()->all());

        // Idempotent: the same (unit, date, type) unique means a second sweep
        // returns the very same rows, not duplicates.
        $again = $service->ensureSessionsForDate($setting, $this->monday);
        $this->assertSame(
            $sessions->pluck('ulid')->sort()->values()->all(),
            $again->pluck('ulid')->sort()->values()->all(),
        );

        // Sunday is not an attendance day.
        $sunday = $this->monday->copy()->addDays(6);
        $this->assertCount(0, $service->ensureSessionsForDate($setting, $sunday));

        // Nor is any day at all for a unit that never switched on.
        $this->assertCount(0, $service->ensureSessionsForDate(
            app(DailyAttendanceService::class)->ensureSettings($this->smp),
            $this->monday,
        ));
    }

    // --- Wali kelas marking board ------------------------------------------

    public function test_homeroom_teacher_sees_only_their_own_roster(): void
    {
        $setting = $this->enabledSetting($this->sd);
        $guru = $this->staff('guru', $this->sd);

        $own = $this->classroomIn($this->sd, $guru);
        $other = $this->classroomIn($this->sd);
        $this->studentIn($own, '20001');
        $this->studentIn($other, '20002');

        $response = $this->actingAs($guru)->getJson('/api/guru/daily-attendance/today')
            ->assertStatus(200)
            ->assertJsonPath('enabled', true);

        $masuk = collect($response->json('sessions'))->firstWhere('type', 'masuk');

        $this->assertSame(1, count($masuk['roster']));
        $this->assertSame('20001', $masuk['roster'][0]['nis']);
    }

    public function test_wali_kelas_marks_arrival(): void
    {
        $this->enabledSetting($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $classroom = $this->classroomIn($this->sd, $guru);
        $student = $this->studentIn($classroom, '20003');
        $this->guardianOf($student);

        $session = $this->masukSessionFor($this->sd);

        Carbon::setTestNow($this->monday->copy()->addMinutes(20)->utc()); // 07:20 WIB - late

        $this->actingAs($guru)->postJson("/api/guru/daily-attendance/sessions/{$session->ulid}/records", [
            'student_ulid' => $student->ulid,
            'status' => 'terlambat',
        ])
            ->assertStatus(200)
            ->assertJsonPath('attendance_status', 'hadir')
            ->assertJsonPath('is_late', true);

        // 'terlambat' lands in the ledger as hadir + is_late, so H/S/I/A
        // rollups keep their four plain buckets.
        $this->assertDatabaseHas('daily_records', [
            'daily_session_id' => $session->id, 'student_id' => $student->id,
            'attendance_status' => 'hadir', 'is_late' => true, 'source' => 'wali_kelas',
        ]);

        $this->assertEmpty($this->sentWa);
    }

    public function test_remarking_supersedes_instead_of_duplicating(): void
    {
        $this->enabledSetting($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $classroom = $this->classroomIn($this->sd, $guru);
        $student = $this->studentIn($classroom, '20004');

        $session = $this->masukSessionFor($this->sd);

        /** @var DailyAttendanceService $service */
        $service = app(DailyAttendanceService::class);

        $first = $service->mark($session, $student, 'hadir', $guru, 'wali_kelas');
        $second = $service->mark($session, $student, 'sakit', $guru, 'wali_kelas');

        // The old row stays on file as revoked with a reason; only one live
        // mark per (session, student) remains.
        $this->assertSame('revoked', $first->fresh()->record_status);
        $this->assertNotNull($first->fresh()->revoke_reason);
        $this->assertSame(1, $session->dailyRecords()->active()->where('student_id', $student->id)->count());
        $this->assertSame('sakit', $second->attendance_status);
    }

    public function test_marking_outside_the_teachers_scope_is_a_404(): void
    {
        $this->enabledSetting($this->sd);
        $this->enabledSetting($this->smp);

        $guruSd = $this->staff('guru', $this->sd);
        $classroomSd = $this->classroomIn($this->sd, $guruSd);
        $studentSd = $this->studentIn($classroomSd, '20005');

        $guruSmp = $this->staff('guru', $this->smp);
        $classroomSmp = $this->classroomIn($this->smp, $guruSmp);
        $studentSmp = $this->studentIn($classroomSmp, '20006');

        // Another unit's session - 404, not 403.
        $sessionSd = $this->masukSessionFor($this->sd);

        $this->actingAs($guruSmp)->postJson("/api/guru/daily-attendance/sessions/{$sessionSd->ulid}/records", [
            'student_ulid' => $studentSd->ulid,
            'status' => 'hadir',
        ])->assertStatus(404);

        // Own unit's session but somebody else's homeroom student - 404 too.
        $notMine = $this->studentIn($this->classroomIn($this->sd), '20007');

        $this->actingAs($guruSd)->postJson("/api/guru/daily-attendance/sessions/{$sessionSd->ulid}/records", [
            'student_ulid' => $notMine->ulid,
            'status' => 'hadir',
        ])->assertStatus(404);

        $this->assertDatabaseMissing('daily_records', ['student_id' => $notMine->id]);
        $this->assertDatabaseMissing('daily_records', ['student_id' => $studentSmp->id]);
    }

    // --- Close & sweep -----------------------------------------------------

    public function test_closing_a_morning_window_sweeps_the_unmarked_into_alpa(): void
    {
        $this->enabledSetting($this->sd, ['pulang_enabled' => true]);
        /** @var DailyAttendanceService $service */
        $service = app(DailyAttendanceService::class);

        $guru = $this->staff('guru', $this->sd);
        $classroom = $this->classroomIn($this->sd, $guru);

        $hadir = $this->studentIn($classroom, '20008');
        $bolos = $this->studentIn($classroom, '20009');
        $this->guardianOf($hadir);
        $this->guardianOf($bolos);

        $sessions = $service->ensureSessionsForDate($this->enabledSetting($this->sd, ['pulang_enabled' => true]), $this->monday);
        $masuk = $sessions->firstWhere('type', 'masuk');
        $pulang = $sessions->firstWhere('type', 'pulang');

        $service->mark($masuk, $hadir, 'hadir', $guru, 'wali_kelas');

        $swept = $service->closeAndSweep($masuk);

        $this->assertSame(1, $swept);
        $this->assertSame('alpa', $masuk->dailyRecords()->active()->where('student_id', $bolos->id)->first()->attendance_status);
        $this->assertSame('hadir', $masuk->dailyRecords()->active()->where('student_id', $hadir->id)->first()->attendance_status);

        $this->assertEmpty($this->sentWa);

        // Closing twice sweeps nothing twice.
        $this->assertSame(0, $service->closeAndSweep($masuk));

        // A pulang window closes without inventing absences.
        $this->assertSame(0, $service->closeAndSweep($pulang));
        $this->assertDatabaseMissing('daily_records', ['daily_session_id' => $pulang->id]);
    }

    // --- Admin board -------------------------------------------------------

    public function test_admin_unit_today_board_counts_their_unit(): void
    {
        $this->enabledSetting($this->sd);
        $this->enabledSetting($this->smp);

        $guru = $this->staff('guru', $this->sd);
        $classroom = $this->classroomIn($this->sd, $guru);
        $this->studentIn($classroom, '20011');
        $this->studentIn($classroom, '20012');

        $admin = $this->staff('admin_unit', $this->sd);

        $response = $this->actingAs($admin)->getJson('/api/admin/daily-attendance/today')
            ->assertStatus(200);

        $masuk = collect($response->json('sessions'))->firstWhere('type', 'masuk');

        $this->assertSame(2, $masuk['tally']['total']);
        $this->assertSame(2, $masuk['tally']['belum']);
    }

    // --- Mode gerbang (SMP/SMA) ---------------------------------------------

    /** SMP fully armed: gerbang + QR + 100 m radius around a fixed gate point, with a known public slug. */
    private function gateSetting(array $overrides = []): DailyAttendanceSetting
    {
        return $this->enabledSetting($this->smp, array_merge([
            'intake_mode' => 'gerbang',
            'qr_required' => true,
            'geo_required' => true,
            'gate_lat' => -6.2000000,
            'gate_lng' => 106.8000000,
            'geo_radius_m' => 100,
            'public_slug' => 'publik-smp',
        ], $overrides));
    }

    public function test_the_public_link_issues_once_and_rotates_on_reset(): void
    {
        $admin = $this->staff('admin_unit', $this->smp);

        $first = $this->actingAs($admin)->postJson('/api/admin/daily-attendance/public-link')
            ->assertStatus(200)->json('public_slug');
        $this->assertNotNull($first);

        // Issuing again is a no-op - a unit must never end up with two live links.
        $this->actingAs($admin)->postJson('/api/admin/daily-attendance/public-link')
            ->assertStatus(200)->assertJsonPath('public_slug', $first);

        $rotated = $this->actingAs($admin)->postJson('/api/admin/daily-attendance/public-link/reset')
            ->assertStatus(200)->json('public_slug');

        $this->assertNotSame($first, $rotated);
    }

    public function test_the_gate_page_reports_the_window_state(): void
    {
        $this->gateSetting();
        $this->masukSessionFor($this->smp);

        $this->getJson('/api/absen/publik-smp')
            ->assertStatus(200)
            ->assertJsonPath('state', 'open')
            ->assertJsonPath('unit_label', 'SMPI Al Azhar 12')
            ->assertJsonPath('qr_required', true)
            ->assertJsonPath('geo.required', true);

        $this->getJson('/api/absen/tidak-ada')->assertStatus(404);
    }

    public function test_a_gate_check_in_passes_every_layer(): void
    {
        $this->gateSetting();
        $classroom = $this->classroomIn($this->smp);
        $student = $this->studentIn($classroom, '20020');
        $this->guardianOf($student);
        $session = $this->masukSessionFor($this->smp);

        $this->postJson('/api/absen/publik-smp/check-in', [
            'nis' => '20020',
            'qr_code' => app(RotatingQrService::class)->code(RotatingQrService::dailyScope($session->ulid)),
            'lat' => -6.2000100, 'lng' => 106.8000100, // ~1.5 m from the gate
            'device_id' => 'device-A',
        ])
            ->assertStatus(200)
            ->assertJsonPath('is_late', false)
            ->assertJsonPath('student.nama_panggilan', 'Panggilan 20020');

        // The stored device hash is the sha256 of the client id - never the id itself.
        $this->assertDatabaseHas('daily_records', [
            'student_id' => $student->id,
            'attendance_status' => 'hadir',
            'source' => 'self',
            'device_hash' => hash('sha256', 'device-A'),
        ]);

        $this->assertEmpty($this->sentWa);
    }

    public function test_a_gate_check_in_after_the_late_threshold_is_flagged(): void
    {
        $this->gateSetting();
        $classroom = $this->classroomIn($this->smp);
        $student = $this->studentIn($classroom, '20021');
        $this->guardianOf($student);
        $session = $this->masukSessionFor($this->smp);

        Carbon::setTestNow($this->monday->copy()->addMinutes(20)->utc()); // 07:20 WIB, late_after 07:15

        $this->postJson('/api/absen/publik-smp/check-in', [
            'nis' => '20021',
            'qr_code' => app(RotatingQrService::class)->code(RotatingQrService::dailyScope($session->ulid)),
            'lat' => -6.2000100, 'lng' => 106.8000100,
            'device_id' => 'device-B',
        ])
            ->assertStatus(200)
            ->assertJsonPath('is_late', true);

        $this->assertDatabaseHas('daily_records', ['student_id' => $student->id, 'is_late' => true]);
        $this->assertEmpty($this->sentWa);
    }

    public function test_stale_qr_codes_and_far_away_positions_are_rejected(): void
    {
        $this->gateSetting();
        $classroom = $this->classroomIn($this->smp);
        $student = $this->studentIn($classroom, '20022');
        $session = $this->masukSessionFor($this->smp);

        $qr = app(RotatingQrService::class);
        $stale = $qr->code(RotatingQrService::dailyScope($session->ulid), Carbon::now('Asia/Jakarta')->copy()->subSeconds(120));

        $this->postJson('/api/absen/publik-smp/check-in', [
            'nis' => '20022', 'qr_code' => $stale,
            'lat' => -6.2000100, 'lng' => 106.8000100, 'device_id' => 'device-C',
        ])->assertStatus(422);

        $this->postJson('/api/absen/publik-smp/check-in', [
            'nis' => '20022', 'qr_code' => $qr->code(RotatingQrService::dailyScope($session->ulid)),
            'lat' => -6.2100000, 'lng' => 106.8000000, // ~1.1 km away
            'device_id' => 'device-C',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('daily_records', ['student_id' => $student->id]);
    }

    public function test_a_low_confidence_gps_fix_is_rejected_but_a_tight_one_passes(): void
    {
        $this->gateSetting(); // radius 100 m, so the accuracy ceiling is 50 m
        $classroom = $this->classroomIn($this->smp);
        $student = $this->studentIn($classroom, '20035');
        $session = $this->masukSessionFor($this->smp);

        $payload = fn (float $accuracy) => [
            'nis' => '20035',
            'qr_code' => app(RotatingQrService::class)->code(RotatingQrService::dailyScope($session->ulid)),
            'lat' => -6.2000100, 'lng' => 106.8000100, // ~1.5 m from the gate
            'accuracy' => $accuracy,
            'device_id' => 'device-G',
        ];

        // A fix wider than half the radius cannot tell "at the gate" from
        // "past it" - and no record is written for the try.
        $this->postJson('/api/absen/publik-smp/check-in', $payload(120.0))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sinyal GPS Anda kurang akurat (±120 m) - coba lagi di tempat terbuka.');

        $this->postJson('/api/absen/publik-smp/check-in', $payload(20.0))->assertStatus(200);

        $this->assertDatabaseHas('daily_records', ['student_id' => $student->id, 'attendance_status' => 'hadir']);
    }

    public function test_one_device_cannot_check_in_two_students_and_one_nis_cannot_check_in_twice(): void
    {
        $this->gateSetting();
        $classroom = $this->classroomIn($this->smp);
        $first = $this->studentIn($classroom, '20023');
        $second = $this->studentIn($classroom, '20024');
        $session = $this->masukSessionFor($this->smp);
        $code = app(RotatingQrService::class)->code(RotatingQrService::dailyScope($session->ulid));

        $payload = fn (string $nis, string $device) => [
            'nis' => $nis, 'qr_code' => $code,
            'lat' => -6.2000100, 'lng' => 106.8000100, 'device_id' => $device,
        ];

        $this->postJson('/api/absen/publik-smp/check-in', $payload('20023', 'shared-phone'))->assertStatus(200);

        // The buddy punch: a second NIS from the same phone.
        $this->postJson('/api/absen/publik-smp/check-in', $payload('20024', 'shared-phone'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Perangkat ini sudah dipakai absen siswa lain hari ini.');

        // The double scan: the same NIS again, even from a different phone.
        $this->postJson('/api/absen/publik-smp/check-in', $payload('20023', 'other-phone'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Sudah tercatat hadir sebelumnya.');

        // The blocked friend can still check in from their own phone.
        $this->postJson('/api/absen/publik-smp/check-in', $payload('20024', 'own-phone'))->assertStatus(200);

        $this->assertSame(1, DailyRecord::where('device_hash', hash('sha256', 'shared-phone'))->count());
        $this->assertSame(2, DailyRecord::where('daily_session_id', $session->id)->count());
    }

    public function test_the_device_rule_binds_one_nis_for_one_day_not_forever(): void
    {
        $this->gateSetting();
        $classroom = $this->classroomIn($this->smp);
        $student = $this->studentIn($classroom, 'Siti', '20025');
        $friend = $this->studentIn($classroom, 'Budi', '20026');

        /** @var DailyAttendanceService $service */
        $service = app(DailyAttendanceService::class);
        $masuk = $service->ensureSessionsForDate($service->ensureSettings($this->smp), $this->monday)
            ->firstWhere('type', 'masuk');

        // Yesterday this phone already checked a different NIS in - seeded
        // straight, the service path has its own tests.
        $yesterday = $this->monday->copy()->subDay();
        $ySession = DailySession::create([
            'school_unit_id' => $this->smp->id,
            'date' => $yesterday->toDateString(),
            'type' => 'masuk',
            'opens_at' => $yesterday->copy()->setTime(6, 30),
            'closes_at' => $yesterday->copy()->setTime(8, 0),
            'status' => 'closed',
        ]);
        DailyRecord::create([
            'daily_session_id' => $ySession->id,
            'student_id' => $friend->id,
            'term_id' => $this->term->id,
            'date' => $yesterday->toDateString(),
            'attendance_status' => 'hadir',
            'source' => 'self',
            'device_hash' => hash('sha256', 'siti-phone'),
            'record_status' => 'recorded',
        ]);

        $input = [
            'qr_code' => app(RotatingQrService::class)->code(RotatingQrService::dailyScope($masuk->ulid)),
            'lat' => -6.2000100, 'lng' => 106.8000100,
            'device_id' => 'siti-phone', 'ip' => '10.0.0.9',
        ];

        // Today the phone is free again - the rule is per day, yesterday's
        // different NIS must not follow the device around forever.
        $service->selfCheckIn($masuk, $student, $input);

        // But from this check-in on, the phone is bound to its owner's NIS
        // for the rest of TODAY: a friend's NIS is closed.
        try {
            $service->selfCheckIn($masuk, $friend, $input);
            $this->fail('NIS teman dari HP yang sama harus ditolak pada hari yang sama.');
        } catch (RuntimeException $e) {
            $this->assertSame('Perangkat ini sudah dipakai absen siswa lain hari ini.', $e->getMessage());
        }

        $this->assertSame(2, DailyRecord::count());
    }

    public function test_a_wali_kelas_unit_rejects_gate_check_ins(): void
    {
        $this->enabledSetting($this->sd, ['public_slug' => 'publik-sd']); // mode stays wali_kelas
        $this->masukSessionFor($this->sd);
        $classroom = $this->classroomIn($this->sd);
        $this->studentIn($classroom, '20025');

        $this->postJson('/api/absen/publik-sd/check-in', [
            'nis' => '20025', 'device_id' => 'device-D',
        ])->assertStatus(404);

        $this->assertDatabaseMissing('daily_records', ['device_hash' => hash('sha256', 'device-D')]);
    }

    public function test_tu_gets_the_rotating_qr_and_can_mark_manually(): void
    {
        $this->gateSetting();
        $classroom = $this->classroomIn($this->smp);
        $student = $this->studentIn($classroom, '20026');
        $this->guardianOf($student);
        $session = $this->masukSessionFor($this->smp);

        $tu = $this->staff('admin_unit', $this->smp);

        // The QR endpoint hands the TU screen today's code, same one a scan would verify.
        $qr = $this->actingAs($tu)->getJson("/api/admin/daily-attendance/sessions/{$session->ulid}/gate-qr")
            ->assertStatus(200);
        $this->assertSame(app(RotatingQrService::class)->code(RotatingQrService::dailyScope($session->ulid)), $qr->json('code'));
        $this->assertGreaterThan(0, $qr->json('rotates_in'));

        // Another unit's TU gets a 404, never a 403.
        $this->actingAs($this->staff('admin_unit', $this->sd))
            ->getJson("/api/admin/daily-attendance/sessions/{$session->ulid}/gate-qr")
            ->assertStatus(404);

        // The manual quick lane: a failed scan becomes a TU mark, source 'tu'.
        $this->actingAs($tu)->postJson("/api/admin/daily-attendance/sessions/{$session->ulid}/records", [
            'student_ulid' => $student->ulid,
            'status' => 'hadir',
        ])->assertStatus(200);

        $this->assertDatabaseHas('daily_records', [
            'student_id' => $student->id, 'source' => 'tu', 'attendance_status' => 'hadir',
        ]);
    }

    public function test_the_tu_board_flags_check_ins_sharing_an_ip(): void
    {
        $this->gateSetting();
        $classroom = $this->classroomIn($this->smp);
        $a = $this->studentIn($classroom, '20027'); // both from the test's
        $b = $this->studentIn($classroom, '20028'); // default 127.0.0.1
        $c = $this->studentIn($classroom, '20029');
        $session = $this->masukSessionFor($this->smp);
        $code = app(RotatingQrService::class)->code(RotatingQrService::dailyScope($session->ulid));

        $payload = fn (string $nis, string $device) => [
            'nis' => $nis, 'qr_code' => $code,
            'lat' => -6.2000100, 'lng' => 106.8000100, 'device_id' => $device,
        ];

        $this->postJson('/api/absen/publik-smp/check-in', $payload('20027', 'dev-1'))->assertStatus(200);
        $this->postJson('/api/absen/publik-smp/check-in', $payload('20028', 'dev-2'))->assertStatus(200);

        // Student C egresses from a different IP - incognito-mode buddy
        // punching is what the flag hunts; an honest school WiFi crowd is
        // many devices on ONE ip only when one phone checked them all in.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9']);
        $this->postJson('/api/absen/publik-smp/check-in', $payload('20029', 'dev-3'))->assertStatus(200);
        $this->withServerVariables([]);

        $board = $this->actingAs($this->staff('admin_unit', $this->smp))
            ->getJson('/api/admin/daily-attendance/today')
            ->assertStatus(200);

        $masuk = collect($board->json('sessions'))->firstWhere('type', 'masuk');
        $suspected = collect($masuk['suspected']);

        $this->assertContains($a->ulid, $suspected);
        $this->assertContains($b->ulid, $suspected);
        $this->assertNotContains($c->ulid, $suspected);
    }

    // --- Daily layer as the official report source (§8) ----------------------

    public function test_marks_and_sweeps_feed_the_enrollment_rollup(): void
    {
        $this->enabledSetting($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $classroom = $this->classroomIn($this->sd, $guru);
        $hadir = $this->studentIn($classroom, '20030');
        $bolos = $this->studentIn($classroom, '20031');
        $sakit = $this->studentIn($classroom, '20032');

        /** @var DailyAttendanceService $service */
        $service = app(DailyAttendanceService::class);
        $masuk = $this->masukSessionFor($this->sd);

        $service->mark($masuk, $hadir, 'hadir', $guru, 'wali_kelas');
        $service->mark($masuk, $sakit, 'sakit', $guru, 'wali_kelas');
        $service->closeAndSweep($masuk); // bolos terbawa alpa

        $this->assertSame(1, $bolos->currentEnrollment()->fresh()->absent_count);
        $this->assertSame(1, $sakit->currentEnrollment()->fresh()->sick_count);
        $this->assertSame(0, $hadir->currentEnrollment()->fresh()->absent_count);

        // And the wali-facing summary counts days, masuk windows only.
        $summary = $service->summary($hadir, $this->term);
        $this->assertSame(1, $summary['hadir']);
        $this->assertSame(0, $summary['alpa']);
    }

    public function test_the_wali_portal_reads_the_daily_layer(): void
    {
        $this->gateSetting();
        $classroom = $this->classroomIn($this->smp);
        $student = $this->studentIn($classroom, '20033');
        $session = $this->masukSessionFor($this->smp);

        $this->postJson('/api/absen/publik-smp/check-in', [
            'nis' => '20033',
            'qr_code' => app(RotatingQrService::class)->code(RotatingQrService::dailyScope($session->ulid)),
            'lat' => -6.2000100, 'lng' => 106.8000100, 'device_id' => 'device-E',
        ])->assertStatus(200);

        $wali = User::create([
            'name' => 'Wali 20033', 'email' => 'wali'.uniqid().'@yapinet.id',
            'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
        ]);
        $guardian = Guardian::create(['user_id' => $wali->id, 'nama' => 'Ibu Wali', 'hubungan' => 'ibu']);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ibu', 'is_primary' => true]);

        $response = $this->actingAs($wali)
            ->getJson("/api/wali/students/{$student->ulid}/attendance")
            ->assertStatus(200);

        $this->assertSame(1, $response->json('summary.hadir'));
        $this->assertSame('masuk', $response->json('records.0.type'));
        $this->assertNotNull($response->json('records.0.checked_in_at'));
    }

    // --- A bells edit must reach TODAY's windows (the reported "set 15:00 but
    // the link still says closed at 08:00") -----------------------------------

    public function test_a_bells_edit_moves_todays_still_open_window(): void
    {
        $this->gateSetting();
        $this->masukSessionFor($this->smp); // opened 06:30-08:00, snapshots frozen

        $this->actingAs($this->staff('admin_unit', $this->smp))
            ->patchJson('/api/admin/daily-attendance/settings', [
                'masuk_opens_at' => '15:00',
                'masuk_closes_at' => '16:00',
                'masuk_late_after' => '15:30',
            ])->assertStatus(200);

        $session = DailySession::where('school_unit_id', $this->smp->id)->where('type', 'masuk')->first();
        $this->assertSame('open', $session->status);
        $this->assertSame('15:00:00', $session->opens_at->format('H:i:s'));
        $this->assertSame('16:00:00', $session->closes_at->format('H:i:s'));

        // Before the new window starts, the link honestly says closed - "open"
        // must mean "inside the window", never "not yet closed" (that let the
        // gate accept scans from midnight).
        $this->getJson('/api/absen/publik-smp')
            ->assertStatus(200)
            ->assertJsonPath('state', 'closed')
            ->assertJsonPath('session.opens_at', '15:00');

        // The public link now tells the student the new window, not 08:00 -
        // and once the window actually starts, it is open.
        Carbon::setTestNow($this->monday->copy()->setTime(15, 10)->utc());

        $this->getJson('/api/absen/publik-smp')
            ->assertStatus(200)
            ->assertJsonPath('state', 'open')
            ->assertJsonPath('session.opens_at', '15:00')
            ->assertJsonPath('session.closes_at', '16:00')
            ->assertJsonPath('session.late_after', '15:30');
    }

    public function test_a_bells_edit_reopens_a_closed_window_and_frees_the_swept_alpas(): void
    {
        $this->gateSetting();
        $guru = $this->staff('guru', $this->smp);
        $classroom = $this->classroomIn($this->smp);
        $bolos = $this->studentIn($classroom, '20034');
        $sakit = $this->studentIn($classroom, '20035');

        /** @var DailyAttendanceService $service */
        $service = app(DailyAttendanceService::class);
        $masuk = $this->masukSessionFor($this->smp);

        $service->mark($masuk, $sakit, 'sakit', $guru, 'wali_kelas');

        Carbon::setTestNow($this->monday->copy()->addMinutes(65)->utc()); // 08:05 - the 08:00 close already ran
        $service->closeAndSweep($masuk);
        $this->assertSame(1, $bolos->currentEnrollment()->fresh()->absent_count);

        $admin = $this->staff('admin_unit', $this->smp);

        $this->actingAs($admin)->patchJson('/api/admin/daily-attendance/settings', [
            'masuk_opens_at' => '15:00',
            'masuk_closes_at' => '16:00',
            'masuk_late_after' => '15:30',
        ])->assertStatus(200);

        // The closed window reopens under the new bells, attributed to the admin.
        $masuk = $masuk->fresh();
        $this->assertSame('open', $masuk->status);
        $this->assertSame('16:00:00', $masuk->closes_at->format('H:i:s'));
        $this->assertSame($admin->id, $masuk->opened_by);

        // The machine's swept alpa is revoked (that window never closed now);
        // a human's manual mark is untouched. The watchlist rollup follows.
        $bolosRecord = DailyRecord::where('student_id', $bolos->id)->first();
        $this->assertSame('revoked', $bolosRecord->record_status);
        $this->assertNotNull($bolosRecord->revoke_reason);
        $this->assertSame(0, $bolos->currentEnrollment()->fresh()->absent_count);
        $this->assertSame('sakit', DailyRecord::where('student_id', $sakit->id)->first()->attendance_status);

        // And the freed student can actually check in at the gate - inside
        // the reopened window (15:00-16:00), not at 08:05 where the old
        // isOpen would have happily accepted a scan before opening time.
        Carbon::setTestNow($this->monday->copy()->setTime(15, 10)->utc());

        $this->postJson('/api/absen/publik-smp/check-in', [
            'nis' => '20034',
            'qr_code' => app(RotatingQrService::class)->code(RotatingQrService::dailyScope($masuk->ulid)),
            'lat' => -6.2000100, 'lng' => 106.8000100, 'device_id' => 'device-F',
        ])->assertStatus(200)->assertJsonPath('is_late', false);

        $this->assertSame('hadir', DailyRecord::where('student_id', $bolos->id)->active()->first()->attendance_status);
    }

    public function test_a_bells_edit_whose_new_window_is_already_over_leaves_history_closed(): void
    {
        $this->gateSetting();
        $classroom = $this->classroomIn($this->smp);
        $student = $this->studentIn($classroom, '20036');

        /** @var DailyAttendanceService $service */
        $service = app(DailyAttendanceService::class);
        $masuk = $this->masukSessionFor($this->smp);

        Carbon::setTestNow($this->monday->copy()->addMinutes(65)->utc()); // 08:05
        $service->closeAndSweep($masuk);

        // The edit lands on a window that is ALSO in the past (06:00-07:00) -
        // that day already happened; it must not resurrect.
        $this->actingAs($this->staff('admin_unit', $this->smp))
            ->patchJson('/api/admin/daily-attendance/settings', [
                'masuk_opens_at' => '06:00',
                'masuk_closes_at' => '07:00',
                'masuk_late_after' => '06:30',
            ])->assertStatus(200);

        $this->assertSame('closed', $masuk->fresh()->status);
        $this->assertSame('alpa', DailyRecord::where('student_id', $student->id)->active()->first()->attendance_status);
    }

    public function test_switching_the_unit_off_mid_day_closes_its_open_window_without_sweeping(): void
    {
        $this->gateSetting();
        $classroom = $this->classroomIn($this->smp);
        $student = $this->studentIn($classroom, '20037');
        $masuk = $this->masukSessionFor($this->smp);

        $this->actingAs($this->staff('admin_unit', $this->smp))
            ->patchJson('/api/admin/daily-attendance/settings', [
                'masuk_opens_at' => '06:30',
                'masuk_closes_at' => '08:00',
                'enabled' => false,
            ])->assertStatus(200);

        $this->assertSame('closed', $masuk->fresh()->status);
        // No auto-alpa was invented for a window the unit itself withdrew -
        // the sweep is what writes those, and it only runs for enabled units.
        $this->assertDatabaseMissing('daily_records', ['student_id' => $student->id]);
    }

    public function test_a_holiday_opens_no_sessions_and_never_sweeps_alpa(): void
    {
        $setting = $this->enabledSetting($this->sd);
        $classroom = $this->classroomIn($this->sd);
        $student = $this->studentIn($classroom, '9010');
        $this->guardianOf($student);

        Holiday::create(['date' => $this->monday->toDateString(), 'label' => 'Hari Libur Nasional']);

        $sessions = app(DailyAttendanceService::class)->ensureSessionsForDate($setting, $this->monday);
        $this->assertCount(0, $sessions);
        $this->assertDatabaseCount('daily_sessions', 0);

        // Marked the holiday only after a session had already opened? Closing
        // it must stay quiet - a school that was shut never mass-alpas.
        $session = DailySession::create([
            'school_unit_id' => $this->sd->id,
            'date' => $this->monday->toDateString(),
            'type' => 'masuk',
            'opens_at' => $this->monday->copy()->setTime(6, 30),
            'closes_at' => $this->monday->copy()->setTime(8, 0),
            'status' => 'open',
        ]);

        $swept = app(DailyAttendanceService::class)->closeAndSweep($session);

        $this->assertSame(0, $swept);
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertDatabaseCount('daily_records', 0);
        $this->assertEmpty($this->sentWa);
    }

    public function test_enabling_a_unit_midday_after_its_close_time_creates_no_open_session(): void
    {
        $setting = $this->enabledSetting($this->sd, ['masuk_closes_at' => '08:00:00']);
        $classroom = $this->classroomIn($this->sd);
        $student = $this->studentIn($classroom, '9011');
        $this->guardianOf($student);

        // 10:00 Monday - the admin flips the unit on two hours after masuk
        // closed. The session must be born closed, or the next sweep would
        // alpa the entire unit.
        Carbon::setTestNow(Carbon::create(2026, 9, 14, 10, 0, 0, 'Asia/Jakarta')->utc());

        $sessions = app(DailyAttendanceService::class)->ensureSessionsForDate($setting);

        $this->assertCount(1, $sessions);
        $this->assertSame('closed', $sessions[0]->fresh()->status);

        $this->artisan('attendance:daily-sweep')->assertSuccessful();

        $this->assertDatabaseCount('daily_records', 0);
        $this->assertEmpty($this->sentWa);
    }

    public function test_the_holiday_calendar_names_the_weekday_and_reports_active_days(): void
    {
        // One enabled unit running Mon-Fri is enough to derive the union.
        $this->enabledSetting($this->sd);

        // 2026-09-19 is a Saturday - no enabled unit runs it.
        $saturday = Carbon::create(2026, 9, 19, 8, 0, 0, 'Asia/Jakarta');
        Holiday::create(['date' => $saturday->toDateString(), 'label' => 'Cuti Bersama']);

        $this->actingAs($this->staff('admin'))
            ->getJson('/api/admin/holidays')
            ->assertOk()
            ->assertJsonPath('holidays.0.weekday', 'Sabtu')
            ->assertJsonPath('active_days', [1, 2, 3, 4, 5]);

        $this->actingAs($this->staff('admin'))
            ->postJson('/api/admin/holidays', ['date' => '2026-09-21', 'label' => 'Hari Besar'])
            ->assertCreated()
            ->assertJsonPath('holiday.weekday', 'Senin');
    }
}
