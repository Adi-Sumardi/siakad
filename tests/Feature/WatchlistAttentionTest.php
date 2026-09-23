<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\PointRecord;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The watchlist drill-down (GET /api/admin/students/attention) - the named
 * students behind the dashboard's "Perlu Perhatian" counts. It must agree
 * with DashboardSummaryController tile-by-tile (both read WatchlistService),
 * show the reason(s) + numbers per student, and stay inside the caller's
 * unit scope.
 */
class WatchlistAttentionTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;
    private SchoolUnit $smp;
    private AcademicYear $year;
    private Term $term;
    private Term $prevTerm;
    private Classroom $kelas5a;
    private Subject $mapel;
    private User $pusat;
    private User $adminSd;

    private Student $andi;   // alpa >= 5
    private Student $budi;   // nilai akhir < KKM
    private Student $cici;   // turun >= 5 poin antar semester
    private Student $dedi;   // pelanggaran poin
    private Student $eka;    // bersih - tidak boleh muncul
    private Student $fajar;  // alpa tinggi, tapi unit SMP

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD Islam Al Azhar 13', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP Islam Al Azhar 12', 'jenjang_group' => 'smp']);

        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();
        $this->term = Term::create([
            'academic_year_id' => $this->year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);

        // The term before: no earlier term in-year, so previousTerm() falls
        // back to the previous year's latest term.
        $prevYear = AcademicYear::create(['year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
        $this->prevTerm = Term::create([
            'academic_year_id' => $prevYear->id, 'name' => 'genap',
            'starts_on' => '2026-01-01', 'ends_on' => '2026-06-30', 'is_active' => false,
        ]);

        $this->kelas5a = Classroom::create(['school_unit_id' => $this->sd->id, 'academic_year_id' => $this->year->id, 'tingkat' => 5, 'name' => '5A']);
        $kelasSmp = Classroom::create(['school_unit_id' => $this->smp->id, 'academic_year_id' => $this->year->id, 'tingkat' => 7, 'name' => '7A']);
        $this->mapel = Subject::create(['school_unit_id' => $this->sd->id, 'code' => 'MTK', 'name' => 'Matematika']);

        $this->pusat = User::create([
            'name' => 'Admin Pusat', 'email' => 'pusat'.uniqid().'@yapinet.id', 'role' => 'admin',
            'is_active' => true, 'activated_at' => now(),
        ]);
        $this->adminSd = User::create([
            'name' => 'Admin SD', 'email' => 'sd'.uniqid().'@yapinet.id', 'role' => 'admin_unit',
            'school_unit_id' => $this->sd->id, 'is_active' => true, 'activated_at' => now(),
        ]);

        $this->andi = $this->student('Andi Alpa', $this->sd, ['absent_count' => 6]);
        $this->budi = $this->student('Budi Bawah KKM', $this->sd, ['absent_count' => 0], ['tugas' => 60, 'uts' => 60, 'uas' => 60]);
        // 71.5 sekarang vs 85 semester lalu: turun 13.5 poin, tapi masih >= KKM.
        $this->cici = $this->student('Cici Turun', $this->sd, ['absent_count' => 0], ['tugas' => 70, 'uts' => 75, 'uas' => 70], ['tugas' => 85, 'uts' => 85, 'uas' => 85]);
        $this->eka = $this->student('Eka Bersih', $this->sd, ['absent_count' => 0], ['tugas' => 90, 'uts' => 90, 'uas' => 90]);
        $this->fajar = $this->student('Fajar SMP', $this->smp, ['absent_count' => 7, 'classroom_id' => $kelasSmp->id]);

        $this->dedi = $this->student('Dedi Pelanggar', $this->sd, ['absent_count' => 0]);
        PointRecord::create([
            'student_id' => $this->dedi->id, 'term_id' => $this->term->id, 'type' => 'violation',
            'points' => -10, 'occurred_on' => '2026-09-01', 'description' => 'Terlambat masuk kelas',
            'recorded_by' => $this->pusat->id, 'status' => 'recorded',
        ]);
    }

    /**
     * @param  array{absent_count?:int,classroom_id?:int}  $enrollment  empty = no enrollment row (unplaced)
     */
    private function student(string $nama, SchoolUnit $unit, array $enrollment = [], ?array $currentScores = null, ?array $prevScores = null): Student
    {
        $student = Student::create([
            'school_unit_id' => $unit->id, 'entry_year_id' => $this->year->id,
            'nama_lengkap' => $nama, 'jenis_kelamin' => 'L', 'status' => 'active',
        ]);

        if ($enrollment !== []) {
            Enrollment::create([
                'student_id' => $student->id, 'classroom_id' => $enrollment['classroom_id'] ?? $this->kelas5a->id,
                'academic_year_id' => $this->year->id, 'status' => 'active', 'joined_on' => '2026-07-01',
                'absent_count' => $enrollment['absent_count'] ?? 0,
            ]);
        }

        foreach (['current' => [$currentScores, $this->term], 'prev' => [$prevScores, $this->prevTerm]] as [$scores, $term]) {
            if ($scores === null) {
                continue;
            }
            foreach ($scores as $category => $score) {
                Grade::create([
                    'student_id' => $student->id, 'subject_id' => $this->mapel->id,
                    'classroom_id' => $this->kelas5a->id, 'term_id' => $term->id,
                    'category' => $category, 'score' => $score, 'recorded_by' => $this->pusat->id,
                ]);
            }
        }

        return $student;
    }

    public function test_lists_every_flagged_student_with_reasons_and_metrics(): void
    {
        $res = $this->actingAs($this->pusat)->getJson('/api/admin/students/attention')->assertOk();

        $names = collect($res->json('students'))->pluck('nama_lengkap');
        $this->assertCount(5, $names);
        // Eka has data but trips no condition - the drill-down shows
        // conditions, not rosters.
        $this->assertNotContains('Eka Bersih', $names->all());

        $rows = collect($res->json('students'))->keyBy('nama_lengkap');

        $andi = $rows->get('Andi Alpa');
        $this->assertSame(['absenteeism'], $andi['reasons']);
        $this->assertSame(6, $andi['metrics']['alpa_count']);
        $this->assertSame('5A', $andi['classroom']['name']);

        $budi = $rows->get('Budi Bawah KKM');
        $this->assertSame(['below_kkm'], $budi['reasons']);
        $this->assertEquals(60.0, $budi['metrics']['current_average']);

        $cici = $rows->get('Cici Turun');
        $this->assertSame(['grade_decline'], $cici['reasons']);
        $this->assertEquals(13.5, $cici['metrics']['average_drop']);

        $dedi = $rows->get('Dedi Pelanggar');
        $this->assertSame(['point_violation'], $dedi['reasons']);
        $this->assertSame(1, $dedi['metrics']['violation_records']);

        // Tile-agreement contract: counts per reason + total the dashboard
        // shows, straight from the same WatchlistService rows.
        $this->assertSame(5, $res->json('total'));
        $this->assertSame(2, $res->json('counts.absenteeism'));
        $this->assertSame(1, $res->json('counts.below_kkm'));
        $this->assertSame(1, $res->json('counts.grade_decline'));
        $this->assertSame(1, $res->json('counts.point_violation'));
        $this->assertSame(70, $res->json('thresholds.kkm'));
    }

    public function test_reason_filter_narrows_without_changing_totals(): void
    {
        $res = $this->actingAs($this->pusat)
            ->getJson('/api/admin/students/attention?reason=absenteeism')
            ->assertOk();

        $names = collect($res->json('students'))->pluck('nama_lengkap');
        $this->assertEqualsCanonicalizing(['Andi Alpa', 'Fajar SMP'], $names->all());
        $this->assertSame(5, $res->json('total')); // totals stay unfiltered
    }

    public function test_unit_admin_only_sees_own_unit(): void
    {
        $res = $this->actingAs($this->adminSd)->getJson('/api/admin/students/attention')->assertOk();

        $names = collect($res->json('students'))->pluck('nama_lengkap');
        $this->assertContains('Andi Alpa', $names->all());
        $this->assertNotContains('Fajar SMP', $names->all());
        $this->assertSame(4, $res->json('total'));
    }
}
