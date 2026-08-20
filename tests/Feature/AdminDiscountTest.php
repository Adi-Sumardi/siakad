<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\DiscountScheme;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\StudentDiscount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDiscountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private SchoolUnit $unit;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Administrator',
            'email' => 'admin@example.com',
            'phone' => '081111111111',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->unit = SchoolUnit::create([
            'code' => 'SD-ALAZHAR13',
            'label' => 'SD Islam Al Azhar 13',
            'jenjang_group' => 'sd',
        ]);

        $this->year = AcademicYear::create([
            'year' => '2026/2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
        ]);
    }

    public function test_admin_can_create_and_list_discount_schemes(): void
    {
        $feeType = FeeType::create([
            'code' => 'spp',
            'name' => 'SPP',
            'recurrence' => 'monthly',
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/discount-schemes', [
            'code' => 'beasiswa_tahfidz',
            'name' => 'Beasiswa Tahfidz Quran',
            'type' => 'percent',
            'value' => 50,
            'fee_type_ulid' => $feeType->ulid,
            'school_unit_ulid' => $this->unit->ulid,
            'is_active' => true,
            'notes' => 'Potongan 50% SPP untuk hafalan 5 juz',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('discount_schemes', ['code' => 'beasiswa_tahfidz']);

        $list = $this->actingAs($this->admin)->getJson('/api/admin/discount-schemes');
        $list->assertStatus(200);
        $list->assertJsonFragment(['name' => 'Beasiswa Tahfidz Quran']);
    }

    public function test_admin_can_assign_discount_to_student(): void
    {
        $scheme = DiscountScheme::create([
            'code' => 'yatim_piatu',
            'name' => 'Subsidi Yatim',
            'type' => 'percent',
            'value' => 100,
            'is_active' => true,
        ]);

        $student = Student::create([
            'school_unit_id' => $this->unit->id,
            'nis' => '54321',
            'nisn' => '0098765432',
            'nik' => '3201010101010002',
            'nama_lengkap' => 'Fatimah Az Zahra',
            'nama_panggilan' => 'Fatimah',
            'jenis_kelamin' => 'P',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/student-discounts', [
            'student_ulid' => $student->ulid,
            'discount_scheme_ulid' => $scheme->ulid,
            'academic_year_ulid' => $this->year->ulid,
            'effective_from' => '2026-07-01',
            'effective_to' => '2027-06-30',
            'reason' => 'Surat keterangan anak yatim terverifikasi',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('student_discounts', [
            'student_id' => $student->id,
            'discount_scheme_id' => $scheme->id,
        ]);

        $list = $this->actingAs($this->admin)->getJson('/api/admin/student-discounts');
        $list->assertStatus(200);
        $list->assertJsonFragment(['nama_lengkap' => 'Fatimah Az Zahra']);
    }
}
