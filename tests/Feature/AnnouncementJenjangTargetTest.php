<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Structured jenjang targeting on announcements (feature batch Poin 8):
 * any mix of ladder keys, stored in announcement_targets, matched in the
 * wali feed against each student's own rung (and its coarse group). A
 * per-unit admin may not target jenjang - that reaches beyond their unit.
 */
class AnnouncementJenjangTargetTest extends TestCase
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

    /** A wali whose one child sits in the given classroom. */
    private function waliWithChildIn(SchoolUnit $unit, string $classroomName, int $tingkat, string $nama): User
    {
        $student = Student::create([
            'nama_lengkap' => $nama,
            'jenis_kelamin' => 'L',
            'school_unit_id' => $unit->id,
            'entry_year_id' => $this->year->id,
            'status' => 'active',
        ]);

        $classroom = Classroom::firstOrCreate(
            ['school_unit_id' => $unit->id, 'academic_year_id' => $this->year->id, 'name' => $classroomName],
            ['tingkat' => $tingkat, 'is_active' => true],
        );

        Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'classroom_id' => $classroom->id,
            'status' => 'active',
            'joined_on' => '2026-07-01',
        ]);

        $guardian = Guardian::create(['nama' => "Wali {$nama}", 'hubungan' => 'ayah', 'email' => strtolower(str_replace(' ', '', $nama)).'@yapinet.id']);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $user = User::create([
            'name' => "Wali {$nama}",
            'email' => strtolower(str_replace(' ', '', $nama)).uniqid().'@yapinet.id',
            'role' => 'orangtua',
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $guardian->forceFill(['user_id' => $user->id])->save();

        return $user;
    }

    public function test_jenjang_targets_reach_exactly_the_targeted_rungs(): void
    {
        $sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD 13', 'jenjang_group' => 'sd']);
        $tk = SchoolUnit::create(['code' => 'TK-13', 'label' => 'TKI 13', 'jenjang_group' => 'tk']);

        $waliSatu = $this->waliWithChildIn($sd, '1-A', 1, 'Anak Satu');
        $waliDua = $this->waliWithChildIn($sd, '2-A', 2, 'Anak Dua');
        $waliTiga = $this->waliWithChildIn($sd, '3-A', 3, 'Anak Tiga');
        $waliTkA = $this->waliWithChildIn($tk, 'TK-A 1', 0, 'Anak Tk A');

        $this->actingAs($this->admin)->postJson('/api/admin/announcements', [
            'title' => 'Rapat Orang Tua SD 1-2',
            'body' => 'Khusus kelas 1 dan 2 SD.',
            'jenjang' => ['sd-1', 'sd-2'],
        ])->assertCreated();

        // The stored structure is queryable, not free text.
        $this->assertDatabaseCount('announcement_targets', 2);
        $this->assertDatabaseHas('announcement_targets', ['kind' => 'jenjang', 'value' => 'sd-1']);

        $feed = fn (User $user) => collect(
            $this->actingAs($user)->getJson('/api/wali/announcements')->assertOk()->json('announcements')
        )->pluck('title');

        $this->assertTrue($feed($waliSatu)->contains('Rapat Orang Tua SD 1-2'), 'sd-1 menerima');
        $this->assertTrue($feed($waliDua)->contains('Rapat Orang Tua SD 1-2'), 'sd-2 menerima');
        $this->assertFalse($feed($waliTiga)->contains('Rapat Orang Tua SD 1-2'), 'sd-3 tidak');
        $this->assertFalse($feed($waliTkA)->contains('Rapat Orang Tua SD 1-2'), 'tk-a tidak');
    }

    public function test_coarse_group_keys_and_unit_admin_refusal(): void
    {
        $sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD 13', 'jenjang_group' => 'sd']);
        $wali = $this->waliWithChildIn($sd, '5-A', 5, 'Anak Lima');

        // A coarse group key reaches every rung under it.
        $this->actingAs($this->admin)->postJson('/api/admin/announcements', [
            'title' => 'Vaksinasi SD',
            'body' => 'Seluruh SD.',
            'jenjang' => ['sd'],
        ])->assertCreated();

        $this->assertTrue(
            collect($this->actingAs($wali)->getJson('/api/wali/announcements')->assertOk()->json('announcements'))->pluck('title')->contains('Vaksinasi SD'),
        );

        // A per-unit admin may not target jenjang at all.
        $adminUnit = User::create([
            'name' => 'Admin Unit',
            'email' => 'au'.uniqid().'@yapinet.id',
            'role' => 'admin_unit',
            'school_unit_id' => $sd->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $this->actingAs($adminUnit)->postJson('/api/admin/announcements', [
            'title' => 'Terlarang',
            'body' => 'admin unit tidak boleh menarget jenjang.',
            'jenjang' => ['sd-1'],
        ])->assertStatus(422);
    }
}
