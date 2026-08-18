<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\PointRecord;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A teacher's account of a win is trusted immediately; a guardian's own is
 * not - it waits for someone independent to confirm it actually happened
 * before it can carry a single point.
 */
class AchievementTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $year->activate();
        $this->term = Term::create([
            'academic_year_id' => $year->id, 'name' => 'ganjil',
            'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31', 'is_active' => true,
        ]);

        // Files this app stores are on the private disk - fake it so the suite
        // never touches the real filesystem, and every test starts from an
        // empty one instead of accumulating fixtures across runs.
        Storage::fake('local');
    }

    private function student(): Student
    {
        return Student::create([
            'nama_lengkap' => 'Aisyah Nur Ramadhani', 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->unit->id, 'status' => 'active',
        ]);
    }

    private function staff(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $this->unit->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function guardianFor(Student $student): User
    {
        $user = User::create([
            'name' => 'Budi', 'email' => 'budi'.uniqid().'@example.com',
            'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
        ]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Budi', 'hubungan' => 'ayah', 'email' => $user->email]);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        return $user;
    }

    private function payload(): array
    {
        return [
            'nama_prestasi' => 'Juara 1 Tahfidz', 'kategori' => 'Non-Akademik',
            'tingkat' => 'Kecamatan', 'juara' => '1', 'nama_event' => 'Lomba Tahfidz Kecamatan',
            'tanggal_event' => now()->subDays(3)->toDateString(),
        ];
    }

    public function test_a_guru_recorded_achievement_is_verified_on_the_spot(): void
    {
        $student = $this->student();
        $guru = $this->staff('guru');

        $response = $this->actingAs($guru)->postJson('/api/guru/achievements', $this->payload() + ['student_ulid' => $student->ulid]);

        $response->assertStatus(201)
            ->assertJsonPath('achievement.status', 'verified')
            ->assertJsonPath('achievement.source', 'sekolah');

        $achievement = Achievement::first();
        $this->assertSame($guru->id, $achievement->verified_by);
        $this->assertNotNull($achievement->verified_at);
    }

    public function test_a_guru_can_award_points_at_creation_and_it_reaches_the_ledger(): void
    {
        $student = $this->student();
        $guru = $this->staff('guru');

        $this->actingAs($guru)->postJson('/api/guru/achievements', $this->payload() + [
            'student_ulid' => $student->ulid, 'points_awarded' => 25,
        ])->assertStatus(201)->assertJsonPath('achievement.point_awarded', 25);

        $achievement = Achievement::first();
        $record = PointRecord::where('related_achievement_id', $achievement->id)->first();

        $this->assertNotNull($record);
        $this->assertSame(25, $record->points);
        $this->assertSame('merit', $record->type);
    }

    public function test_a_guardians_submission_starts_pending_with_no_points(): void
    {
        $student = $this->student();
        $user = $this->guardianFor($student);

        $this->actingAs($user)
            ->postJson("/api/wali/students/{$student->ulid}/achievements", $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('achievement.status', 'pending')
            ->assertJsonPath('achievement.point_awarded', null);

        $this->assertDatabaseCount('point_records', 0);
    }

    public function test_a_guardian_cannot_award_points_by_submitting_them(): void
    {
        $student = $this->student();
        $user = $this->guardianFor($student);

        // points_awarded is simply not a field the wali endpoint accepts -
        // sent or not, it cannot influence the row.
        $this->actingAs($user)->postJson("/api/wali/students/{$student->ulid}/achievements", $this->payload() + [
            'points_awarded' => 999,
        ])->assertStatus(201);

        $this->assertNull(Achievement::first()->point_awarded);
    }

    public function test_admin_verifies_a_pending_submission_and_can_award_points(): void
    {
        $student = $this->student();
        $user = $this->guardianFor($student);
        $this->actingAs($user)->postJson("/api/wali/students/{$student->ulid}/achievements", $this->payload());

        $achievement = Achievement::first();
        $admin = $this->staff('admin_unit');

        $this->actingAs($admin)
            ->postJson("/api/admin/achievements/{$achievement->ulid}/verify", ['points_awarded' => 20])
            ->assertOk()
            ->assertJsonPath('achievement.status', 'verified')
            ->assertJsonPath('achievement.point_awarded', 20);

        $this->assertSame(1, PointRecord::where('related_achievement_id', $achievement->id)->count());
    }

    public function test_admin_rejects_a_pending_submission_with_a_reason(): void
    {
        $student = $this->student();
        $user = $this->guardianFor($student);
        $this->actingAs($user)->postJson("/api/wali/students/{$student->ulid}/achievements", $this->payload());

        $achievement = Achievement::first();
        $admin = $this->staff('admin_unit');

        $this->actingAs($admin)->postJson("/api/admin/achievements/{$achievement->ulid}/reject", [])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson("/api/admin/achievements/{$achievement->ulid}/reject", ['reason' => 'Tidak ada bukti pendukung'])
            ->assertOk()
            ->assertJsonPath('achievement.status', 'rejected');

        $this->assertDatabaseCount('point_records', 0);
    }

    public function test_an_already_decided_achievement_cannot_be_decided_again(): void
    {
        $student = $this->student();
        $guru = $this->staff('guru'); // already verified on creation
        $this->actingAs($guru)->postJson('/api/guru/achievements', $this->payload() + ['student_ulid' => $student->ulid]);

        $achievement = Achievement::first();
        $admin = $this->staff('admin_unit');

        $this->actingAs($admin)
            ->postJson("/api/admin/achievements/{$achievement->ulid}/verify", [])
            ->assertStatus(422);
    }

    public function test_a_guardian_cannot_verify_any_submission(): void
    {
        $student = $this->student();
        $user = $this->guardianFor($student);
        $this->actingAs($user)->postJson("/api/wali/students/{$student->ulid}/achievements", $this->payload());

        $achievement = Achievement::first();

        $this->actingAs($user)
            ->postJson("/api/admin/achievements/{$achievement->ulid}/verify", [])
            ->assertStatus(403);
    }

    public function test_a_unit_admin_cannot_decide_on_another_units_submission(): void
    {
        $student = $this->student();
        $user = $this->guardianFor($student);
        $this->actingAs($user)->postJson("/api/wali/students/{$student->ulid}/achievements", $this->payload());
        $achievement = Achievement::first();

        $otherUnit = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);
        $outsider = User::create([
            'name' => 'Admin SMP', 'email' => 'admin.smp@yapinet.id', 'role' => 'admin_unit',
            'school_unit_id' => $otherUnit->id, 'is_active' => true, 'activated_at' => now(),
        ]);

        $this->actingAs($outsider)
            ->postJson("/api/admin/achievements/{$achievement->ulid}/verify", [])
            ->assertStatus(404);
    }

    public function test_a_certificate_download_is_gated_by_ownership(): void
    {
        $student = $this->student();
        $guru = $this->staff('guru');
        $file = UploadedFile::fake()->create('sertifikat.pdf', 100, 'application/pdf');

        $this->actingAs($guru)->postJson('/api/guru/achievements', $this->payload() + [
            'student_ulid' => $student->ulid, 'sertifikat' => $file,
        ])->assertStatus(201);

        $achievement = Achievement::first();
        $owner = $this->guardianFor($student);

        $this->actingAs($owner)
            ->get("/api/files/achievements/{$achievement->ulid}/sertifikat")
            ->assertOk();

        $strangerStudent = $this->student();
        $stranger = $this->guardianFor($strangerStudent);

        $this->actingAs($stranger)
            ->get("/api/files/achievements/{$achievement->ulid}/sertifikat")
            ->assertStatus(404);
    }

    public function test_the_admin_achievement_list_shows_pending_before_decided(): void
    {
        $student = $this->student();
        $user = $this->guardianFor($student);
        $this->actingAs($user)->postJson("/api/wali/students/{$student->ulid}/achievements", $this->payload());

        $guru = $this->staff('guru');
        $this->actingAs($guru)->postJson('/api/guru/achievements', $this->payload() + ['student_ulid' => $this->student()->ulid]);

        $admin = $this->staff('admin_unit');

        $response = $this->actingAs($admin)->getJson('/api/admin/achievements')->assertOk();
        $statuses = collect($response->json('achievements'))->pluck('status');

        $this->assertSame('pending', $statuses->first());
    }
}
