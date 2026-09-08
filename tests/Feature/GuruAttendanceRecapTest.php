<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\ClassSchedule;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The class-wide H/S/I/A recap - the teacher's answer to "who in my class
 * keeps missing school". Three things must hold: revoked marks never count
 * (they are corrections, not absences), every roster student is listed zeros
 * included (a blank row is itself a finding), and another unit's classroom
 * is a 404, not a 403.
 */
class GuruAttendanceRecapTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;
    private SchoolUnit $smp;
    private Classroom $kelas;
    private Student $andi;
    private Student $budi;
    private User $guru;
    private AttendanceSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);

        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();

        Term::create([
            'academic_year_id' => $year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);

        $this->kelas = Classroom::create(['school_unit_id' => $this->sd->id, 'academic_year_id' => $year->id, 'tingkat' => 1, 'name' => '1A']);

        $this->andi = $this->student('Andi Pratama');
        $this->budi = $this->student('Budi Santoso');

        foreach ([$this->andi, $this->budi] as $siswa) {
            Enrollment::create([
                'student_id' => $siswa->id, 'classroom_id' => $this->kelas->id,
                'academic_year_id' => $year->id, 'status' => 'active', 'joined_on' => '2026-07-01',
            ]);
        }

        $this->guru = User::create([
            'name' => 'Guru SD', 'email' => 'guru'.uniqid().'@yapinet.id', 'role' => 'guru',
            'school_unit_id' => $this->sd->id, 'is_active' => true, 'activated_at' => now(),
        ]);

        $mapel = Subject::create(['school_unit_id' => $this->sd->id, 'code' => 'MTK', 'name' => 'Matematika']);
        $jadwal = ClassSchedule::create([
            'classroom_id' => $this->kelas->id, 'subject_id' => $mapel->id, 'teacher_id' => $this->guru->id,
            'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
        ]);

        $this->session = AttendanceSession::create([
            'class_schedule_id' => $jadwal->id, 'occurred_on' => '2026-09-01',
            'token' => Str::random(64), 'opened_at' => now(), 'expires_at' => now()->addHour(),
        ]);
    }

    private function student(string $nama): Student
    {
        return Student::create([
            'school_unit_id' => $this->sd->id,
            'entry_year_id' => AcademicYear::first()->id,
            'nama_lengkap' => $nama, 'jenis_kelamin' => 'L', 'status' => 'active',
        ]);
    }

    private function mark(Student $siswa, string $status, string $tanggal, array $extra = []): AttendanceRecord
    {
        return AttendanceRecord::create([
            'student_id' => $siswa->id,
            'attendance_session_id' => $this->session->id,
            'classroom_id' => $this->kelas->id,
            'term_id' => Term::first()->id,
            'attendance_status' => $status,
            'occurred_on' => $tanggal,
            'source' => 'guru',
            'recorded_by' => $this->guru->id,
            ...$extra,
        ]);
    }

    public function test_recap_tallies_per_student_including_zero_rows(): void
    {
        $this->mark($this->andi, 'hadir', '2026-09-01');
        $this->mark($this->andi, 'hadir', '2026-09-02');
        $this->mark($this->andi, 'alpa', '2026-09-03');
        // A revoked mark is a correction, never an absence.
        $this->mark($this->andi, 'hadir', '2026-09-04', [
            'record_status' => 'revoked', 'revoked_by' => $this->guru->id,
            'revoked_at' => now(), 'revoke_reason' => 'salah tekan',
        ]);
        // Budi has no marks at all - he must still be on the recap.

        $res = $this->actingAs($this->guru)
            ->getJson("/api/guru/classrooms/{$this->kelas->ulid}/attendance")
            ->assertOk();

        $res->assertJsonPath('period.from', '2026-07-01'); // term-to-date by default

        $andi = $res->json('students.0');
        $this->assertSame('Andi Pratama', $andi['nama_lengkap']);
        $this->assertSame(2, $andi['hadir']);
        $this->assertSame(1, $andi['alpa']);
        $this->assertSame(0, $andi['sakit']);
        $this->assertSame(0, $andi['izin']);

        $budi = $res->json('students.1');
        $this->assertSame('Budi Santoso', $budi['nama_lengkap']);
        $this->assertSame(0, $budi['hadir'] + $budi['sakit'] + $budi['izin'] + $budi['alpa']);
    }

    public function test_another_units_classroom_is_a_404(): void
    {
        $kelasSmp = Classroom::create([
            'school_unit_id' => $this->smp->id, 'academic_year_id' => AcademicYear::first()->id,
            'tingkat' => 7, 'name' => '7A',
        ]);

        $this->actingAs($this->guru)
            ->getJson("/api/guru/classrooms/{$kelasSmp->ulid}/attendance")
            ->assertNotFound();
    }

    public function test_explicit_date_range_bounds_the_tally(): void
    {
        $this->mark($this->andi, 'alpa', '2026-08-01');
        $this->mark($this->andi, 'hadir', '2026-09-01');

        $res = $this->actingAs($this->guru)
            ->getJson("/api/guru/classrooms/{$this->kelas->ulid}/attendance?from=2026-08-15")
            ->assertOk();

        $this->assertSame(1, $res->json('students.0.hadir'));
        $this->assertSame(0, $res->json('students.0.alpa'));
        $this->assertSame('2026-08-15', $res->json('period.from'));
    }
}
