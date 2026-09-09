<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminImportAndAcademicYearTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private SchoolUnit $unit;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = SchoolUnit::create([
            'code' => 'sd',
            'label' => 'SD Islam Al Azhar 13',
            'jenjang_group' => 'sd',
        ]);

        $this->year = AcademicYear::create([
            'year' => '2026/2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@yapinet.id',
            'phone' => '081234567890',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_can_create_and_activate_academic_year(): void
    {
        $createRes = $this->actingAs($this->admin)->postJson('/api/admin/academic-years', [
            'year' => '2029/2030',
            'starts_on' => '2029-07-01',
            'ends_on' => '2030-06-30',
            'is_active' => true,
        ]);

        $createRes->assertCreated();
        $this->assertDatabaseHas('academic_years', [
            'year' => '2029/2030',
            'is_active' => true,
        ]);

        // Previous year 2026/2027 should now be false
        $this->assertFalse($this->year->fresh()->is_active);
    }

    public function test_can_import_students_from_csv(): void
    {
        $this->withoutExceptionHandling();
        $csvContent = "nama_lengkap,nis,nisn,jenis_kelamin,unit_code,kelas,wali_nama,wali_phone,wali_email,status\n" .
            "Muhammad Farhan,27001,0012345678,L,sd,1-A,Ahmad Syahid,081299887766,ahmad@gmail.com,active\n" .
            "Fatimah Az Zahra,27002,0012345679,P,sd,1-A,Umar Abdullah,081399887766,umar@gmail.com,active\n";

        $file = UploadedFile::fake()->createWithContent('students.csv', $csvContent);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/import/students', [
            'file' => $file,
        ]);

        if ($response->status() !== 200) {
            dump($response->json());
        }

        $response->assertOk()
            ->assertJsonPath('imported_count', 2);

        $this->assertDatabaseHas('students', [
            'nama_lengkap' => 'Muhammad Farhan',
            'nis' => '27001',
        ]);

        $this->assertDatabaseHas('students', [
            'nama_lengkap' => 'Fatimah Az Zahra',
            'nis' => '27002',
        ]);

        $this->assertDatabaseHas('classrooms', [
            'name' => '1-A',
            'tingkat' => 1,
        ]);

        $this->assertDatabaseHas('guardians', [
            'nama' => 'Ahmad Syahid',
        ]);
    }

    public function test_reimporting_the_same_csv_does_not_duplicate_the_phone_matched_guardian(): void
    {
        // phone is an encrypted column; a naive where('phone', ...) can never
        // match its own ciphertext, so without findByEncrypted() this row
        // would mint a brand new guardian/user pair on every re-import.
        $csvContent = "nama_lengkap,nis,jenis_kelamin,unit_code,wali_nama,wali_phone\n" .
            "Muhammad Farhan,27001,L,sd,Ahmad Syahid,081299887766\n";

        $file1 = UploadedFile::fake()->createWithContent('students.csv', $csvContent);
        $this->actingAs($this->admin)->postJson('/api/admin/import/students', ['file' => $file1])->assertOk();

        $file2 = UploadedFile::fake()->createWithContent('students.csv', $csvContent);
        $res = $this->actingAs($this->admin)->postJson('/api/admin/import/students', ['file' => $file2]);

        $res->assertOk()->assertJsonPath('updated_count', 1)->assertJsonPath('imported_count', 0);
        $this->assertSame(1, \App\Models\Guardian::where('nama', 'Ahmad Syahid')->count());
        $this->assertSame(1, User::where('role', 'orangtua')->count());
    }

    public function test_a_name_only_guardian_row_does_not_reuse_an_unrelated_orphan_guardian(): void
    {
        // An "orphan" guardian - no linked user account - already exists,
        // e.g. a secondary contact who was never invited.
        $orphan = \App\Models\Guardian::create(['nama' => 'Kontak Lama Tidak Terkait', 'hubungan' => 'wali']);

        $csvContent = "nama_lengkap,nis,jenis_kelamin,unit_code,wali_nama\n" .
            "Siswa Baru,27099,L,sd,Wali Tanpa Kontak\n";
        $file = UploadedFile::fake()->createWithContent('students.csv', $csvContent);

        $this->actingAs($this->admin)->postJson('/api/admin/import/students', ['file' => $file])->assertOk();

        $student = Student::where('nis', '27099')->firstOrFail();
        $attachedGuardian = $student->guardians()->first();

        $this->assertNotNull($attachedGuardian);
        $this->assertNotEquals($orphan->id, $attachedGuardian->id, 'Must not reuse an unrelated orphan guardian.');
        $this->assertSame('Wali Tanpa Kontak', $attachedGuardian->nama);
    }

    public function test_reimporting_a_student_with_a_different_guardian_does_not_leave_two_primaries(): void
    {
        // Elsewhere (PmbHandoffProcessor, BillReminderSender,
        // PointThresholdNotifier) the app assumes exactly one primary/billing
        // guardian per student and just picks firstWhere('pivot.is_primary').
        // A CSV only has one wali column, so this only shows up across two
        // imports of the same student naming a different wali - correcting a
        // typo, or filing the father then the mother in separate uploads.
        $first = "nama_lengkap,nis,jenis_kelamin,unit_code,wali_nama,wali_phone\n" .
            "Anak Ganda,27050,L,sd,Ayah Pertama,081200000001\n";
        $this->actingAs($this->admin)->postJson('/api/admin/import/students', [
            'file' => UploadedFile::fake()->createWithContent('s1.csv', $first),
        ])->assertOk();

        $second = "nama_lengkap,nis,jenis_kelamin,unit_code,wali_nama,wali_phone\n" .
            "Anak Ganda,27050,L,sd,Ibu Kedua,081200000002\n";
        $this->actingAs($this->admin)->postJson('/api/admin/import/students', [
            'file' => UploadedFile::fake()->createWithContent('s2.csv', $second),
        ])->assertOk();

        $student = Student::where('nis', '27050')->firstOrFail();
        $primaryGuardians = $student->guardians()->wherePivot('is_primary', true)->get();
        $billingContacts = $student->guardians()->wherePivot('is_billing_contact', true)->get();

        $this->assertCount(1, $primaryGuardians, 'Exactly one guardian must stay marked primary.');
        $this->assertCount(1, $billingContacts, 'Exactly one guardian must stay the billing contact.');
        $this->assertSame('Ibu Kedua', $primaryGuardians->first()->nama, 'The most recently imported row should win.');
    }

    public function test_indonesian_formatted_amounts_are_not_read_as_a_thousandfold_undercharge(): void
    {
        // "650.000" is how a Rupiah amount is normally written/pasted from a
        // spreadsheet - a period grouping thousands, not a decimal point.
        // Stripping only non-digit characters would keep the period and
        // read this as 650.0 rupiah.
        $csvContent = "fee_type_code,unit_code,tingkat,academic_year,amount,due_day,late_fee_amount\n" .
            "spp,sd,1,2027/2028,650.000,10,25.000\n";

        $file = UploadedFile::fake()->createWithContent('tariffs.csv', $csvContent);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/import/fee-rates', ['file' => $file]);

        $response->assertOk()->assertJsonPath('imported_count', 1);
        $this->assertDatabaseHas('fee_rates', [
            'amount' => 650000,
            'late_fee_amount' => 25000,
        ]);
    }

    public function test_can_import_fee_rates_from_csv(): void
    {
        $csvContent = "fee_type_code,unit_code,tingkat,academic_year,amount,due_day,late_fee_amount\n" .
            "spp,sd,1,2027/2028,650000,10,0\n" .
            "spp,sd,2,2027/2028,650000,10,0\n";

        $file = UploadedFile::fake()->createWithContent('tariffs.csv', $csvContent);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/import/fee-rates', [
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertJsonPath('imported_count', 2);

        $this->assertDatabaseHas('fee_rates', [
            'amount' => 650000,
            'tingkat' => 1,
        ]);
    }

    public function test_can_download_templates(): void
    {
        $this->actingAs($this->admin)->get('/api/admin/import/students/template')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="template_import_siswa_siakad.csv"');

        $this->actingAs($this->admin)->get('/api/admin/import/fee-rates/template')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="template_import_tarif_spp.csv"');
    }

    public function test_an_ambiguous_jenjang_shorthand_is_an_error_row_naming_both_campuses(): void
    {
        // Two SMP campuses on the roll is the situation the old first-match
        // behaviour silently misrouted: every "smp" row landed on whichever
        // unit the collection happened to return first. It must now name
        // both candidates and import nothing, rather than guess.
        SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMPI Al Azhar 12 Rawamangun', 'jenjang_group' => 'smp']);
        SchoolUnit::create(['code' => 'SMP-55', 'label' => 'SMPI Al Azhar 55 Jatimakmur', 'jenjang_group' => 'smp']);

        $csvContent = "nama_lengkap,nis,jenis_kelamin,unit_code\n" .
            "Siswa Nyasal,27010,L,smp\n";

        $response = $this->actingAs($this->admin)->postJson('/api/admin/import/students', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csvContent),
        ]);

        $response->assertOk()->assertJsonPath('imported_count', 0);

        $errors = $response->json('errors');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('cocok ke beberapa unit', $errors[0]);
        $this->assertStringContainsString('SMP-12', $errors[0]);
        $this->assertStringContainsString('SMP-55', $errors[0]);

        $this->assertSame(0, Student::count());
    }

    public function test_a_substring_that_names_one_campus_still_resolves(): void
    {
        SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMPI Al Azhar 12 Rawamangun', 'jenjang_group' => 'smp']);
        SchoolUnit::create(['code' => 'SMP-55', 'label' => 'SMPI Al Azhar 55 Jatimakmur', 'jenjang_group' => 'smp']);

        // "jatimakmur" fits exactly one label, so the hand-typed-file
        // convenience survives - but only ever by pointing at ONE campus.
        $csvContent = "nama_lengkap,nis,jenis_kelamin,unit_code\n" .
            "Siswa Jatimakmur,27011,L,jatimakmur\n";

        $response = $this->actingAs($this->admin)->postJson('/api/admin/import/students', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csvContent),
        ]);

        $response->assertOk()->assertJsonPath('imported_count', 1);
        $this->assertSame('SMP-55', Student::first()->schoolUnit->code);
    }

    public function test_a_blank_unit_cell_is_reported_as_missing(): void
    {
        $csvContent = "nama_lengkap,nis,jenis_kelamin,unit_code\n" .
            "Siswa Tanpa Unit,27012,L,\n";

        $response = $this->actingAs($this->admin)->postJson('/api/admin/import/students', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csvContent),
        ]);

        $response->assertOk()->assertJsonPath('imported_count', 0);
        $this->assertStringContainsString('kosong', $response->json('errors.0'));
    }

    public function test_an_ambiguous_unit_cell_in_a_fee_rate_row_is_an_error(): void
    {
        SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMPI Al Azhar 12 Rawamangun', 'jenjang_group' => 'smp']);
        SchoolUnit::create(['code' => 'SMP-55', 'label' => 'SMPI Al Azhar 55 Jatimakmur', 'jenjang_group' => 'smp']);

        // A rate resolving to the wrong campus would misprice that school's
        // bills, so the same one-campus rule applies to tarif rows.
        $csvContent = "fee_type_code,unit_code,tingkat,academic_year,amount,due_day,late_fee_amount\n" .
            "spp,smp,,2027/2028,750000,10,0\n";

        $response = $this->actingAs($this->admin)->postJson('/api/admin/import/fee-rates', [
            'file' => UploadedFile::fake()->createWithContent('tariffs.csv', $csvContent),
        ]);

        $response->assertOk()->assertJsonPath('imported_count', 0);
        $this->assertStringContainsString('SMP-55', $response->json('errors.0'));
        $this->assertSame(0, FeeRate::count());
    }

    public function test_the_student_template_cites_real_unit_codes_from_the_database(): void
    {
        // The template must never teach a jenjang shorthand: with two SMP
        // campuses "smp" is precisely the value the matcher rejects, and the
        // samples have to come from the live unit master at download time.
        SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMPI Al Azhar 12 Rawamangun', 'jenjang_group' => 'smp', 'is_active' => true]);
        SchoolUnit::create(['code' => 'SMP-55', 'label' => 'SMPI Al Azhar 55 Jatimakmur', 'jenjang_group' => 'smp', 'is_active' => true]);

        $content = $this->actingAs($this->admin)
            ->get('/api/admin/import/students/template')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('unit_code', $content);
        // Sample rows carry real codes - deliberately a same-jenjang pair.
        $this->assertStringContainsString('SMP-12', $content);
        $this->assertStringContainsString('SMP-55', $content);
    }

    public function test_the_fee_rate_template_cites_real_unit_codes_from_the_database(): void
    {
        SchoolUnit::create(['code' => 'SMP-55', 'label' => 'SMPI Al Azhar 55 Jatimakmur', 'jenjang_group' => 'smp', 'is_active' => true]);

        $content = $this->actingAs($this->admin)
            ->get('/api/admin/import/fee-rates/template')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('SMP-55', $content);
    }

    private function unitAdmin(): User
    {
        return User::create([
            'name' => 'Admin SD', 'email' => 'admin.sd'.uniqid().'@yapinet.id',
            'role' => 'admin_unit', 'school_unit_id' => $this->unit->id, 'is_active' => true,
        ]);
    }

    public function test_a_unit_admin_imports_students_into_their_own_unit_even_when_another_is_named(): void
    {
        SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMPI Al Azhar 12 Rawamangun', 'jenjang_group' => 'smp']);

        // The unit column names the SMP campus and even carries the
        // shorthand that would be ambiguous for a central admin - it must
        // be ignored entirely: the importer's own unit wins, the same line
        // importUsers draws.
        $csvContent = "nama_lengkap,nis,jenis_kelamin,unit_code\n" .
            "Siswa Unit SD,27020,L,smp\n";

        $response = $this->actingAs($this->unitAdmin())->postJson('/api/admin/import/students', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csvContent),
        ]);

        $response->assertOk()->assertJsonPath('imported_count', 1);
        $this->assertSame($this->unit->id, Student::first()->school_unit_id);
    }

    public function test_a_unit_admin_gets_a_student_template_without_the_unit_column(): void
    {
        // Their import forces their own unit, so a unit column in their
        // template would only invite values that get ignored.
        $content = $this->actingAs($this->unitAdmin())
            ->get('/api/admin/import/students/template')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('nama_lengkap', $content);
        $this->assertStringNotContainsString('unit_code', $content);
    }

    public function test_a_unit_admin_cannot_import_fee_rates(): void
    {
        // Prices stay a foundation-level decision even though students are
        // now importable per unit.
        $csvContent = "fee_type_code,unit_code,tingkat,academic_year,amount,due_day,late_fee_amount\n" .
            "spp,sd,1,2027/2028,650000,10,0\n";

        $this->actingAs($this->unitAdmin())->postJson('/api/admin/import/fee-rates', [
            'file' => UploadedFile::fake()->createWithContent('tariffs.csv', $csvContent),
        ])->assertStatus(403);

        $this->assertSame(0, FeeRate::count());
    }
}
