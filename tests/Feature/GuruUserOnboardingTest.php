<?php

namespace Tests\Feature;

use App\Models\SchoolUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * A per-unit admin onboarding their own unit's teachers - the manual form
 * and the CSV import. Three lines must hold no matter what the request or
 * the file says: only role guru can be created, the account always lands in
 * the admin's OWN unit (never a unit named in the payload/CSV), and an
 * email/number already owned by a non-guru account is reported, never
 * silently flipped. The central admin keeps the full four roles and routes
 * import rows by their unit_code column.
 */
class GuruUserOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;
    private SchoolUnit $smp;
    private User $pusat;
    private User $tuSd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);

        $this->pusat = User::create([
            'name' => 'Admin Pusat', 'email' => 'pusat'.uniqid().'@yapinet.id', 'role' => 'admin',
            'is_active' => true, 'activated_at' => now(),
        ]);

        $this->tuSd = User::create([
            'name' => 'TU SD', 'email' => 'tusd'.uniqid().'@yapinet.id', 'role' => 'admin_unit',
            'school_unit_id' => $this->sd->id, 'is_active' => true, 'activated_at' => now(),
        ]);
    }

    public function test_admin_unit_creates_a_guru_in_their_own_unit_even_when_another_is_named(): void
    {
        $res = $this->actingAs($this->tuSd)->postJson('/api/admin/users', [
            'name' => 'Guru Matematika',
            'email' => 'guru.mtk@yapinet.id',
            'role' => 'guru',
            // Points at SMP on purpose - the controller must force SD anyway.
            'school_unit_ulid' => $this->smp->ulid,
        ]);

        $res->assertCreated();
        $guru = User::where('email', 'guru.mtk@yapinet.id')->first();
        $this->assertSame('guru', $guru->role);
        $this->assertSame($this->sd->id, $guru->school_unit_id);
    }

    public function test_admin_unit_cannot_create_staff_roles(): void
    {
        foreach (['admin', 'admin_unit'] as $role) {
            $this->actingAs($this->tuSd)
                ->postJson('/api/admin/users', [
                    'name' => 'Coba', 'email' => "coba-{$role}@yapinet.id",
                    'role' => $role, 'school_unit_ulid' => $this->sd->ulid,
                ])
                ->assertStatus(422);
        }

        $this->assertDatabaseMissing('users', ['email' => 'coba-admin@yapinet.id']);
    }

    public function test_admin_unit_creates_an_orangtua_with_a_guardian_record(): void
    {
        $res = $this->actingAs($this->tuSd)->postJson('/api/admin/users', [
            'name' => 'Hendra Gunawan',
            'email' => 'hendra.wali@yapinet.id',
            'phone' => '081234567803',
            'role' => 'orangtua',
        ]);

        $res->assertCreated();
        $wali = User::where('email', 'hendra.wali@yapinet.id')->first();
        $this->assertSame('orangtua', $wali->role);
        $this->assertSame($this->sd->id, $wali->school_unit_id);

        // Without a guardian row the account could never be attached to a
        // student - the students CSV import matches wali by phone through it.
        $guardian = \App\Models\Guardian::where('user_id', $wali->id)->first();
        $this->assertNotNull($guardian);
    }

    public function test_admin_unit_imports_gurus_and_parents_only(): void
    {
        $csv = "nama_lengkap,email,no_hp,role\n" .
            "Ahmad Fauzi,ahmad.fauzi@alazhar.sch.id,081234567801,guru\n" .
            "Hendra Gunawan,,6281234567803,orangtua\n" .
            "Palsu Supervisor,,081234567804,admin\n";

        $res = $this->actingAs($this->tuSd)->postJson('/api/admin/import/users', [
            'file' => UploadedFile::fake()->createWithContent('akun.csv', $csv),
        ]);

        $res->assertOk()->assertJsonPath('imported_count', 2);
        $this->assertNotNull($res->json('errors.0'), 'role admin harus ditolak utk admin unit');

        $hendra = User::findByEncrypted('phone', '081234567803');
        $this->assertNotNull($hendra);
        $this->assertSame('orangtua', $hendra->role);
        $this->assertSame($this->sd->id, $hendra->school_unit_id);
        $this->assertNotNull(\App\Models\Guardian::where('user_id', $hendra->id)->first(), 'orangtua impor harus lahir bersama guardian');

        $this->assertDatabaseMissing('users', ['email' => 'ahmad.fauzi@alazhar.sch.id', 'role' => 'admin']);
    }

    public function test_admin_unit_import_lands_every_row_in_their_own_unit(): void
    {
        // unit_code says smp on both rows - must be ignored: the importer's
        // own unit wins. The 62-format number must come back as 08xx, the
        // form OTP login hashes before lookup.
        $csv = "nama_lengkap,email,no_hp,unit_code\n" .
            "Ahmad Fauzi,ahmad.fauzi@alazhar.sch.id,6281234567801,smp\n" .
            "Siti Rahmawati,,81234567802,smp\n";

        $res = $this->actingAs($this->tuSd)->postJson('/api/admin/import/users', [
            'file' => UploadedFile::fake()->createWithContent('guru.csv', $csv),
        ]);

        $res->assertOk()->assertJsonPath('imported_count', 2)->assertJsonPath('updated_count', 0);

        $ahmad = User::where('email', 'ahmad.fauzi@alazhar.sch.id')->first();
        $this->assertSame($this->sd->id, $ahmad->school_unit_id);
        $this->assertSame('guru', $ahmad->role);

        $siti = User::findByEncrypted('phone', '081234567802');
        $this->assertNotNull($siti, 'nomor 81234567802 harus tersimpan/ditemukan dalam format 08xx');
        $this->assertSame($this->sd->id, $siti->school_unit_id);
    }

    public function test_reimport_updates_without_duplicates_and_refuses_foreign_accounts(): void
    {
        // An email owned by an orangtua and a guru of another unit - both must
        // be reported as errors, never converted nor taken over.
        $wali = User::create([
            'name' => 'Wali Murid', 'email' => 'wali@yapinet.id', 'role' => 'orangtua',
            'is_active' => true,
        ]);
        $guruSmp = User::create([
            'name' => 'Guru SMP', 'email' => 'guru.smp@yapinet.id', 'role' => 'guru',
            'school_unit_id' => $this->smp->id, 'is_active' => true,
        ]);

        $csv = "nama_lengkap,email,no_hp,unit_code\n" .
            "Ahmad Fauzi,ahmad.fauzi@alazhar.sch.id,081234567801,sd\n" .
            "Wali Murid,wali@yapinet.id,,sd\n" .
            "Guru SMP,guru.smp@yapinet.id,,sd\n";
        $file = UploadedFile::fake()->createWithContent('guru.csv', $csv);

        $this->actingAs($this->tuSd)->postJson('/api/admin/import/users', ['file' => $file])
            ->assertOk()->assertJsonPath('imported_count', 1);

        $this->assertSame('orangtua', $wali->fresh()->role, 'akun wali tidak boleh berubah jadi guru');
        $this->assertSame($this->smp->id, $guruSmp->fresh()->school_unit_id, 'guru unit lain tidak boleh diambil alih');

        // Same file again: Ahmad is found by email and refreshed, not doubled.
        $file2 = UploadedFile::fake()->createWithContent('guru.csv', $csv);
        $this->actingAs($this->tuSd)->postJson('/api/admin/import/users', ['file' => $file2])
            ->assertOk()->assertJsonPath('imported_count', 0)->assertJsonPath('updated_count', 1);

        $this->assertSame(1, User::where('email', 'ahmad.fauzi@alazhar.sch.id')->count());
    }

    public function test_central_admin_import_routes_rows_by_the_unit_column(): void
    {
        $csv = "nama_lengkap,email,no_hp,unit_code\n" .
            "Ahmad Fauzi,ahmad.fauzi@alazhar.sch.id,,smp\n" .
            "Budi Hartono,budi.hartono@alazhar.sch.id,,sd\n" .
            "Salah Unit,salah@yapinet.id,,unit-tidak-ada\n";

        $res = $this->actingAs($this->pusat)->postJson('/api/admin/import/users', [
            'file' => UploadedFile::fake()->createWithContent('guru.csv', $csv),
        ]);

        $res->assertOk()->assertJsonPath('imported_count', 2);
        $this->assertSame($this->smp->id, User::where('email', 'ahmad.fauzi@alazhar.sch.id')->first()->school_unit_id);
        $this->assertSame($this->sd->id, User::where('email', 'budi.hartono@alazhar.sch.id')->first()->school_unit_id);
        $this->assertNotNull($res->json('errors.0'));
    }

    public function test_central_admin_import_rejects_an_ambiguous_unit_cell(): void
    {
        // "smp" fits both SMP campuses now that a second one exists - the
        // row must be reported naming them, never parked silently on
        // whichever unit the collection happened to return first.
        $smpLain = SchoolUnit::create(['code' => 'SMP-55', 'label' => 'SMP Islam Al Azhar 55 Jatimakmur', 'jenjang_group' => 'smp']);

        $csv = "nama_lengkap,email,no_hp,role,unit_code\n" .
            "Guru Nyasal,nyasal@alazhar.sch.id,,guru,smp\n";

        $res = $this->actingAs($this->pusat)->postJson('/api/admin/import/users', [
            'file' => UploadedFile::fake()->createWithContent('guru.csv', $csv),
        ]);

        $res->assertOk()->assertJsonPath('imported_count', 0);

        $error = $res->json('errors.0');
        $this->assertStringContainsString('cocok ke beberapa unit', $error);
        $this->assertStringContainsString('SMP-SAKINAH', $error);
        $this->assertStringContainsString('SMP-55', $error);

        $this->assertNull(User::where('email', 'nyasal@alazhar.sch.id')->first());
    }

    public function test_central_admin_imports_staff_roles_from_the_role_column(): void
    {
        $csv = "nama_lengkap,email,no_hp,role,unit_code,is_aktif\n" .
            "Yusuf Hanafi,yusuf@yapinet.id,,admin,,\n" .
            "Rina Amalia,rina.amalia@alazhar.sch.id,,tata_usaha,sd,1\n" .
            "Wali Aisyah,wali.aisyah@gmail.com,,orangtua,,0\n" .
            "Supervisor Salah,salah@yapinet.id,,supervisor,sd,1\n" .
            "TU Tanpa Unit,tanpa.unit@yapinet.id,,admin_unit,,1\n";

        $res = $this->actingAs($this->pusat)->postJson('/api/admin/import/users', [
            'file' => UploadedFile::fake()->createWithContent('users.csv', $csv),
        ]);

        $res->assertOk()->assertJsonPath('imported_count', 3);
        $this->assertSame(2, count($res->json('errors')), 'role tak dikenal + role unit tanpa unit_code');

        $this->assertNull(User::where('email', 'yusuf@yapinet.id')->first()->school_unit_id);
        $this->assertSame('admin_unit', User::where('email', 'rina.amalia@alazhar.sch.id')->first()->role);
        $this->assertSame($this->sd->id, User::where('email', 'rina.amalia@alazhar.sch.id')->first()->school_unit_id);
        $this->assertFalse((bool) User::where('email', 'wali.aisyah@gmail.com')->first()->is_active, 'is_aktif=0 harus nonaktif');
    }

    public function test_admin_unit_user_list_shows_own_units_gurus_and_parents(): void
    {
        $guruSd = User::create([
            'name' => 'Guru SD', 'email' => 'gurusd'.uniqid().'@yapinet.id', 'role' => 'guru',
            'school_unit_id' => $this->sd->id, 'is_active' => true,
        ]);
        User::create([
            'name' => 'Guru SMP', 'email' => 'gurusmp'.uniqid().'@yapinet.id', 'role' => 'guru',
            'school_unit_id' => $this->smp->id, 'is_active' => true,
        ]);

        // An imported parent carries the unit on the user row...
        $waliImpor = User::create([
            'name' => 'Wali Impor', 'email' => 'waliimpor'.uniqid().'@yapinet.id', 'role' => 'orangtua',
            'school_unit_id' => $this->sd->id, 'is_active' => true,
        ]);
        // ...a PMB parent does not - it reaches the unit's list through its
        // children's enrollment instead.
        $waliPmb = User::create([
            'name' => 'Wali PMB', 'email' => 'walipmb'.uniqid().'@yapinet.id', 'role' => 'orangtua',
            'is_active' => true,
        ]);
        $guardian = \App\Models\Guardian::create([
            'user_id' => $waliPmb->id, 'nama' => 'Wali PMB', 'hubungan' => 'ayah',
        ]);
        $siswaSd = \App\Models\Student::create([
            'school_unit_id' => $this->sd->id,
            'entry_year_id' => \App\Models\AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'])->id,
            'nama_lengkap' => 'Anak SD', 'jenis_kelamin' => 'L', 'status' => 'active',
        ]);
        $guardian->students()->attach($siswaSd->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $res = $this->actingAs($this->tuSd)->getJson('/api/admin/users?per_page=50')->assertOk();

        $emails = collect($res->json('users.data'))->pluck('email');
        $this->assertTrue($emails->contains($guruSd->email));
        $this->assertTrue($emails->contains($waliImpor->email));
        $this->assertTrue($emails->contains($waliPmb->email), 'wali PMB unit ini terlihat lewat siswanya');
        $this->assertSame(3, $emails->count(), 'guru unit lain & akun staf tidak boleh muncul');
    }

    public function test_templates_match_their_caller(): void
    {
        // The per-unit admin's template carries the role choice (guru /
        // orangtua) but no unit column - their import lands every row in
        // their own unit anyway.
        $unitRes = $this->actingAs($this->tuSd)->get('/api/admin/import/users/template');
        $unitRes->assertOk();
        $unitContent = $unitRes->streamedContent();
        $this->assertStringContainsString('nama_lengkap', $unitContent);
        $this->assertStringContainsString('role', $unitContent);
        $this->assertStringNotContainsString('unit_code', $unitContent);

        // The central admin's template cites REAL unit labels from the
        // database (the same names the Tambah Pengguna dropdown shows), not
        // made-up codes like "sd"/"smp" that miss RA/TK.
        $pusatRes = $this->actingAs($this->pusat)->get('/api/admin/import/users/template');
        $pusatRes->assertOk();
        $pusatContent = $pusatRes->streamedContent();
        $this->assertStringContainsString('SD Sakinah', $pusatContent);
        $this->assertStringContainsString('role', $pusatContent);
        $this->assertStringContainsString('is_aktif', $pusatContent);
    }
}
