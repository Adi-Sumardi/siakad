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

    public function test_a_unit_admin_only_sees_their_own_units_schemes_and_student_discounts(): void
    {
        $otherUnit = SchoolUnit::create(['code' => 'SMP-12', 'label' => 'SMP Islam Al Azhar 12', 'jenjang_group' => 'smp']);
        $unitAdmin = User::create(['name' => 'Admin SD', 'email' => 'admin.sd@example.com', 'role' => 'admin_unit', 'school_unit_id' => $this->unit->id, 'is_active' => true]);

        $schemeSd = DiscountScheme::create(['code' => 'sd_only', 'name' => 'Beasiswa SD', 'type' => 'percent', 'value' => 20, 'school_unit_id' => $this->unit->id, 'is_active' => true]);
        $schemeSmp = DiscountScheme::create(['code' => 'smp_only', 'name' => 'Beasiswa SMP', 'type' => 'percent', 'value' => 20, 'school_unit_id' => $otherUnit->id, 'is_active' => true]);
        $schemeSchoolWide = DiscountScheme::create(['code' => 'semua_unit', 'name' => 'Beasiswa Yayasan', 'type' => 'nominal', 'value' => 50000, 'is_active' => true]);

        $schemesRes = $this->actingAs($unitAdmin)->getJson('/api/admin/discount-schemes');
        $schemeNames = collect($schemesRes->json('schemes'))->pluck('name');
        $this->assertTrue($schemeNames->contains('Beasiswa SD'));
        $this->assertTrue($schemeNames->contains('Beasiswa Yayasan'));
        $this->assertFalse($schemeNames->contains('Beasiswa SMP'));

        $studentSd = Student::create(['nama_lengkap' => 'Anak SD', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->unit->id, 'status' => 'active']);
        $studentSmp = Student::create(['nama_lengkap' => 'Anak SMP', 'jenis_kelamin' => 'L', 'school_unit_id' => $otherUnit->id, 'status' => 'active']);

        StudentDiscount::create(['student_id' => $studentSd->id, 'discount_scheme_id' => $schemeSd->id, 'academic_year_id' => $this->year->id, 'effective_from' => '2026-07-01', 'reason' => 'Alasan siswa SD']);
        StudentDiscount::create(['student_id' => $studentSmp->id, 'discount_scheme_id' => $schemeSmp->id, 'academic_year_id' => $this->year->id, 'effective_from' => '2026-07-01', 'reason' => 'Alasan siswa SMP']);

        $sdRes = $this->actingAs($unitAdmin)->getJson('/api/admin/student-discounts');
        $names = collect($sdRes->json('student_discounts'))->pluck('student.nama_lengkap');
        $this->assertTrue($names->contains('Anak SD'));
        $this->assertFalse($names->contains('Anak SMP'));
    }

    public function test_deleting_a_scheme_still_assigned_to_a_student_is_refused_instead_of_silently_unassigning_it(): void
    {
        $scheme = DiscountScheme::create(['code' => 'aktif_dipakai', 'name' => 'Beasiswa Aktif', 'type' => 'percent', 'value' => 30, 'is_active' => true]);
        $student = Student::create(['nama_lengkap' => 'Anak Beasiswa', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->unit->id, 'status' => 'active']);

        StudentDiscount::create([
            'student_id' => $student->id, 'discount_scheme_id' => $scheme->id, 'academic_year_id' => $this->year->id,
            'effective_from' => '2026-07-01', 'effective_to' => '2027-06-30', 'reason' => 'Masih berlaku',
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/discount-schemes/{$scheme->ulid}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('discount_schemes', ['id' => $scheme->id]);
        $this->assertDatabaseHas('student_discounts', ['student_id' => $student->id, 'discount_scheme_id' => $scheme->id]);
    }

    public function test_deleting_a_scheme_with_no_active_assignments_still_works(): void
    {
        $scheme = DiscountScheme::create(['code' => 'tak_dipakai', 'name' => 'Beasiswa Kosong', 'type' => 'percent', 'value' => 10, 'is_active' => true]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/discount-schemes/{$scheme->ulid}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('discount_schemes', ['id' => $scheme->id]);
    }

    public function test_deleting_a_scheme_whose_assignment_already_expired_still_works(): void
    {
        $scheme = DiscountScheme::create(['code' => 'kadaluarsa', 'name' => 'Beasiswa Lalu', 'type' => 'percent', 'value' => 15, 'is_active' => true]);
        $student = Student::create(['nama_lengkap' => 'Anak Lulus', 'jenis_kelamin' => 'P', 'school_unit_id' => $this->unit->id, 'status' => 'active']);

        StudentDiscount::create([
            'student_id' => $student->id, 'discount_scheme_id' => $scheme->id, 'academic_year_id' => $this->year->id,
            'effective_from' => '2020-07-01', 'effective_to' => '2021-06-30', 'reason' => 'Sudah lewat',
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/discount-schemes/{$scheme->ulid}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('discount_schemes', ['id' => $scheme->id]);
    }
}
