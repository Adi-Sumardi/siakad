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
