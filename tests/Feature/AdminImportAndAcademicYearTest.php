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
}
