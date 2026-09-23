<?php

namespace Tests\Feature;

use App\Models\SchoolUnit;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Security\FieldEncrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The spec's staff contact column (audit C4, 03-ERD "phone (enc)"): one
 * phone field in the UI, two homes - the login identifier on users.phone,
 * the staff record's copy encrypted in staff_profiles with a blind index
 * for lookup. Parents never get a staff profile; their contact is the
 * guardian row's business.
 */
class StaffProfilePhoneTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role).uniqid(), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    public function test_creating_a_guru_stores_the_phone_encrypted_with_a_blind_index(): void
    {
        $sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $tu = $this->staff('admin_unit', $sd);

        $this->actingAs($tu)->postJson('/api/admin/users', [
            'name' => 'Ahmad Fauzi',
            'email' => 'ahmad.fauzi@alazhar.sch.id',
            'phone' => '081234567890',
            'role' => 'guru',
        ])->assertStatus(201);

        $user = User::where('email', 'ahmad.fauzi@alazhar.sch.id')->first();

        // Ciphertext in the database, never the plaintext.
        $stored = DB::table('staff_profiles')->where('user_id', $user->id)->first();
        $this->assertNotNull($stored);
        $this->assertNotSame('081234567890', $stored->phone);
        $this->assertNotSame('', $stored->phone);
        $this->assertSame(
            app(FieldEncrypter::class)->blindIndex('081234567890'),
            $stored->phone_hash,
        );

        // ...and plaintext back out through the model.
        $this->assertSame('081234567890', $user->staffProfile->phone);
    }

    public function test_parents_get_no_staff_profile_but_staff_roles_do(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Ibu Wali', 'phone' => '081111111111', 'role' => 'orangtua',
        ])->assertStatus(201);

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Tata Usaha', 'email' => 'tu'.uniqid().'@yapinet.id', 'role' => 'admin',
        ])->assertStatus(201);

        $this->assertSame(1, StaffProfile::count());
        $this->assertSame('admin', StaffProfile::sole()->user->role);
    }

    public function test_updating_the_shared_phone_field_resyncs_the_staff_record(): void
    {
        $admin = $this->staff('admin');
        $guru = User::create([
            'name' => 'Ahmad Fauzi', 'email' => 'ahmad@alazhar.sch.id', 'phone' => '081234567890',
            'role' => 'guru', 'is_active' => true, 'activated_at' => now(),
        ]);
        StaffProfile::mirrorUserPhone($guru);

        // An update that does not carry the phone key must not clobber the
        // mirror (the request is `sometimes`).
        $this->actingAs($admin)->patchJson("/api/admin/users/{$guru->ulid}", [
            'name' => 'Ahmad F. (S.Kom)',
        ])->assertOk();
        $this->assertSame('081234567890', $guru->fresh()->staffProfile->phone);

        // One that does, resyncs it.
        $this->actingAs($admin)->patchJson("/api/admin/users/{$guru->ulid}", [
            'phone' => '089876543210',
        ])->assertOk();

        $fresh = $guru->fresh()->staffProfile;
        $this->assertSame('089876543210', $fresh->phone);
        $this->assertSame(
            app(FieldEncrypter::class)->blindIndex('089876543210'),
            DB::table('staff_profiles')->where('user_id', $guru->id)->value('phone_hash'),
        );

        // Clearing it clears the hash too (the trait skips null writes).
        $this->actingAs($admin)->patchJson("/api/admin/users/{$guru->ulid}", [
            'phone' => null,
        ])->assertOk();

        $row = DB::table('staff_profiles')->where('user_id', $guru->id)->first();
        $this->assertNull($row->phone);
        $this->assertNull($row->phone_hash);
    }

    public function test_the_csv_import_mirrors_the_phone_for_staff_rows(): void
    {
        $sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $tu = $this->staff('admin_unit', $sd);

        $csv = "nama_lengkap,email,no_hp,role\n" .
            "Ahmad Fauzi,ahmad.csv@alazhar.sch.id,081234567801,guru\n" .
            "Hendra Gunawan,,081234567803,orangtua\n";

        $this->actingAs($tu)->postJson('/api/admin/import/users', [
            'file' => UploadedFile::fake()->createWithContent('akun.csv', $csv),
        ])->assertOk()->assertJsonPath('imported_count', 2);

        $guru = User::where('email', 'ahmad.csv@alazhar.sch.id')->first();
        $this->assertSame('081234567801', $guru->staffProfile->phone);

        // Exactly one staff profile - the parent row must not have one.
        $this->assertSame(1, StaffProfile::count());
    }

    public function test_the_blind_index_finds_a_staff_profile_by_phone(): void
    {
        $guru = User::create([
            'name' => 'Ahmad Fauzi', 'email' => 'cari@alazhar.sch.id', 'phone' => '081377788899',
            'role' => 'guru', 'is_active' => true, 'activated_at' => now(),
        ]);
        StaffProfile::mirrorUserPhone($guru);

        $found = StaffProfile::findByEncrypted('phone', '081377788899');

        $this->assertNotNull($found);
        $this->assertSame($guru->id, $found->user_id);
        $this->assertNull(StaffProfile::findByEncrypted('phone', '081000000000'));
    }
}
