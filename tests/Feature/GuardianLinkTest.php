<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Audit 6 Okt 2026 #9: admins manage guardian <-> student links. */
class GuardianLinkTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sd = SchoolUnit::create(['code' => 'SD-1', 'label' => 'SD Satu', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-1', 'label' => 'SMP Satu', 'jenjang_group' => 'smp']);
    }

    private function admin(?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'a'.uniqid().'@yapinet.id', 'role' => $unit ? 'admin_unit' : 'admin',
            'school_unit_id' => $unit?->id, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function student(SchoolUnit $unit): Student
    {
        return Student::create(['nama_lengkap' => 'Anak', 'jenis_kelamin' => 'L', 'school_unit_id' => $unit->id, 'status' => 'active']);
    }

    public function test_link_switch_billing_contact_and_unlink_hands_roles_over(): void
    {
        $student = $this->student($this->sd);
        $admin = $this->admin($this->sd);

        // An account made on the Users page: guardian row, no child yet.
        $ibu = Guardian::create(['nama' => 'Ibu Siti', 'hubungan' => 'ibu', 'no_hp' => '081211112222']);

        $this->actingAs($admin)->getJson('/api/admin/guardians/search?q=081211112222')
            ->assertOk()->assertJsonPath('guardians.0.ulid', $ibu->ulid);

        $this->actingAs($admin)->postJson("/api/admin/students/{$student->ulid}/guardians", [
            'guardian_ulid' => $ibu->ulid, 'relationship' => 'ibu',
        ])->assertCreated()->assertJsonPath('guardian.is_billing_contact', true)->assertJsonPath('guardian.is_primary', true);

        $ayah = $this->actingAs($admin)->postJson("/api/admin/students/{$student->ulid}/guardians", [
            'nama' => 'Ayah Budi', 'no_hp' => '0813-3333-4444', 'relationship' => 'ayah',
        ])->assertCreated()->assertJsonPath('guardian.is_billing_contact', false)->json('guardian.ulid');

        // Same phone again: refused, points to the existing contact.
        $this->actingAs($admin)->postJson("/api/admin/students/{$student->ulid}/guardians", [
            'nama' => 'Ayah Lain', 'no_hp' => '081333334444', 'relationship' => 'wali',
        ])->assertStatus(422);

        $this->actingAs($admin)->patchJson("/api/admin/students/{$student->ulid}/guardians/{$ayah}", ['is_billing_contact' => true])
            ->assertOk()->assertJsonPath('guardian.is_billing_contact', true);
        $this->assertSame(1, $student->guardians()->wherePivot('is_billing_contact', true)->count());

        $this->actingAs($admin)->deleteJson("/api/admin/students/{$student->ulid}/guardians/{$ayah}")->assertOk();

        $remaining = $student->fresh()->guardians()->get();
        $this->assertCount(1, $remaining);
        $this->assertTrue((bool) $remaining[0]->pivot->is_billing_contact);
    }

    public function test_unit_admin_cannot_reach_another_units_student_or_families(): void
    {
        $smpStudent = $this->student($this->smp);
        $smpParent = Guardian::create(['nama' => 'Keluarga SMP', 'hubungan' => 'ayah']);
        $smpStudent->guardians()->attach($smpParent->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $sdAdmin = $this->admin($this->sd);

        $this->actingAs($sdAdmin)->getJson("/api/admin/students/{$smpStudent->ulid}/guardians")->assertNotFound();
        $this->actingAs($sdAdmin)->getJson('/api/admin/guardians/search?q=Keluarga')->assertOk()->assertJsonCount(0, 'guardians');
        $this->actingAs($this->admin())->getJson('/api/admin/guardians/search?q=Keluarga')->assertOk()->assertJsonCount(1, 'guardians');
    }
}
