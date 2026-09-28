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
use Illuminate\Support\Facades\DB;
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
        // Renamed semantics (Poin 7): a teacher PROPOSES - the row lands
        // pending, awaiting the child's wali kelas or an admin.
        $student = $this->student();
        $guru = $this->staff('guru');

        $response = $this->actingAs($guru)->postJson('/api/guru/achievements', $this->payload() + ['student_ulid' => $student->ulid]);

        $response->assertStatus(201)
            ->assertJsonPath('achievement.status', 'pending')
            ->assertJsonPath('achievement.source', 'sekolah');

        $achievement = Achievement::first();
        $this->assertNull($achievement->verified_by, 'belum ada yang memutuskan');
        $this->assertNull($achievement->verified_at);
    }

    public function test_a_guru_can_award_points_at_creation_and_it_reaches_the_ledger(): void
    {
        // Poin 7: points at proposal time are a SUGGESTION for the
        // verifier - recorded as point_awarded, nothing hits the ledger
        // until the decision (the ledger assertions moved to the
        // AchievementFlowsTest homeroom lane).
        $student = $this->student();
        $guru = $this->staff('guru');

        $this->actingAs($guru)->postJson('/api/guru/achievements', $this->payload() + [
            'student_ulid' => $student->ulid, 'points_awarded' => 25,
        ])->assertStatus(201)->assertJsonPath('achievement.point_awarded', 25);

        $this->assertSame(0, \App\Models\PointRecord::count(), 'poin belum dicatat sampai diverifikasi');
    }

    public function test_guru_store_with_points_is_refused_rather_than_silently_dropped_when_no_term_is_active(): void
    {
        // Poin 7 changed the semantics: a proposal NEVER writes points, so
        // it succeeds regardless of term state - the guard now lives at
        // decision time (verified below and in AchievementFlowsTest).
        $this->term->update(['is_active' => false]);

        $student = $this->student();
        $guru = $this->staff('guru');

        $this->actingAs($guru)->postJson('/api/guru/achievements', $this->payload() + [
            'student_ulid' => $student->ulid, 'points_awarded' => 15,
        ])->assertStatus(201);

        $this->assertSame('pending', Achievement::first()->status);
        $this->assertSame(0, \App\Models\PointRecord::count());
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

    public function test_verifying_with_points_is_refused_rather_than_silently_skipped_when_no_term_is_active(): void
    {
        // The refusal moved INTO the decision service (Poin 7): a verify
        // with points while no term is active fails the whole request so
        // the decider is never told "verified" for points that quietly
        // vanished - the row stays pending. Status is 422 (state), carried
        // by the service's RuntimeException.
        $this->term->update(['is_active' => false]);

        $student = $this->student();
        $user = $this->guardianFor($student);
        $this->actingAs($user)->postJson("/api/wali/students/{$student->ulid}/achievements", $this->payload());

        $achievement = Achievement::first();
        $admin = $this->staff('admin_unit');

        $this->actingAs($admin)
            ->postJson("/api/admin/achievements/{$achievement->ulid}/verify", ['points_awarded' => 20])
            ->assertStatus(422);

        $achievement->refresh();
        $this->assertTrue($achievement->isPending(), 'The achievement must stay pending, not half-verified with no points.');
        $this->assertDatabaseCount('point_records', 0);
    }

    public function test_verifying_with_no_points_requested_still_works_with_no_active_term(): void
    {
        $this->term->update(['is_active' => false]);

        $student = $this->student();
        $user = $this->guardianFor($student);
        $this->actingAs($user)->postJson("/api/wali/students/{$student->ulid}/achievements", $this->payload());

        $achievement = Achievement::first();
        $admin = $this->staff('admin_unit');

        $this->actingAs($admin)
            ->postJson("/api/admin/achievements/{$achievement->ulid}/verify", [])
            ->assertOk()
            ->assertJsonPath('achievement.status', 'verified');
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
        // Poin 7: a guru proposal starts PENDING now, so this exercises the
        // same invariant through a real decision first - verify once,
        // then every further decision is refused.
        $student = $this->student();
        $guru = $this->staff('guru');
        $this->actingAs($guru)->postJson('/api/guru/achievements', $this->payload() + ['student_ulid' => $student->ulid]);

        $achievement = Achievement::first();
        $admin = $this->staff('admin_unit');

        // First decision succeeds.
        $this->actingAs($admin)
            ->postJson("/api/admin/achievements/{$achievement->ulid}/verify", [])
            ->assertOk();

        // Every further decision is refused - 409 Conflict (Poin 6B), not
        // 422: re-deciding is a state conflict, refused at the API so a
        // direct request can never re-award points.
        $this->actingAs($admin)
            ->postJson("/api/admin/achievements/{$achievement->ulid}/verify", [])
            ->assertStatus(409);
        $this->actingAs($admin)
            ->postJson("/api/admin/achievements/{$achievement->ulid}/reject", ['reason' => 'terlambat'])
            ->assertStatus(409);
    }

    public function test_verifying_without_points_clears_a_proposals_suggested_points(): void
    {
        // Poin 6: point_awarded used to keep the teacher proposal's value
        // after a no-points verification - a decided row that read "25
        // poin" with no ledger row behind it. The decision now writes the
        // FINAL value: null when the decider grants nothing.
        $student = $this->student();
        $guru = $this->staff('guru');
        $this->actingAs($guru)->postJson('/api/guru/achievements', $this->payload() + [
            'student_ulid' => $student->ulid, 'points_awarded' => 25,
        ]);

        $achievement = Achievement::first();
        $admin = $this->staff('admin_unit');

        $this->actingAs($admin)
            ->postJson("/api/admin/achievements/{$achievement->ulid}/verify", [])
            ->assertOk()
            ->assertJsonPath('achievement.status', 'verified');

        $this->assertDatabaseHas('achievements', ['ulid' => $achievement->ulid, 'point_awarded' => null]);
        $this->assertDatabaseCount('point_records', 0);
    }

    public function test_points_sent_for_a_teacher_achievement_are_refused_before_anything_is_written(): void
    {
        // Poin 6B: the merit ledger is a student construct - points on a
        // teacher achievement would die on the NOT NULL student_id insert
        // mid-transaction. The service refuses up front (422) instead.
        $adminUnit = $this->staff('admin_unit');
        $guru = $this->staff('guru');

        $ulid = $this->actingAs($guru)->postJson('/api/guru/achievements/self', [
            'nama_prestasi' => 'Juara 1 LKS Guru', 'kategori' => 'Akademik', 'tingkat' => 'Provinsi',
        ])->assertCreated()->json('achievement.ulid');

        $this->actingAs($adminUnit)
            ->postJson("/api/admin/achievements/{$ulid}/verify", ['points_awarded' => 10])
            ->assertStatus(422);

        $this->assertDatabaseHas('achievements', ['ulid' => $ulid, 'status' => 'pending']);
        $this->assertDatabaseCount('point_records', 0);
    }

    public function test_the_schema_refuses_a_second_active_merit_row_for_one_achievement(): void
    {
        // Poin 6B, schema half: the partial unique index is the guarantee
        // that survives even a future writer that forgets the atomic
        // claim. Revoked rows must stay exempt - a revoke is the repair
        // path itself.
        $student = $this->student();
        $achievement = Achievement::create([
            'student_id' => $student->id, 'nama_prestasi' => 'Juara 2 Cipta Puisi',
            'kategori' => 'Seni', 'tingkat' => 'Kabupaten/Kota', 'status' => 'verified',
        ]);
        $recorder = $this->staff('admin');

        $first = PointRecord::create([
            'student_id' => $student->id, 'term_id' => $this->term->id,
            'related_achievement_id' => $achievement->id, 'type' => 'merit', 'points' => 15,
            'occurred_on' => today(), 'description' => 'Penghargaan prestasi: Juara 2 Cipta Puisi',
            'recorded_by' => $recorder->id, 'status' => 'recorded',
        ]);

        try {
            PointRecord::create([
                'student_id' => $student->id, 'term_id' => $this->term->id,
                'related_achievement_id' => $achievement->id, 'type' => 'merit', 'points' => 15,
                'occurred_on' => today(), 'description' => 'duplikat',
                'recorded_by' => $recorder->id, 'status' => 'recorded',
            ]);
            $this->fail('The unique index should have refused a second ACTIVE merit row for one achievement.');
        } catch (\Illuminate\Database\QueryException) {
            // Refused at the schema level - exactly what Poin 6B asked for.
        }

        // The revoke path stays open: revoked rows are excluded by the
        // partial index, so repairing a duplicate never violates it.
        $first->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoke_reason' => 'uji'])->save();
        PointRecord::create([
            'student_id' => $student->id, 'term_id' => $this->term->id,
            'related_achievement_id' => $achievement->id, 'type' => 'merit', 'points' => 15,
            'occurred_on' => today(), 'description' => 'pengganti setelah revoke',
            'recorded_by' => $recorder->id, 'status' => 'recorded',
        ]);
        $this->assertSame(2, PointRecord::where('related_achievement_id', $achievement->id)->count());
        $this->assertSame(1, PointRecord::where('related_achievement_id', $achievement->id)->active()->count());
    }

    public function test_the_repair_command_revokes_only_the_later_duplicates(): void
    {
        // Poin 6C: pre-index data could carry several active merit rows for
        // one achievement. Simulate it by dropping the index, planting a
        // duplicate, then running the audit's repair half: earliest row
        // survives, the rest are REVOKED (never deleted - D6) with a
        // reason that names the bug.
        $student = $this->student();
        $achievement = Achievement::create([
            'student_id' => $student->id, 'nama_prestasi' => 'Juara 1 Paduan Suara',
            'kategori' => 'Seni', 'tingkat' => 'Kota', 'status' => 'verified', 'point_awarded' => 15,
        ]);
        $recorder = $this->staff('admin');

        DB::statement('DROP INDEX IF EXISTS point_records_one_active_merit_per_achievement');

        foreach ([0, 1, 2] as $n) {
            PointRecord::create([
                'student_id' => $student->id, 'term_id' => $this->term->id,
                'related_achievement_id' => $achievement->id, 'type' => 'merit', 'points' => 15,
                'occurred_on' => today(), 'description' => "baris ke-{$n}",
                'recorded_by' => $recorder->id, 'status' => 'recorded',
            ]);
        }

        // Dry run first: reviews the audit without touching anything.
        $this->artisan('prestasi:repair-points')->assertSuccessful();
        $this->assertSame(3, PointRecord::where('related_achievement_id', $achievement->id)->active()->count());

        $this->artisan('prestasi:repair-points', ['--confirm' => true])->assertSuccessful();

        $remaining = PointRecord::where('related_achievement_id', $achievement->id)->orderBy('id')->get();
        $this->assertSame('recorded', $remaining[0]->status, 'baris paling awal dipertahankan');
        $this->assertSame('baris ke-0', $remaining[0]->description);
        $this->assertSame('revoked', $remaining[1]->status);
        $this->assertSame('revoked', $remaining[2]->status);
        $this->assertSame(15, (int) PointRecord::where('student_id', $student->id)->active()->sum('points'));
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
            ->assertOk()
            // The name the uploader gave it, not the random path it's stored
            // under - a family opening this should not get "aB3f...xyz.pdf".
            ->assertHeader('content-disposition', 'inline; filename=sertifikat.pdf');

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

    public function test_an_identical_pending_submission_is_refused_rather_than_stacked(): void
    {
        // Follow-up to the "points added repeatedly" report: the same win
        // filed twice (double-clicked submit, wali and guru both filing it)
        // used to stack pending cards that each legitimately earned their
        // own points at verify time. One pending card per identical win.
        $student = $this->student();
        $user = $this->guardianFor($student);

        $this->actingAs($user)->postJson("/api/wali/students/{$student->ulid}/achievements", $this->payload())->assertCreated();

        $this->actingAs($user)
            ->postJson("/api/wali/students/{$student->ulid}/achievements", $this->payload())
            ->assertStatus(422);
        $this->assertSame(1, Achievement::where('student_id', $student->id)->count());

        // A genuinely different win still goes through. (Spread, not array
        // `+`: the union operator keeps the FIRST key, so `+ ['nama_prestasi'
        // => …]` would silently resend the same name.)
        $this->actingAs($user)
            ->postJson("/api/wali/students/{$student->ulid}/achievements", [...$this->payload(), 'nama_prestasi' => 'Juara 2 Lomba Puisi'])
            ->assertCreated();
        $this->assertSame(2, Achievement::where('student_id', $student->id)->count());
    }

    public function test_a_guru_cannot_stack_an_identical_pending_proposal_either(): void
    {
        $student = $this->student();
        $guru = $this->staff('guru');

        $this->actingAs($guru)->postJson('/api/guru/achievements', $this->payload() + ['student_ulid' => $student->ulid])->assertCreated();

        $this->actingAs($guru)
            ->postJson('/api/guru/achievements', $this->payload() + ['student_ulid' => $student->ulid])
            ->assertStatus(422);
        $this->assertSame(1, Achievement::where('student_id', $student->id)->count());
    }
}
