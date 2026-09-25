<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The granular jenjang filter on Data Siswa (feature batch Poin 1): one
 * ladder, sixteen keys. Coarse group keys (tk|sd|smp|sma) keep the
 * historical behaviour; granular keys (sd-3, tk-a, pg-sb…) additionally
 * match the classroom's tingkat and, for early childhood, its name prefix
 * - through the student's ACTIVE enrollment, so unplaced students stay
 * outside the granular result by design.
 */
class StudentJenjangFilterTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));

        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);

        $this->admin = User::create([
            'name' => 'Admin Pusat',
            'email' => 'admin'.uniqid().'@yapinet.id',
            'role' => 'admin',
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    private function studentInClassroom(SchoolUnit $unit, string $classroomName, ?int $tingkat, string $nama): Student
    {
        $student = Student::create([
            'nama_lengkap' => $nama,
            'jenis_kelamin' => 'L',
            'school_unit_id' => $unit->id,
            'entry_year_id' => $this->year->id,
            'status' => 'active',
        ]);

        $classroom = Classroom::create([
            'school_unit_id' => $unit->id,
            'academic_year_id' => $this->year->id,
            'name' => $classroomName,
            'tingkat' => $tingkat,
            'is_active' => true,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'classroom_id' => $classroom->id,
            'status' => 'active',
            'joined_on' => '2026-07-01',
        ]);

        return $student;
    }

    public function test_granular_keys_filter_by_classroom_tingkat_and_name(): void
    {
        $tk = SchoolUnit::create(['code' => 'TK-13', 'label' => 'TKI 13', 'jenjang_group' => 'tk']);
        $pg = SchoolUnit::create(['code' => 'PG-SAKINAH', 'label' => 'PG Sakinah', 'jenjang_group' => 'pg']);
        $sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD 13', 'jenjang_group' => 'sd']);

        $this->studentInClassroom($tk, 'TK-A 1', 0, 'Anak TK A');
        $this->studentInClassroom($tk, 'TK-B 1', 0, 'Anak TK B');
        $this->studentInClassroom($pg, 'SB Merpati', 0, 'Anak Sanggar');
        $this->studentInClassroom($pg, 'KB Kenari', 0, 'Anak Kelompok');
        $this->studentInClassroom($sd, '3-A', 3, 'Anak SD Tiga');
        $this->studentInClassroom($sd, '4-A', 4, 'Anak SD Empat');

        $names = fn ($jenjang) => collect(
            $this->actingAs($this->admin)->getJson("/api/admin/students?jenjang={$jenjang}&per_page=100")->json('students.data')
        )->pluck('nama_lengkap')->sort()->values();

        $this->assertSame(['Anak TK A'], $names('tk-a')->all(), 'tk-a hanya kelas berprefix TK-A');
        $this->assertSame(['Anak TK B'], $names('tk-b')->all());
        $this->assertSame(['Anak Sanggar'], $names('pg-sb')->all());
        $this->assertSame(['Anak Kelompok'], $names('pg-kb')->all());
        $this->assertSame(['Anak SD Tiga'], $names('sd-3')->all());
        $this->assertSame(['Anak SD Empat'], $names('sd-4')->all());

        // Coarse keys keep the historical whole-group behaviour.
        $this->assertSame(['Anak SD Empat', 'Anak SD Tiga'], $names('sd')->all());
    }

    public function test_granular_keys_exclude_unplaced_students_and_unit_admin_stays_scoped(): void
    {
        $sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD 13', 'jenjang_group' => 'sd']);
        $smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP 12', 'jenjang_group' => 'smp']);

        $this->studentInClassroom($sd, '1-A', 1, 'Anak SD Satu');

        // An unplaced student - no enrollment at all.
        Student::create([
            'nama_lengkap' => 'Siswa Belum Ditempatkan',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $sd->id,
            'entry_year_id' => $this->year->id,
            'status' => 'active',
        ]);

        // ...and one on another unit the unit-admin must never see.
        $this->studentInClassroom($smp, '7-A', 7, 'Anak SMP');

        $names = fn ($jenjang) => collect(
            $this->actingAs($this->admin)->getJson("/api/admin/students?jenjang={$jenjang}&per_page=100")->json('students.data')
        )->pluck('nama_lengkap')->sort()->values();

        $this->assertSame(['Anak SD Satu'], $names('sd-1')->all(), 'siswa tanpa rombel tidak muncul di filter granular');

        // RBAC: an admin_unit of SD asking for another unit's students
        // (?unit=SMP-12) intersects with their own scope to nothing - the
        // audit-T18 contract: the dropdown never widens their reach.
        $adminUnit = User::create([
            'name' => 'Admin Unit SD',
            'email' => 'ausd'.uniqid().'@yapinet.id',
            'role' => 'admin_unit',
            'school_unit_id' => $sd->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $foreign = $this->actingAs($adminUnit)
            ->getJson('/api/admin/students?jenjang=sd-1&unit=SMP-12&per_page=100')
            ->assertOk()
            ->json('students.data');
        $this->assertSame([], collect($foreign)->pluck('nama_lengkap')->all());

        // And their own unit's rung still resolves through the granular
        // matcher - the SMP student stays invisible even without ?unit=.
        $own = $this->actingAs($adminUnit)
            ->getJson('/api/admin/students?per_page=100')
            ->assertOk()
            ->json('students.data');
        $this->assertSame(
            ['Anak SD Satu', 'Siswa Belum Ditempatkan'],
            collect($own)->pluck('nama_lengkap')->sort()->values()->all(),
            'scope admin unit menang atas data lintas unit (siswa SMP tak terlihat)',
        );
    }
}
