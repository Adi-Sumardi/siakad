<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\PointRecord;
use App\Models\PointRule;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Points\PointLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The ledger itself: every record is signed, permanent, and traces to a
 * catalogue rule; a correction is a revoke with a reason, never an edit or a
 * delete (docs/01-ARSITEKTUR.md D6). And the boundary around who may touch
 * whose students holds here exactly as it does for bills.
 */
class PointLedgerTest extends TestCase
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

    private function studentIn(SchoolUnit $unit, string $name = 'Aisyah Nur Ramadhani'): Student
    {
        return Student::create([
            'nama_lengkap' => $name, 'jenis_kelamin' => 'P',
            'school_unit_id' => $unit->id, 'status' => 'active',
        ]);
    }

    private function staff(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function rule(array $overrides = []): PointRule
    {
        return PointRule::create(array_merge([
            'code' => 'TL-'.uniqid(), 'name' => 'Terlambat masuk kelas',
            'type' => 'violation', 'category' => 'Kedisiplinan', 'points' => 10,
        ], $overrides));
    }

    private function ledger(): PointLedger
    {
        return app(PointLedger::class);
    }

    public function test_recording_a_violation_writes_a_signed_negative_row(): void
    {
        $student = $this->studentIn($this->sd);
        $rule = $this->rule(['points' => 10, 'type' => 'violation']);
        $guru = $this->staff('guru', $this->sd);

        $record = $this->ledger()->record($student, $this->term, $rule, $guru, now(), 'Terlambat 10 menit');

        $this->assertSame(-10, $record->points);
        $this->assertSame('recorded', $record->status);
        $this->assertSame(-10, $this->ledger()->balance($student, $this->term));
    }

    public function test_recording_a_merit_writes_a_signed_positive_row(): void
    {
        $student = $this->studentIn($this->sd);
        $rule = $this->rule(['points' => 15, 'type' => 'merit', 'category' => 'Penghargaan']);
        $guru = $this->staff('guru', $this->sd);

        $record = $this->ledger()->record($student, $this->term, $rule, $guru, now(), 'Membantu piket');

        $this->assertSame(15, $record->points);
        $this->assertSame(15, $this->ledger()->balance($student, $this->term));
    }

    public function test_balance_is_the_sum_of_every_active_row_this_term(): void
    {
        $student = $this->studentIn($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $violation = $this->rule(['points' => 10, 'type' => 'violation']);
        $merit = $this->rule(['points' => 15, 'type' => 'merit']);

        $this->ledger()->record($student, $this->term, $violation, $guru, now(), 'A');
        $this->ledger()->record($student, $this->term, $violation, $guru, now(), 'B');
        $this->ledger()->record($student, $this->term, $merit, $guru, now(), 'C');

        // -10 -10 +15 = -5, not a stored column anywhere - recomputed here.
        $this->assertSame(-5, $this->ledger()->balance($student, $this->term));
    }

    public function test_a_rule_requiring_evidence_is_refused_without_it(): void
    {
        $student = $this->studentIn($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $rule = $this->rule(['requires_evidence' => true]);

        $this->expectException(\RuntimeException::class);
        $this->ledger()->record($student, $this->term, $rule, $guru, now(), 'Tanpa bukti');
    }

    public function test_bulk_recording_applies_one_rule_to_every_student_given(): void
    {
        $guru = $this->staff('guru', $this->sd);
        $rule = $this->rule(['points' => 5, 'type' => 'violation']);
        $students = collect([$this->studentIn($this->sd, 'A'), $this->studentIn($this->sd, 'B'), $this->studentIn($this->sd, 'C')]);

        $records = $this->ledger()->recordBulk($students, $this->term, $rule, $guru, now(), 'Terlambat upacara');

        $this->assertCount(3, $records);
        $students->each(fn ($s) => $this->assertSame(-5, $this->ledger()->balance($s, $this->term)));
    }

    public function test_revoking_removes_a_row_from_the_balance_but_keeps_it_on_file(): void
    {
        $student = $this->studentIn($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $rule = $this->rule(['points' => 10, 'type' => 'violation']);

        $record = $this->ledger()->record($student, $this->term, $rule, $guru, now(), 'Salah catat');
        $this->ledger()->revoke($record, $guru, 'Salah input, bukan siswa ini');

        $this->assertSame(0, $this->ledger()->balance($student, $this->term));

        $record->refresh();
        $this->assertSame('revoked', $record->status);
        $this->assertSame(-10, $record->points); // untouched - the audit trail is the point
        $this->assertSame('Salah input, bukan siswa ini', $record->revoke_reason);
        $this->assertNotNull($record->revoked_at);
    }

    public function test_a_record_cannot_be_revoked_twice(): void
    {
        $student = $this->studentIn($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $record = $this->ledger()->record($student, $this->term, $this->rule(), $guru, now(), 'X');

        $this->ledger()->revoke($record, $guru, 'Alasan pertama');

        $this->expectException(\RuntimeException::class);
        $this->ledger()->revoke($record->fresh(), $guru, 'Alasan kedua');
    }

    // ---- HTTP surface: role gate + row-level scope ----

    private function guardianFor(Student $student): User
    {
        $user = User::create([
            'name' => 'Budi Ramadhani', 'email' => 'budi'.uniqid().'@example.com',
            'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
        ]);
        $guardian = Guardian::create(['user_id' => $user->id, 'nama' => 'Budi Ramadhani', 'hubungan' => 'ayah', 'email' => $user->email]);
        $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        return $user;
    }

    public function test_a_guru_can_record_a_point_via_the_api(): void
    {
        $student = $this->studentIn($this->sd);
        $rule = $this->rule();
        $guru = $this->staff('guru', $this->sd);

        $this->actingAs($guru)->postJson('/api/guru/points', [
            'student_ulid' => $student->ulid,
            'point_rule_ulid' => $rule->ulid,
            'occurred_on' => now()->toDateString(),
            'description' => 'Terlambat 10 menit',
        ])->assertStatus(201)->assertJsonPath('record.points', -10);

        $this->assertDatabaseCount('point_records', 1);
    }

    /**
     * config('app.timezone') is UTC, not the Asia/Jakarta .env sets it to -
     * Laravel's bare 'today' validation keyword resolves against it, so
     * before_or_equal:today used to reject a genuinely same-day entry as "in
     * the future" for the seven hours every morning (00:00-06:59 WIB) that
     * still fall on UTC's previous calendar day.
     */
    public function test_recording_a_point_for_the_jakarta_today_is_accepted_even_when_utc_still_says_yesterday(): void
    {
        // 2026-09-07 20:00 UTC = 2026-09-08 03:00 WIB.
        Carbon::setTestNow(Carbon::create(2026, 9, 7, 20, 0, 0, 'UTC'));

        try {
            $student = $this->studentIn($this->sd);
            $rule = $this->rule();
            $guru = $this->staff('guru', $this->sd);

            $this->actingAs($guru)->postJson('/api/guru/points', [
                'student_ulid' => $student->ulid,
                'point_rule_ulid' => $rule->ulid,
                'occurred_on' => '2026-09-08', // Jakarta's actual today
                'description' => 'Terlambat 10 menit',
            ])->assertStatus(201);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_guru_cannot_record_points_for_a_student_outside_their_unit(): void
    {
        $student = $this->studentIn($this->smp);
        $rule = $this->rule();
        $guru = $this->staff('guru', $this->sd);

        // 404, not 403: Student::visibleTo() simply does not return this row
        // for a teacher in a different unit.
        $this->actingAs($guru)->postJson('/api/guru/points', [
            'student_ulid' => $student->ulid,
            'point_rule_ulid' => $rule->ulid,
            'occurred_on' => now()->toDateString(),
            'description' => 'X',
        ])->assertStatus(404);
    }

    public function test_a_guardian_cannot_reach_the_guru_points_endpoint_at_all(): void
    {
        $parent = User::create([
            'name' => 'Wali', 'email' => 'wali@example.com', 'role' => 'orangtua',
            'is_active' => true, 'activated_at' => now(),
        ]);

        $this->actingAs($parent)->postJson('/api/guru/points', [])->assertStatus(403);
    }

    public function test_a_guru_revokes_through_the_api_with_a_required_reason(): void
    {
        $student = $this->studentIn($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $record = $this->ledger()->record($student, $this->term, $this->rule(), $guru, now(), 'X');

        $this->actingAs($guru)->patchJson("/api/guru/points/{$record->ulid}/revoke", [])
            ->assertStatus(422);

        $this->actingAs($guru)->patchJson("/api/guru/points/{$record->ulid}/revoke", ['reason' => 'Keliru'])
            ->assertOk()->assertJsonPath('record.status', 'revoked');
    }

    public function test_the_wali_endpoint_returns_balance_and_the_matching_threshold(): void
    {
        $student = $this->studentIn($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $user = $this->guardianFor($student);

        \App\Models\PointThreshold::create([
            'school_unit_id' => null, 'min_points' => -999, 'max_points' => -1,
            'label' => 'Peringatan 1', 'notify_guardian' => true,
        ]);

        $this->ledger()->record($student, $this->term, $this->rule(['points' => 10]), $guru, now(), 'X');

        $this->actingAs($user)->getJson("/api/wali/students/{$student->ulid}/points")
            ->assertOk()
            ->assertJsonPath('balance', -10)
            ->assertJsonPath('threshold.label', 'Peringatan 1')
            ->assertJsonCount(1, 'records');
    }

    public function test_a_guru_can_read_a_students_ledger_to_decide_whether_to_revoke(): void
    {
        $student = $this->studentIn($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $this->ledger()->record($student, $this->term, $this->rule(['points' => 10]), $guru, now(), 'Terlambat');

        $this->actingAs($guru)->getJson("/api/guru/students/{$student->ulid}/points")
            ->assertOk()
            ->assertJsonPath('balance', -10)
            ->assertJsonCount(1, 'records');
    }

    public function test_a_guru_cannot_read_a_students_ledger_outside_their_unit(): void
    {
        $student = $this->studentIn($this->smp);
        $guru = $this->staff('guru', $this->sd);

        $this->actingAs($guru)->getJson("/api/guru/students/{$student->ulid}/points")
            ->assertStatus(404);
    }

    public function test_a_guardian_cannot_read_another_familys_points(): void
    {
        $mine = $this->studentIn($this->sd, 'Anak saya');
        $theirs = $this->studentIn($this->sd, 'Anak orang lain');
        $user = $this->guardianFor($mine);
        $this->guardianFor($theirs);

        $this->actingAs($user)->getJson("/api/wali/students/{$theirs->ulid}/points")->assertStatus(404);
    }

    // ---- catalogue scope ----

    public function test_a_school_wide_rule_is_usable_by_every_unit(): void
    {
        $schoolWide = $this->rule(['school_unit_id' => null]);
        $onlySd = $this->rule(['school_unit_id' => $this->sd->id]);

        $forSd = PointRule::forUnit($this->sd->id)->pluck('id');
        $forSmp = PointRule::forUnit($this->smp->id)->pluck('id');

        $this->assertTrue($forSd->contains($schoolWide->id));
        $this->assertTrue($forSd->contains($onlySd->id));
        $this->assertTrue($forSmp->contains($schoolWide->id));
        $this->assertFalse($forSmp->contains($onlySd->id));
    }

    public function test_a_unit_admin_cannot_create_a_school_wide_point_rule(): void
    {
        $admin = $this->staff('admin_unit', $this->sd);

        $this->actingAs($admin)->postJson('/api/admin/point-rules', [
            'code' => 'X1', 'name' => 'Test', 'type' => 'violation', 'category' => 'Kedisiplinan', 'points' => 5,
        ])->assertStatus(201);

        $rule = PointRule::where('code', 'X1')->first();
        $this->assertSame($this->sd->id, $rule->school_unit_id);
    }

    public function test_a_central_admin_can_create_a_school_wide_point_rule(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->postJson('/api/admin/point-rules', [
            'code' => 'X2', 'name' => 'Test', 'type' => 'violation', 'category' => 'Kedisiplinan', 'points' => 5,
        ])->assertStatus(201);

        $this->assertNull(PointRule::where('code', 'X2')->first()->school_unit_id);
    }

    public function test_a_unit_admin_cannot_edit_another_units_rule(): void
    {
        $rule = $this->rule(['school_unit_id' => $this->smp->id]);
        $admin = $this->staff('admin_unit', $this->sd);

        $this->actingAs($admin)->patchJson("/api/admin/point-rules/{$rule->ulid}", ['points' => 99])
            ->assertStatus(404);
    }

    public function test_a_rule_already_applied_cannot_be_deleted(): void
    {
        $student = $this->studentIn($this->sd);
        $guru = $this->staff('guru', $this->sd);
        $rule = $this->rule();
        $this->ledger()->record($student, $this->term, $rule, $guru, now(), 'X');

        $admin = $this->staff('admin');

        $this->actingAs($admin)->deleteJson("/api/admin/point-rules/{$rule->ulid}")
            ->assertStatus(422);

        $this->assertDatabaseHas('point_rules', ['id' => $rule->id]);
    }
}
