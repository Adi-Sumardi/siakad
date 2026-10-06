<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\DailyAttendanceSetting;
use App\Models\DailyRecord;
use App\Models\DailySession;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\LeaveRequest;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\Attendance\AttendanceSessionService;
use App\Services\Attendance\DailyAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit 6 Okt 2026, items 1-3: correcting a past day on the staff boards,
 * guardians' izin/sakit notices (submit -> review -> attendance), and the
 * auto-close of lesson sessions a teacher never finished.
 */
class AttendanceCorrectionAndLeaveTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private Term $term;

    private Carbon $monday;

    private User $homeroom;

    private Classroom $classroom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monday = Carbon::create(2026, 9, 14, 7, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($this->monday->copy()->utc());

        $this->sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'TKI/SDI Al Azhar 13', 'jenjang_group' => 'sd']);

        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        $this->term = Term::create([
            'academic_year_id' => $year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);

        $setting = app(DailyAttendanceService::class)->ensureSettings($this->sd);
        $setting->forceFill(['enabled' => true, 'days' => [1, 2, 3, 4, 5]])->save();

        $this->homeroom = $this->user('guru');
        $this->classroom = Classroom::create([
            'school_unit_id' => $this->sd->id,
            'academic_year_id' => $year->id,
            'name' => '1-A', 'tingkat' => 1,
            'homeroom_teacher_id' => $this->homeroom->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $role === 'orangtua' ? null : $this->sd->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function student(string $nis, ?Classroom $classroom = null): Student
    {
        $student = Student::create([
            'nama_lengkap' => 'Siswa '.$nis, 'nama_panggilan' => 'S'.$nis, 'nis' => $nis,
            'jenis_kelamin' => 'L', 'school_unit_id' => $this->sd->id, 'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => ($classroom ?? $this->classroom)->id,
            'academic_year_id' => $this->term->academic_year_id, 'status' => 'active', 'joined_on' => '2026-07-01',
        ]);

        return $student;
    }

    private function guardianUserOf(Student $student): User
    {
        $user = $this->user('orangtua');
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Ibu', 'hubungan' => 'ibu']);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ibu', 'is_primary' => true]);

        return $user;
    }

    /** Creates (and closes with the alpa sweep) the masuk session of a given date. */
    private function sweptMasukOn(Carbon $date): DailySession
    {
        $service = app(DailyAttendanceService::class);
        Carbon::setTestNow($date->copy()->setTime(7, 0)->utc());
        $session = $service->ensureSessionsForDate(DailyAttendanceSetting::where('school_unit_id', $this->sd->id)->first(), $date)
            ->firstWhere('type', 'masuk');
        $service->closeAndSweep($session);
        Carbon::setTestNow($this->monday->copy()->utc());

        return $session->fresh();
    }

    // --- #1 correcting a past day -----------------------------------------

    public function test_board_reads_a_past_day_without_inventing_sessions(): void
    {
        $this->student('1001');
        $friday = $this->monday->copy()->subDays(3);
        $session = $this->sweptMasukOn($friday);

        $this->actingAs($this->homeroom)->getJson('/api/guru/daily-attendance/today?date='.$friday->toDateString())
            ->assertOk()
            ->assertJsonPath('is_today', false)
            ->assertJsonPath('sessions.0.ulid', $session->ulid)
            ->assertJsonPath('sessions.0.roster.0.attendance_status', 'alpa');

        // Thursday never had a session: reading it must not create one.
        $this->actingAs($this->homeroom)->getJson('/api/guru/daily-attendance/today?date='.$this->monday->copy()->subDays(4)->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'sessions');
        $this->assertSame(1, DailySession::whereDate('date', '<', $this->monday->toDateString())->count());
    }

    public function test_correcting_a_past_day_requires_a_reason_and_keeps_the_trail(): void
    {
        $student = $this->student('1002');
        $session = $this->sweptMasukOn($this->monday->copy()->subDays(3));

        $this->actingAs($this->homeroom)->postJson("/api/guru/daily-attendance/sessions/{$session->ulid}/records", [
            'student_ulid' => $student->ulid, 'status' => 'sakit',
        ])->assertStatus(422)->assertJsonPath('message', 'Alasan koreksi wajib diisi untuk presensi hari yang sudah lewat.');

        $this->actingAs($this->homeroom)->postJson("/api/guru/daily-attendance/sessions/{$session->ulid}/records", [
            'student_ulid' => $student->ulid, 'status' => 'sakit', 'description' => 'Surat dokter menyusul hari Senin',
        ])->assertOk();

        $this->assertSame(['revoked', 'recorded'], DailyRecord::where('student_id', $student->id)->orderBy('id')->pluck('record_status')->all());
        $this->assertSame('sakit', DailyRecord::where('student_id', $student->id)->active()->value('attendance_status'));
    }

    public function test_board_rejects_future_dates_and_dates_beyond_the_window(): void
    {
        $this->actingAs($this->homeroom)->getJson('/api/guru/daily-attendance/today?date='.$this->monday->copy()->addDay()->toDateString())
            ->assertStatus(422);

        $tooOld = $this->monday->copy()->subDays(DailyAttendanceService::CORRECTION_WINDOW_DAYS + 1)->toDateString();
        $this->actingAs($this->user('admin_unit'))->getJson('/api/admin/daily-attendance/today?date='.$tooOld)
            ->assertStatus(422);
    }

    // --- #3 guardian leave notices ------------------------------------------

    public function test_guardian_files_and_homeroom_approval_marks_past_days_but_never_overwrites_hadir(): void
    {
        $student = $this->student('1003');
        $wali = $this->guardianUserOf($student);
        $service = app(DailyAttendanceService::class);

        $friday = $this->sweptMasukOn($this->monday->copy()->subDays(3));
        $thursday = $this->sweptMasukOn($this->monday->copy()->subDays(4));
        // Thursday the child actually came in.
        $service->mark($thursday, $student, 'hadir', $this->homeroom, 'wali_kelas');

        $this->actingAs($wali)->postJson("/api/wali/students/{$student->ulid}/leave-requests", [
            'type' => 'sakit',
            'date_from' => $thursday->date->toDateString(),
            'date_to' => $friday->date->toDateString(),
            'reason' => 'Demam sejak Kamis siang',
        ])->assertCreated();

        $leave = LeaveRequest::firstOrFail();
        $this->assertSame('pending', $leave->status);
        // Pending changes nothing.
        $this->assertSame('alpa', DailyRecord::where('daily_session_id', $friday->id)->active()->value('attendance_status'));

        $this->actingAs($this->homeroom)->getJson('/api/guru/leave-requests')
            ->assertOk()->assertJsonPath('leave_requests.0.ulid', $leave->ulid);

        $this->actingAs($this->homeroom)->postJson("/api/guru/leave-requests/{$leave->ulid}/approve")
            ->assertOk()->assertJsonPath('windows_marked', 1);

        $this->assertSame('sakit', DailyRecord::where('daily_session_id', $friday->id)->active()->value('attendance_status'));
        $this->assertSame('hadir', DailyRecord::where('daily_session_id', $thursday->id)->active()->value('attendance_status'));

        // Second decision on the same notice is refused.
        $this->actingAs($this->homeroom)->postJson("/api/guru/leave-requests/{$leave->ulid}/reject", ['note' => 'tidak'])
            ->assertStatus(422);
    }

    public function test_approved_future_leave_turns_the_close_time_sweep_into_izin(): void
    {
        $student = $this->student('1004');
        $other = $this->student('1005');

        LeaveRequest::create([
            'student_id' => $student->id, 'school_unit_id' => $this->sd->id,
            'type' => 'izin', 'date_from' => $this->monday->toDateString(), 'date_to' => $this->monday->toDateString(),
            'reason' => 'Acara keluarga', 'status' => 'approved',
        ]);

        $session = app(DailyAttendanceService::class)
            ->ensureSessionsForDate(DailyAttendanceSetting::where('school_unit_id', $this->sd->id)->first(), $this->monday)
            ->firstWhere('type', 'masuk');

        $this->actingAs($this->homeroom)->getJson('/api/guru/daily-attendance/today')
            ->assertOk()
            ->assertJsonFragment(['ulid' => $student->ulid, 'leave' => 'izin']);

        app(DailyAttendanceService::class)->closeAndSweep($session);

        $this->assertSame('izin', DailyRecord::where('student_id', $student->id)->active()->value('attendance_status'));
        $this->assertSame('alpa', DailyRecord::where('student_id', $other->id)->active()->value('attendance_status'));
    }

    public function test_leave_scope_is_the_homeroom_and_the_guardians_own_children(): void
    {
        $mine = $this->student('1006');
        $otherRoom = Classroom::create([
            'school_unit_id' => $this->sd->id, 'academic_year_id' => $this->term->academic_year_id,
            'name' => '1-B', 'tingkat' => 1, 'homeroom_teacher_id' => $this->user('guru')->id,
        ]);
        $notMine = $this->student('1007', $otherRoom);
        $wali = $this->guardianUserOf($mine);

        // A guardian cannot file for somebody else's child.
        $this->actingAs($wali)->postJson("/api/wali/students/{$notMine->ulid}/leave-requests", [
            'type' => 'izin', 'date_from' => $this->monday->toDateString(), 'date_to' => $this->monday->toDateString(),
            'reason' => 'Bukan anak saya',
        ])->assertNotFound();

        $leave = LeaveRequest::create([
            'student_id' => $notMine->id, 'school_unit_id' => $this->sd->id,
            'type' => 'izin', 'date_from' => $this->monday->toDateString(), 'date_to' => $this->monday->toDateString(),
            'reason' => 'Acara keluarga', 'status' => 'pending',
        ]);

        // Another room's homeroom teacher: 404, never 403.
        $this->actingAs($this->homeroom)->postJson("/api/guru/leave-requests/{$leave->ulid}/approve")->assertNotFound();
        // TU of the unit can decide it.
        $this->actingAs($this->user('admin_unit'))->postJson("/api/admin/leave-requests/{$leave->ulid}/reject", ['note' => 'Tanpa keterangan jelas'])
            ->assertOk();
    }

    public function test_leave_request_validation_limits(): void
    {
        $student = $this->student('1008');
        $wali = $this->guardianUserOf($student);

        $this->actingAs($wali)->postJson("/api/wali/students/{$student->ulid}/leave-requests", [
            'type' => 'sakit',
            'date_from' => $this->monday->copy()->subDays(LeaveRequest::BACKDATE_DAYS + 1)->toDateString(),
            'date_to' => $this->monday->toDateString(),
            'reason' => 'Terlambat lapor',
        ])->assertStatus(422)->assertJsonValidationErrors('date_from');

        $this->actingAs($wali)->postJson("/api/wali/students/{$student->ulid}/leave-requests", [
            'type' => 'izin',
            'date_from' => $this->monday->toDateString(),
            'date_to' => $this->monday->copy()->addDays(LeaveRequest::MAX_DAYS)->toDateString(),
            'reason' => 'Umrah keluarga',
        ])->assertStatus(422)->assertJsonValidationErrors('date_to');
    }

    // --- #2 lesson auto-close -----------------------------------------------

    public function test_forgotten_lesson_sessions_close_and_fill_blanks_from_the_daily_layer(): void
    {
        $scanned = $this->student('2001');
        $sick = $this->student('2002');
        $blank = $this->student('2003');

        $subject = Subject::create(['code' => 'MTK', 'name' => 'Matematika', 'school_unit_id' => $this->sd->id]);
        $schedule = ClassSchedule::create([
            'classroom_id' => $this->classroom->id, 'subject_id' => $subject->id, 'teacher_id' => $this->homeroom->id,
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:00',
        ]);
        $session = AttendanceSession::create([
            'class_schedule_id' => $schedule->id, 'occurred_on' => $this->monday->toDateString(),
            'token' => Str::random(40), 'status' => 'open', 'opened_by' => $this->homeroom->id,
            'opened_at' => $this->monday->copy()->setTime(8, 0), 'expires_at' => $this->monday->copy()->setTime(9, 0),
        ]);
        AttendanceRecord::create([
            'student_id' => $scanned->id, 'attendance_session_id' => $session->id, 'classroom_id' => $this->classroom->id,
            'term_id' => $this->term->id, 'attendance_status' => 'hadir', 'occurred_on' => $this->monday->toDateString(),
            'source' => 'self', 'record_status' => 'recorded',
        ]);

        $daily = app(DailyAttendanceService::class);
        $masuk = $daily->ensureSessionsForDate(DailyAttendanceSetting::where('school_unit_id', $this->sd->id)->first(), $this->monday)
            ->firstWhere('type', 'masuk');
        $daily->mark($masuk, $sick, 'sakit', $this->homeroom, 'wali_kelas');

        $service = app(AttendanceSessionService::class);

        // Still inside the grace period: untouched.
        $this->assertSame(0, $service->closeExpired($this->monday->copy()->setTime(9, 10))['closed']);

        $result = $service->closeExpired($this->monday->copy()->setTime(9, 45));

        $this->assertSame(['closed' => 1, 'marked' => 2], $result);
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame('hadir', AttendanceRecord::where('student_id', $scanned->id)->active()->value('attendance_status'));
        $this->assertSame('sakit', AttendanceRecord::where('student_id', $sick->id)->active()->value('attendance_status'));
        $this->assertSame('alpa', AttendanceRecord::where('student_id', $blank->id)->active()->value('attendance_status'));
    }
}
