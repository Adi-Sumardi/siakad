<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\AttendanceSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Students have no account in this app, so the whole self-check-in flow runs
 * through a session token instead of a Sanctum session - this covers both
 * that public surface and the teacher-facing session panel it hands off to
 * (open, live roster, revoke, complete). See docs/01-ARSITEKTUR.md D6: a
 * correction is a revoke with a reason here too, never an edit or a delete.
 */
class AttendanceSessionTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);

        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        $this->term = Term::create([
            'academic_year_id' => $year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);
    }

    private function classroomIn(SchoolUnit $unit): Classroom
    {
        return Classroom::create([
            'school_unit_id' => $unit->id,
            'academic_year_id' => $this->term->academic_year_id,
            'name' => '1-A', 'tingkat' => 1,
        ]);
    }

    private function studentIn(Classroom $classroom, string $name = 'Aisyah Nur Ramadhani', string $nis = '10001'): Student
    {
        $student = Student::create([
            'nama_lengkap' => $name, 'nis' => $nis, 'jenis_kelamin' => 'P',
            'school_unit_id' => $classroom->school_unit_id, 'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $student->id, 'classroom_id' => $classroom->id,
            'academic_year_id' => $this->term->academic_year_id,
            'status' => 'active', 'joined_on' => '2026-07-01',
        ]);

        return $student;
    }

    private function staff(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function subject(): Subject
    {
        return Subject::create(['code' => 'BINDO-'.uniqid(), 'name' => 'Bahasa Indonesia']);
    }

    private function scheduleFor(Classroom $classroom, Subject $subject, ?User $teacher = null): ClassSchedule
    {
        return ClassSchedule::create([
            'classroom_id' => $classroom->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher?->id,
            'day_of_week' => Carbon::today()->dayOfWeekIso,
            'start_time' => '07:00', 'end_time' => '23:59',
        ]);
    }

    private function openSession(ClassSchedule $schedule, User $openedBy): AttendanceSession
    {
        return app(AttendanceSessionService::class)->open($schedule, Carbon::today(), $openedBy);
    }

    // --- Session lifecycle -------------------------------------------------

    public function test_a_guru_opens_a_session_for_their_own_schedule_via_the_api(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $schedule = $this->scheduleFor($classroom, $this->subject(), $guru);

        $this->actingAs($guru)->postJson("/api/guru/schedules/{$schedule->ulid}/attendance-sessions")
            ->assertStatus(200)
            ->assertJsonStructure(['session' => ['ulid', 'token', 'expires_at'], 'checkin_url']);

        $this->assertDatabaseCount('attendance_sessions', 1);
    }

    public function test_a_guru_cannot_open_a_session_for_a_schedule_outside_their_unit(): void
    {
        $classroom = $this->classroomIn($this->smp);
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);

        $this->actingAs($guru)->postJson("/api/guru/schedules/{$schedule->ulid}/attendance-sessions")
            ->assertStatus(404);
    }

    public function test_opening_an_already_open_unexpired_session_reuses_it(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);

        $first = $this->openSession($schedule, $guru);
        $second = $this->openSession($schedule, $guru);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('attendance_sessions', 1);
    }

    // --- Public check-in surface -------------------------------------------

    public function test_show_rejects_an_unknown_token(): void
    {
        $this->getJson('/api/presensi/tidak-ada-tokennya')->assertStatus(404);
    }

    public function test_show_rejects_an_expired_session(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $session = $this->openSession($schedule, $guru);
        $session->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->getJson("/api/presensi/{$session->token}")->assertStatus(410);
    }

    public function test_show_never_includes_a_student_roster(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $this->studentIn($classroom);
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $session = $this->openSession($schedule, $guru);

        $response = $this->getJson("/api/presensi/{$session->token}")->assertStatus(200);
        $response->assertJsonMissingPath('students');
        $this->assertStringNotContainsString('Aisyah', $response->getContent());
    }

    public function test_lookup_with_a_registered_nis_returns_the_name_and_not_checked_in(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $student = $this->studentIn($classroom, nis: '20001');
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $session = $this->openSession($schedule, $guru);

        $this->postJson("/api/presensi/{$session->token}/lookup", ['nis' => '20001'])
            ->assertStatus(200)
            ->assertJson(['already_checked_in' => false])
            ->assertJsonPath('student.nama_panggilan', $student->nama_panggilan);
    }

    public function test_lookup_with_an_unregistered_nis_returns_a_generic_404(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $session = $this->openSession($schedule, $guru);

        $this->postJson("/api/presensi/{$session->token}/lookup", ['nis' => '99999'])
            ->assertStatus(404);
    }

    public function test_check_in_after_lookup_writes_a_hadir_record_with_source_self(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $student = $this->studentIn($classroom, nis: '20002');
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $session = $this->openSession($schedule, $guru);

        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20002'])
            ->assertStatus(200)
            ->assertJsonPath('student.nama_panggilan', $student->nama_panggilan);

        $this->assertDatabaseHas('attendance_records', [
            'student_id' => $student->id, 'attendance_session_id' => $session->id,
            'attendance_status' => 'hadir', 'source' => 'self', 'recorded_by' => null,
        ]);
    }

    public function test_lookup_reports_already_checked_in_after_a_check_in(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $this->studentIn($classroom, nis: '20003');
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $session = $this->openSession($schedule, $guru);

        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20003']);

        $this->postJson("/api/presensi/{$session->token}/lookup", ['nis' => '20003'])
            ->assertJson(['already_checked_in' => true]);
    }

    public function test_a_second_check_in_for_the_same_nis_is_rejected(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $this->studentIn($classroom, nis: '20004');
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $session = $this->openSession($schedule, $guru);

        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20004'])->assertStatus(200);
        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20004'])->assertStatus(409);

        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_check_in_is_rejected_once_the_session_is_closed(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $this->studentIn($classroom, nis: '20005');
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $session = $this->openSession($schedule, $guru);
        $session->forceFill(['status' => 'closed'])->save();

        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20005'])->assertStatus(410);
    }

    // --- Guru session panel --------------------------------------------------

    public function test_roster_shows_name_and_check_in_time_for_each_checked_in_student(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $this->studentIn($classroom, 'Aisyah', '20006');
        $guru = $this->staff('guru', $this->sd);
        $schedule = $this->scheduleFor($classroom, $this->subject(), $guru);
        $session = $this->openSession($schedule, $guru);

        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20006']);

        $response = $this->actingAs($guru)->getJson("/api/guru/attendance-sessions/{$session->ulid}/roster")
            ->assertStatus(200);

        $students = $response->json('students');
        $aisyah = collect($students)->firstWhere('nis', '20006');
        $this->assertSame('hadir', $aisyah['attendance_status']);
        $this->assertSame('self', $aisyah['source']);
        $this->assertNotNull($aisyah['checked_in_at']);
    }

    public function test_a_guru_revokes_a_check_in_from_the_session_panel(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $student = $this->studentIn($classroom, nis: '20007');
        $guru = $this->staff('guru', $this->sd);
        $schedule = $this->scheduleFor($classroom, $this->subject(), $guru);
        $session = $this->openSession($schedule, $guru);

        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20007']);
        $record = AttendanceRecord::where('student_id', $student->id)->firstOrFail();

        $this->actingAs($guru)->patchJson(
            "/api/guru/attendance-sessions/{$session->ulid}/records/{$record->ulid}/revoke",
            ['reason' => 'NIS dimasukkan oleh siswa lain, yang bersangkutan tidak hadir.']
        )->assertStatus(200);

        $this->assertDatabaseHas('attendance_records', ['id' => $record->id, 'record_status' => 'revoked']);

        $roster = $this->actingAs($guru)->getJson("/api/guru/attendance-sessions/{$session->ulid}/roster")->json('students');
        $this->assertNull(collect($roster)->firstWhere('nis', '20007')['attendance_status']);
    }

    public function test_revoke_requires_a_reason(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $student = $this->studentIn($classroom, nis: '20008');
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $session = $this->openSession($schedule, $guru);
        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20008']);
        $record = AttendanceRecord::where('student_id', $student->id)->firstOrFail();

        $this->actingAs($guru)->patchJson(
            "/api/guru/attendance-sessions/{$session->ulid}/records/{$record->ulid}/revoke",
            []
        )->assertStatus(422);
    }

    public function test_a_guru_from_another_unit_cannot_revoke_a_record_on_this_session(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $student = $this->studentIn($classroom, nis: '20009');
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $otherGuru = $this->staff('guru', $this->smp);
        $session = $this->openSession($schedule, $guru);
        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20009']);
        $record = AttendanceRecord::where('student_id', $student->id)->firstOrFail();

        $this->actingAs($otherGuru)->patchJson(
            "/api/guru/attendance-sessions/{$session->ulid}/records/{$record->ulid}/revoke",
            ['reason' => 'Coba-coba.']
        )->assertStatus(404);
    }

    public function test_completing_a_session_marks_the_rest_of_the_roster_and_closes_it(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $hadir = $this->studentIn($classroom, 'Sudah Hadir', '20010');
        $sakit = $this->studentIn($classroom, 'Lagi Sakit', '20011');
        $guru = $this->staff('guru', $this->sd);
        $schedule = $this->scheduleFor($classroom, $this->subject(), $guru);
        $session = $this->openSession($schedule, $guru);

        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20010']);

        $this->actingAs($guru)->postJson("/api/guru/attendance-sessions/{$session->ulid}/complete", [
            'records' => [
                ['student_ulid' => $sakit->ulid, 'status' => 'sakit', 'description' => 'Surat dari orang tua.'],
            ],
        ])->assertStatus(200);

        $session->refresh();
        $this->assertSame('closed', $session->status);

        $this->assertDatabaseHas('attendance_records', [
            'student_id' => $sakit->id, 'attendance_status' => 'sakit', 'source' => 'guru',
        ]);

        $enrollment = $sakit->currentEnrollment();
        $this->assertSame(1, $enrollment->fresh()->sick_count);
    }

    // --- Ledger internals ----------------------------------------------------

    public function test_recording_writes_the_classroom_and_term_denormalized_onto_the_row(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $this->studentIn($classroom, nis: '20012');
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $session = $this->openSession($schedule, $guru);

        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20012']);

        $record = AttendanceRecord::first();
        $this->assertSame($classroom->id, $record->classroom_id);
        $this->assertSame($this->term->id, $record->term_id);
    }

    public function test_a_record_cannot_be_revoked_twice(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $student = $this->studentIn($classroom, nis: '20013');
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $session = $this->openSession($schedule, $guru);
        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20013']);
        $record = AttendanceRecord::where('student_id', $student->id)->firstOrFail();

        app(AttendanceLedger::class)->revoke($record, $guru, 'Pertama.');

        $this->expectException(\RuntimeException::class);
        app(AttendanceLedger::class)->revoke($record->fresh(), $guru, 'Kedua.');
    }

    // --- Admin report ----------------------------------------------------

    public function test_the_admin_attendance_report_aggregates_by_class_and_subject_within_the_date_range(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $this->studentIn($classroom, nis: '20014');
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $admin = $this->staff('admin_unit', $this->sd);
        $session = $this->openSession($schedule, $guru);

        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20014']);

        $response = $this->actingAs($admin)->getJson('/api/admin/reports/attendance')->assertStatus(200);

        $this->assertSame(1, $response->json('summary.hadir'));
        $this->assertSame(1, $response->json('summary.total_records'));
        $this->assertSame($classroom->name, $response->json('by_class.0.kelas'));
        $this->assertSame('Bahasa Indonesia', $response->json('by_subject.0.mata_pelajaran'));
    }

    /**
     * Regression test: occurred_on is stored with a time component even
     * though it is conceptually date-only (Eloquent's `date` cast), so a
     * naive whereBetween(occurred_on, [$from->toDateString(), $to->toDateString()])
     * would sort a same-day record's "00:00:00" timestamp after the
     * date-only upper bound string and silently drop it from the range.
     */
    public function test_a_record_that_occurred_today_is_included_when_the_report_range_ends_today(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $this->studentIn($classroom, nis: '20015');
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $admin = $this->staff('admin_unit', $this->sd);
        $session = $this->openSession($schedule, $guru);
        $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20015']);

        $today = Carbon::today()->toDateString();
        $response = $this->actingAs($admin)
            ->getJson("/api/admin/reports/attendance?from={$today}&to={$today}")
            ->assertStatus(200);

        $this->assertSame(1, $response->json('summary.total_records'));
    }

    public function test_a_record_outside_the_report_range_is_not_counted(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $student = $this->studentIn($classroom, nis: '20016');
        $schedule = $this->scheduleFor($classroom, $this->subject());
        $guru = $this->staff('guru', $this->sd);
        $admin = $this->staff('admin_unit', $this->sd);
        $session = $this->openSession($schedule, $guru);

        // Backdate the record to well outside the query window below.
        AttendanceRecord::create([
            'student_id' => $student->id, 'attendance_session_id' => $session->id,
            'classroom_id' => $classroom->id, 'term_id' => $this->term->id,
            'attendance_status' => 'hadir', 'occurred_on' => '2020-01-01', 'source' => 'guru',
            'recorded_by' => $guru->id, 'record_status' => 'recorded',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/reports/attendance?from=2026-01-01&to=2026-12-31')
            ->assertStatus(200);

        $this->assertSame(0, $response->json('summary.total_records'));
    }

    public function test_an_admin_unit_only_sees_attendance_for_their_own_units_students_in_the_report(): void
    {
        $sdClassroom = $this->classroomIn($this->sd);
        $this->studentIn($sdClassroom, nis: '20017');
        $sdSchedule = $this->scheduleFor($sdClassroom, $this->subject());
        $sdGuru = $this->staff('guru', $this->sd);
        $sdSession = $this->openSession($sdSchedule, $sdGuru);
        $this->postJson("/api/presensi/{$sdSession->token}/check-in", ['nis' => '20017']);

        $smpClassroom = $this->classroomIn($this->smp);
        $this->studentIn($smpClassroom, nis: '20018');
        $smpSchedule = $this->scheduleFor($smpClassroom, $this->subject());
        $smpGuru = $this->staff('guru', $this->smp);
        $smpSession = $this->openSession($smpSchedule, $smpGuru);
        $this->postJson("/api/presensi/{$smpSession->token}/check-in", ['nis' => '20018']);

        $sdAdmin = $this->staff('admin_unit', $this->sd);

        $response = $this->actingAs($sdAdmin)->getJson('/api/admin/reports/attendance')->assertStatus(200);

        $this->assertSame(1, $response->json('summary.total_records'));
    }

    // --- Regression: bugs found and closed in the debugging pass -----------

    public function test_reopening_a_closed_session_reuses_the_row_instead_of_creating_a_second_one(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $schedule = $this->scheduleFor($classroom, $this->subject(), $guru);

        $first = $this->openSession($schedule, $guru);
        $first->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $second = $this->openSession($schedule, $guru);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('open', $second->fresh()->status);
        $this->assertDatabaseCount('attendance_sessions', 1);
    }

    public function test_reopening_an_expired_but_still_open_session_extends_it_instead_of_creating_a_second_one(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $schedule = $this->scheduleFor($classroom, $this->subject(), $guru);

        $first = $this->openSession($schedule, $guru);
        $first->forceFill(['expires_at' => now()->subMinute()])->save();

        $second = $this->openSession($schedule, $guru);

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($second->fresh()->expires_at->isFuture());
        $this->assertDatabaseCount('attendance_sessions', 1);
    }

    public function test_a_guru_who_is_not_assigned_to_the_schedule_cannot_open_it_even_in_their_own_unit(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $assignedGuru = $this->staff('guru', $this->sd);
        $schedule = $this->scheduleFor($classroom, $this->subject(), $assignedGuru);
        $otherGuru = $this->staff('guru', $this->sd);

        $this->actingAs($otherGuru)->postJson("/api/guru/schedules/{$schedule->ulid}/attendance-sessions")
            ->assertStatus(404);
    }

    public function test_a_guru_who_is_not_assigned_to_the_schedule_cannot_complete_its_session(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $assignedGuru = $this->staff('guru', $this->sd);
        $schedule = $this->scheduleFor($classroom, $this->subject(), $assignedGuru);
        $session = $this->openSession($schedule, $assignedGuru);
        $otherGuru = $this->staff('guru', $this->sd);

        $this->actingAs($otherGuru)->postJson("/api/guru/attendance-sessions/{$session->ulid}/complete", ['records' => []])
            ->assertStatus(404);
    }

    public function test_completing_a_session_rejects_a_student_who_is_not_enrolled_in_this_classroom(): void
    {
        $classroom = $this->classroomIn($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $schedule = $this->scheduleFor($classroom, $this->subject(), $guru);
        $session = $this->openSession($schedule, $guru);

        $otherClassroom = Classroom::create([
            'school_unit_id' => $this->sd->id, 'academic_year_id' => $this->term->academic_year_id,
            'name' => '1-B', 'tingkat' => 1,
        ]);
        $outsider = $this->studentIn($otherClassroom, 'Bukan Anak Kelas Ini', '20099');

        $response = $this->actingAs($guru)->postJson("/api/guru/attendance-sessions/{$session->ulid}/complete", [
            'records' => [
                ['student_ulid' => $outsider->ulid, 'status' => 'hadir'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('attendance_records', ['student_id' => $outsider->id]);
    }

    public function test_check_in_is_rejected_cleanly_when_there_is_no_active_term(): void
    {
        $this->term->update(['is_active' => false]);

        $classroom = $this->classroomIn($this->sd);
        $this->studentIn($classroom, nis: '20100');
        $guru = $this->staff('guru', $this->sd);
        $schedule = $this->scheduleFor($classroom, $this->subject(), $guru);
        $session = $this->openSession($schedule, $guru);

        $response = $this->postJson("/api/presensi/{$session->token}/check-in", ['nis' => '20100']);

        $response->assertStatus(503);
        $this->assertDatabaseCount('attendance_records', 0);
    }
}
