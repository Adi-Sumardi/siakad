<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This exports a CSV in Formulir Peserta Didik (F-PD) column order to speed
 * up an operator's manual Dapodik entry - it is not an import into Dapodik,
 * which has no public write API. Roughly half of F-PD's fields have no
 * source in Siakad at all and must read as the '[isi manual]' placeholder,
 * never a silent blank.
 */
class DapodikExportTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->smp = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();
    }

    private function admin(?SchoolUnit $unit = null, string $role = 'admin'): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'admin'.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function csvRows(string $content): array
    {
        $lines = array_filter(explode("\n", trim($content)));

        return array_map('str_getcsv', $lines);
    }

    public function test_the_export_streams_csv_with_the_f_pd_header_row(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/api/admin/students/dapodik-export');

        $response->assertOk();
        $this->assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));

        $rows = $this->csvRows($response->streamedContent());
        $this->assertSame('Nama Lengkap', $rows[0][0]);
        $this->assertSame('Jenis Kelamin', $rows[0][1]);
        $this->assertCount(53, $rows[0]);
    }

    public function test_a_field_siakad_has_data_for_appears_correctly(): void
    {
        $admin = $this->admin();
        Student::create([
            'nama_lengkap' => 'Ahmad Fajar', 'nis' => '13001', 'jenis_kelamin' => 'L',
            'tempat_lahir' => 'Jakarta', 'school_unit_id' => $this->sd->id, 'status' => 'active',
        ]);

        $response = $this->actingAs($admin)->get('/api/admin/students/dapodik-export');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertSame('Ahmad Fajar', $rows[1][0]);
        $this->assertSame('Laki-laki', $rows[1][1]);
        $this->assertSame('Jakarta', $rows[1][5]);
    }

    public function test_a_field_with_no_source_in_siakad_is_marked_isi_manual(): void
    {
        $admin = $this->admin();
        Student::create([
            'nama_lengkap' => 'Ahmad Fajar', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sd->id, 'status' => 'active',
        ]);

        $response = $this->actingAs($admin)->get('/api/admin/students/dapodik-export');
        $rows = $this->csvRows($response->streamedContent());

        // No KK - column index 4 - has no source anywhere in Siakad's schema.
        $this->assertSame('[isi manual]', $rows[1][4]);
    }

    public function test_agama_maps_to_its_numeric_code_for_a_recognised_value(): void
    {
        $admin = $this->admin();
        Student::create([
            'nama_lengkap' => 'Ahmad Fajar', 'jenis_kelamin' => 'L', 'agama' => 'Islam',
            'school_unit_id' => $this->sd->id, 'status' => 'active',
        ]);

        $response = $this->actingAs($admin)->get('/api/admin/students/dapodik-export');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertSame('01', $rows[1][8]);
    }

    public function test_an_admin_unit_only_exports_their_own_units_students(): void
    {
        $adminSd = $this->admin($this->sd, 'admin_unit');
        Student::create(['nama_lengkap' => 'Anak SD', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->sd->id, 'status' => 'active']);
        Student::create(['nama_lengkap' => 'Anak SMP', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->smp->id, 'status' => 'active']);

        $response = $this->actingAs($adminSd)->get('/api/admin/students/dapodik-export');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertCount(2, $rows); // header + 1 student
        $this->assertSame('Anak SD', $rows[1][0]);
    }

    public function test_encrypted_fields_decrypt_correctly_in_the_export(): void
    {
        $admin = $this->admin();
        Student::create([
            'nama_lengkap' => 'Ahmad Fajar', 'jenis_kelamin' => 'L', 'nisn' => '0012345678',
            'school_unit_id' => $this->sd->id, 'status' => 'active',
        ]);

        $response = $this->actingAs($admin)->get('/api/admin/students/dapodik-export');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertSame('0012345678', $rows[1][2]);
    }

    public function test_a_guardians_name_appears_under_the_correct_relationship_column(): void
    {
        $admin = $this->admin();
        $student = Student::create([
            'nama_lengkap' => 'Ahmad Fajar', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sd->id, 'status' => 'active',
        ]);
        $ayah = Guardian::create(['nama' => 'Budi Santoso', 'hubungan' => 'ayah']);
        $student->guardians()->attach($ayah->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $response = $this->actingAs($admin)->get('/api/admin/students/dapodik-export');
        $rows = $this->csvRows($response->streamedContent());

        $this->assertSame('Budi Santoso', $rows[1][25]); // Nama Ayah Kandung
    }
}
