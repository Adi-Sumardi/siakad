<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSchedule;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The whole-class grade matrix - the teacher's answer to "how is my class
 * actually doing", which the per-category entry screen can never give
 * because it only ever shows one category of one subject at a time. Three
 * things must hold: every scheduled subject appears (not just the
 * requester's own - the recap is the room's, not the teacher's), the
 * weighted final is null until all three categories exist (never a guess
 * from partial data), and another unit's classroom is a 404, not a 403.
 */
class GuruGradeRecapTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;
    private SchoolUnit $smp;
    private Classroom $kelas;
    private Student $andi;
    private Student $budi;
    private User $guru;
    private Subject $mtk;
    private Subject $bind;

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

        // B. Indonesia is taught by someone else - the recap is the room's,
        // so it must appear even though this requester never teaches it.
        $guruLain = User::create([
            'name' => 'Guru Lain', 'email' => 'gurulain'.uniqid().'@yapinet.id', 'role' => 'guru',
            'school_unit_id' => $this->sd->id, 'is_active' => true, 'activated_at' => now(),
        ]);

        $this->mtk = Subject::create(['school_unit_id' => $this->sd->id, 'code' => 'MTK', 'name' => 'Matematika']);
        $this->bind = Subject::create(['school_unit_id' => $this->sd->id, 'code' => 'BIND', 'name' => 'B. Indonesia']);

        foreach ([[$this->mtk, $this->guru], [$this->bind, $guruLain]] as [$mapel, $pengajar]) {
            ClassSchedule::create([
                'classroom_id' => $this->kelas->id, 'subject_id' => $mapel->id, 'teacher_id' => $pengajar->id,
                'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00',
            ]);
        }
    }

    private function student(string $nama): Student
    {
        return Student::create([
            'school_unit_id' => $this->sd->id,
            'entry_year_id' => AcademicYear::first()->id,
            'nama_lengkap' => $nama, 'jenis_kelamin' => 'L', 'status' => 'active',
        ]);
    }

    private function nilai(Student $siswa, Subject $mapel, string $kategori, float $skor): Grade
    {
        return Grade::create([
            'student_id' => $siswa->id,
            'subject_id' => $mapel->id,
            'classroom_id' => $this->kelas->id,
            'term_id' => Term::first()->id,
            'category' => $kategori,
            'score' => $skor,
            'recorded_by' => $this->guru->id,
        ]);
    }

    public function test_recap_covers_every_scheduled_subject_and_student(): void
    {
        // Andi's Matematika is complete -> 0.2*80 + 0.3*90 + 0.5*70 = 78.
        $this->nilai($this->andi, $this->mtk, 'tugas', 80);
        $this->nilai($this->andi, $this->mtk, 'uts', 90);
        $this->nilai($this->andi, $this->mtk, 'uas', 70);
        // His B. Indonesia only has Tugas -> no final, never a partial guess.
        $this->nilai($this->andi, $this->bind, 'tugas', 75);
        // Budi has nothing at all - he must still be on the recap.

        $res = $this->actingAs($this->guru)
            ->getJson("/api/guru/classrooms/{$this->kelas->ulid}/grades")
            ->assertOk();

        // Scheduled subjects, sorted by name - including the one this
        // teacher never teaches.
        $this->assertSame(
            ['B. Indonesia', 'Matematika'],
            array_column($res->json('subjects'), 'name')
        );

        $andi = $res->json('students.0');
        $this->assertSame('Andi Pratama', $andi['nama_lengkap']);

        // json_encode writes whole floats as "80" (no zero fraction), so a
        // decoded score comes back int - compare numerically, not by type.
        $mtk = $andi['scores'][$this->mtk->ulid];
        $this->assertEquals(80.0, $mtk['tugas']);
        $this->assertEquals(90.0, $mtk['uts']);
        $this->assertEquals(70.0, $mtk['uas']);
        $this->assertEquals(78.0, $mtk['final']);

        $bind = $andi['scores'][$this->bind->ulid];
        $this->assertEquals(75.0, $bind['tugas']);
        $this->assertNull($bind['uts']);
        $this->assertNull($bind['final']);

        // The blank row is itself a finding - Budi owes every category.
        $budi = $res->json('students.1');
        $this->assertSame('Budi Santoso', $budi['nama_lengkap']);
        foreach ($budi['scores'] as $perMapel) {
            $this->assertNull($perMapel['final']);
        }
    }

    public function test_another_units_classroom_is_a_404(): void
    {
        $kelasSmp = Classroom::create([
            'school_unit_id' => $this->smp->id, 'academic_year_id' => AcademicYear::first()->id,
            'tingkat' => 7, 'name' => '7A',
        ]);

        $this->actingAs($this->guru)
            ->getJson("/api/guru/classrooms/{$kelasSmp->ulid}/grades")
            ->assertNotFound();
    }

    public function test_recap_requires_an_active_term(): void
    {
        Term::first()->update(['is_active' => false]);

        $this->actingAs($this->guru)
            ->getJson("/api/guru/classrooms/{$this->kelas->ulid}/grades")
            ->assertStatus(422);
    }
}
