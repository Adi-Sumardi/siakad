<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\PointRecord;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two achievement verification lanes (feature batch Poin 7):
 *
 *  (a) STUDENT - a teacher proposes (pending, no points yet); the child's
 *      OWN homeroom teacher decides. A teacher who is not that child's
 *      wali kelas gets 403 AT THE API; so does anyone else.
 *
 *  (b) TEACHER - a teacher proposes their own (pending); an admin_unit of
 *      the teacher's unit decides. Central admin sees everything and
 *      decides nothing here - also a 403, not a hidden button.
 */
class AchievementFlowsTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private Term $term;

    private SchoolUnit $unit;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));

        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);
        $this->term = Term::create(['academic_year_id' => $this->year->id, 'name' => 'ganjil', 'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31']);
        $this->term->activate();

        $this->unit = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD 13', 'jenjang_group' => 'sd']);

        $this->admin = User::create([
            'name' => 'Admin Pusat',
            'email' => 'admin'.uniqid().'@yapinet.id',
            'role' => 'admin',
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    /** A student actively enrolled in the given classroom, plus the class's homeroom teacher. */
    private function studentWithHomeroom(string $nama, ?int $homeroomId): array
    {
        $student = Student::create([
            'nama_lengkap' => $nama,
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->unit->id,
            'entry_year_id' => $this->year->id,
            'status' => 'active',
        ]);

        $classroom = Classroom::create([
            'school_unit_id' => $this->unit->id,
            'academic_year_id' => $this->year->id,
            'name' => '1-'.uniqid(),
            'tingkat' => 1,
            'is_active' => true,
            'homeroom_teacher_id' => $homeroomId,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'classroom_id' => $classroom->id,
            'status' => 'active',
            'joined_on' => '2026-07-01',
        ]);

        return [$student, $classroom];
    }

    private function guru(string $nama): User
    {
        return User::create([
            'name' => $nama,
            'email' => 'g'.uniqid().'@yapinet.id',
            'role' => 'guru',
            'school_unit_id' => $this->unit->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    private function propose(User $guru, Student $student): string
    {
        return $this->actingAs($guru)
            ->postJson('/api/guru/achievements', [
                'student_ulid' => $student->ulid,
                'nama_prestasi' => 'Juara 1 Olimpiade',
                'kategori' => 'Akademik',
                'tingkat' => 'Kabupaten/Kota',
                'juara' => '1',
                'points_awarded' => 20,
            ])
            ->assertCreated()
            ->json('achievement.ulid');
    }

    public function test_a_teacher_proposal_is_pending_with_no_points_and_the_homeroom_decides(): void
    {
        $waliKelas = $this->guru('Wali Kelas Sejati');
        [$student] = $this->studentWithHomeroom('Anak Bimbingan', $waliKelas->id);

        $pengaju = $this->guru('Guru Pengaju');
        $ulid = $this->propose($pengaju, $student);

        // Pending, and ZERO point records - the proposal awards nothing.
        $this->assertDatabaseHas('achievements', ['ulid' => $ulid, 'status' => 'pending']);
        $this->assertSame(0, PointRecord::where('student_id', $student->id)->count());

        // A teacher who is NOT the child's wali kelas: 403 at the API.
        $this->actingAs($pengaju)
            ->postJson("/api/guru/achievements/{$ulid}/verify", ['points_awarded' => 20])
            ->assertStatus(403);
        $this->assertSame(0, PointRecord::where('student_id', $student->id)->count());

        // The homeroom teacher decides - once, with the points.
        $this->actingAs($waliKelas)
            ->postJson("/api/guru/achievements/{$ulid}/verify", ['points_awarded' => 20])
            ->assertOk();
        $this->assertDatabaseHas('achievements', ['ulid' => $ulid, 'status' => 'verified']);
        $this->assertSame(1, PointRecord::where('student_id', $student->id)->count());
        $this->assertSame(20, (int) PointRecord::where('student_id', $student->id)->value('points'));

        // A double-click on the decided row: 422, nothing new written.
        $this->actingAs($waliKelas)
            ->postJson("/api/guru/achievements/{$ulid}/verify", ['points_awarded' => 20])
            ->assertStatus(422);
        $this->assertSame(1, PointRecord::where('student_id', $student->id)->count());
    }

    public function test_homeroom_rejection_leaves_no_points(): void
    {
        $waliKelas = $this->guru('Wali Kelas Menolak');
        [$student] = $this->studentWithHomeroom('Anak Ditolak', $waliKelas->id);

        $ulid = $this->propose($this->guru('Guru Lain'), $student);

        $this->actingAs($waliKelas)
            ->postJson("/api/guru/achievements/{$ulid}/reject", ['reason' => 'Bukti tidak mencukupi.'])
            ->assertOk();

        $this->assertDatabaseHas('achievements', ['ulid' => $ulid, 'status' => 'rejected']);
        $this->assertSame(0, PointRecord::where('student_id', $student->id)->count());
    }

    public function test_a_teacher_self_achievement_is_decided_by_their_admin_unit_not_central(): void
    {
        $guru = $this->guru('Guru Berprestasi');

        $ulid = $this->actingAs($guru)
            ->postJson('/api/guru/achievements/self', [
                'nama_prestasi' => 'Guru Teladan Tingkat Kota',
                'kategori' => 'Non-Akademik',
                'tingkat' => 'Kabupaten/Kota',
                'juara' => '1',
            ])
            ->assertCreated()
            ->json('achievement.ulid');

        $this->assertDatabaseHas('achievements', [
            'ulid' => $ulid,
            'achiever_type' => 'guru',
            'teacher_user_id' => $guru->id,
            'school_unit_id' => $this->unit->id,
            'status' => 'pending',
        ]);

        // Central admin SEES the row on the Prestasi Guru tab...
        $this->actingAs($this->admin)
            ->getJson('/api/admin/achievements?achiever_type=guru')
            ->assertOk()
            ->assertJsonFragment(['ulid' => $ulid]);

        // ...and central admin DECIDES NOTHING: 403, not a hidden button.
        $this->actingAs($this->admin)
            ->postJson("/api/admin/achievements/{$ulid}/verify")
            ->assertStatus(403);

        // The admin_unit of ANOTHER unit cannot decide it either.
        $smp = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP 12', 'jenjang_group' => 'smp']);
        $adminUnitAsing = User::create([
            'name' => 'Admin Unit SMP',
            'email' => 'ausmp'.uniqid().'@yapinet.id',
            'role' => 'admin_unit',
            'school_unit_id' => $smp->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $this->actingAs($adminUnitAsing)
            ->postJson("/api/admin/achievements/{$ulid}/verify")
            ->assertStatus(404, 'visibleTo: prestasi guru unit lain tak terlihat sama sekali');

        // The OWN admin_unit decides.
        $adminUnit = User::create([
            'name' => 'Admin Unit SD',
            'email' => 'ausd'.uniqid().'@yapinet.id',
            'role' => 'admin_unit',
            'school_unit_id' => $this->unit->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $this->actingAs($adminUnit)
            ->postJson("/api/admin/achievements/{$ulid}/verify")
            ->assertOk();
        $this->assertDatabaseHas('achievements', ['ulid' => $ulid, 'status' => 'verified']);
    }
}
