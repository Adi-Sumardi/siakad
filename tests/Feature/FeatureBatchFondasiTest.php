<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Support\Jenjang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fondasi batch fitur 13 poin: the shared jenjang ladder (Poin 1) and the
 * enriched reference responses everything else builds on (active_student_count
 * for Poin 3's per-class table, jenjang_group for the frontend matcher).
 */
class FeatureBatchFondasiTest extends TestCase
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

    private function classroom(SchoolUnit $unit, string $name, ?int $tingkat): Classroom
    {
        return Classroom::create([
            'school_unit_id' => $unit->id,
            'academic_year_id' => $this->year->id,
            'name' => $name,
            'tingkat' => $tingkat,
            'is_active' => true,
        ]);
    }

    public function test_the_ladder_matches_early_childhood_by_classroom_name(): void
    {
        $tk = SchoolUnit::create(['code' => 'TK-13', 'label' => 'TKI 13', 'jenjang_group' => 'tk']);
        $pg = SchoolUnit::create(['code' => 'PG-SAKINAH', 'label' => 'PG Sakinah', 'jenjang_group' => 'pg']);
        $ra = SchoolUnit::create(['code' => 'RA-SAKINAH', 'label' => 'RA Sakinah', 'jenjang_group' => 'ra']);
        $sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD 13', 'jenjang_group' => 'sd']);

        $tkA = $this->classroom($tk, 'TK-A 1', 0);
        $tkB = $this->classroom($tk, 'TK B Mawar', 0);
        $sb = $this->classroom($pg, 'SB Merpati', 0);
        $kb = $this->classroom($pg, 'KB-2', 0);
        $raCls = $this->classroom($ra, 'Kelas A', 0);
        $sd3 = $this->classroom($sd, '3-A', 3);

        // Separator-stripped lowercase prefixes, by design (user's choice):
        // "TK-A 1"/"TK B Mawar"/"SB-2" normalize to tka/tkb/sb/kb.
        $this->assertSame('tk-a', Jenjang::keyForClassroom($tkA->fresh('schoolUnit')));
        $this->assertSame('tk-b', Jenjang::keyForClassroom($tkB->fresh('schoolUnit')));
        $this->assertSame('pg-sb', Jenjang::keyForClassroom($sb->fresh('schoolUnit')));
        $this->assertSame('pg-kb', Jenjang::keyForClassroom($kb->fresh('schoolUnit')));
        $this->assertSame('ra', Jenjang::keyForClassroom($raCls->fresh('schoolUnit')));
        $this->assertSame('sd-3', Jenjang::keyForClassroom($sd3->fresh('schoolUnit')));

        // Coarse groups resolve through granular keys.
        $this->assertSame('tk', Jenjang::groupOf('tk-a'));
        $this->assertSame('smp', Jenjang::groupOf('smp'));
        $this->assertNull(Jenjang::groupOf('tidak-ada'));

        // A name no ladder token recognizes stays outside the granular
        // rungs (null), never mis-bucketed.
        $aneh = $this->classroom($tk, 'Kelompok Melati', 0);
        $this->assertNull(Jenjang::keyForClassroom($aneh->fresh('schoolUnit')));
    }

    public function test_the_ladder_query_narrows_by_group_tingkat_and_name(): void
    {
        $tk = SchoolUnit::create(['code' => 'TK-13', 'label' => 'TKI 13', 'jenjang_group' => 'tk']);
        $sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD 13', 'jenjang_group' => 'sd']);

        $this->classroom($tk, 'TK-A 1', 0);
        $this->classroom($tk, 'TK-B 1', 0);
        $this->classroom($sd, '3-A', 3);
        $this->classroom($sd, '4-A', 4);

        $keys = fn ($key) => Classroom::query()->tap(fn ($q) => Jenjang::applyToClassroomQuery($q, $key))->pluck('name')->sort()->values();

        $this->assertSame(['TK-A 1'], $keys('tk-a')->all());
        $this->assertSame(['TK-B 1'], $keys('tk-b')->all());
        $this->assertSame(['3-A'], $keys('sd-3')->all());
        // Coarse keeps the historical behaviour: the whole group.
        $this->assertSame(['3-A', '4-A'], $keys('sd')->all());
    }

    public function test_reference_responses_carry_jenjang_group_and_active_student_count(): void
    {
        $sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD 13', 'jenjang_group' => 'sd']);
        $classroom = $this->classroom($sd, '1-A', 1);

        foreach ([1, 2] as $i) {
            $student = Student::create([
                'nama_lengkap' => "Siswa Filter {$i}",
                'jenis_kelamin' => 'L',
                'school_unit_id' => $sd->id,
                'entry_year_id' => $this->year->id,
                'status' => 'active',
            ]);

            Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $this->year->id,
                'classroom_id' => $classroom->id,
                'status' => $i === 1 ? 'active' : 'promoted',
                'joined_on' => '2026-07-01',
            ]);
        }

        $units = $this->actingAs($this->admin)->getJson('/api/admin/school-units')->assertOk()->json('school_units');
        $this->assertSame('sd', collect($units)->firstWhere('code', 'SD-13')['jenjang_group']);

        $classrooms = $this->actingAs($this->admin)
            ->getJson("/api/admin/classrooms?academic_year_ulid={$this->year->ulid}")
            ->assertOk()
            ->json('classrooms');

        $row = collect($classrooms)->firstWhere('name', '1-A');
        $this->assertSame(1, $row['active_student_count'], 'hanya enrollment aktif yang dihitung');
        $this->assertSame('sd', $row['school_unit']['jenjang_group']);
    }
}
