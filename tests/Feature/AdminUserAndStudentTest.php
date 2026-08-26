<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\DiscountScheme;
use App\Models\Enrollment;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\StudentDiscount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserAndStudentTest extends TestCase
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

    public function test_can_list_users(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/users');

        $response->assertOk()
            ->assertJsonStructure([
                'users' => [
                    'data' => [
                        '*' => ['ulid', 'name', 'email', 'role', 'role_label', 'is_active'],
                    ],
                    'meta' => ['total', 'current_page'],
                ],
            ]);
    }

    public function test_can_create_and_update_user(): void
    {
        $createRes = $this->actingAs($this->admin)->postJson('/api/admin/users', [
            'name' => 'Guru Matematika',
            'email' => 'guru.matematika@yapinet.id',
            'phone' => '081122334455',
            'role' => 'guru',
            'school_unit_ulid' => $this->unit->ulid,
            'is_active' => true,
        ]);

        $createRes->assertCreated();
        $userUlid = $createRes->json('user.ulid');

        $updateRes = $this->actingAs($this->admin)->patchJson("/api/admin/users/{$userUlid}", [
            'name' => 'Guru Matematika Update',
            'is_active' => false,
        ]);

        $updateRes->assertOk();
        $this->assertDatabaseHas('users', [
            'ulid' => $userUlid,
            'name' => 'Guru Matematika Update',
            'is_active' => false,
        ]);
    }

    public function test_a_unit_admin_only_sees_their_own_units_staff_and_parents(): void
    {
        $otherUnit = SchoolUnit::create(['code' => 'smp', 'label' => 'SMP Islam Al Azhar 12', 'jenjang_group' => 'smp']);

        $unitAdmin = User::create([
            'name' => 'Admin SD', 'email' => 'admin.sd@yapinet.id',
            'role' => 'admin_unit', 'school_unit_id' => $this->unit->id, 'is_active' => true,
        ]);

        $guruSd = User::create(['name' => 'Guru SD', 'email' => 'guru.sd@yapinet.id', 'role' => 'guru', 'school_unit_id' => $this->unit->id, 'is_active' => true]);
        $guruSmp = User::create(['name' => 'Guru SMP', 'email' => 'guru.smp@yapinet.id', 'role' => 'guru', 'school_unit_id' => $otherUnit->id, 'is_active' => true]);

        $studentSd = Student::create(['nama_lengkap' => 'Anak SD', 'jenis_kelamin' => 'L', 'school_unit_id' => $this->unit->id, 'status' => 'active']);
        $studentSmp = Student::create(['nama_lengkap' => 'Anak SMP', 'jenis_kelamin' => 'L', 'school_unit_id' => $otherUnit->id, 'status' => 'active']);

        $waliSdUser = User::create(['name' => 'Wali SD', 'email' => 'wali.sd@example.com', 'role' => 'orangtua', 'is_active' => true]);
        $waliSd = Guardian::create(['user_id' => $waliSdUser->id, 'nama' => 'Wali SD', 'hubungan' => 'ayah', 'email' => $waliSdUser->email]);
        $waliSd->students()->attach($studentSd->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $waliSmpUser = User::create(['name' => 'Wali SMP', 'email' => 'wali.smp@example.com', 'role' => 'orangtua', 'is_active' => true]);
        $waliSmp = Guardian::create(['user_id' => $waliSmpUser->id, 'nama' => 'Wali SMP', 'hubungan' => 'ayah', 'email' => $waliSmpUser->email]);
        $waliSmp->students()->attach($studentSmp->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $response = $this->actingAs($unitAdmin)->getJson('/api/admin/users?per_page=100');

        $response->assertOk();
        $emails = collect($response->json('users.data'))->pluck('email');

        $this->assertTrue($emails->contains('guru.sd@yapinet.id'));
        $this->assertTrue($emails->contains('wali.sd@example.com'));
        $this->assertFalse($emails->contains('guru.smp@yapinet.id'));
        $this->assertFalse($emails->contains('wali.smp@example.com'));
    }

    public function test_the_student_list_shows_the_guardians_actual_name_and_phone(): void
    {
        $student = Student::create([
            'nama_lengkap' => 'Budi Santoso', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->unit->id, 'status' => 'active',
        ]);

        $guardianUser = User::create(['name' => 'Pak Budi', 'email' => 'pak.budi@example.com', 'role' => 'orangtua', 'is_active' => true]);
        $guardian = Guardian::create([
            'user_id' => $guardianUser->id, 'nama' => 'Budi Santoso Sr.',
            'hubungan' => 'ayah', 'no_hp' => '081234567890', 'email' => $guardianUser->email,
        ]);
        $guardian->students()->attach($student->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/students');

        // Guardian has no nama_lengkap or phone column - it's nama and
        // no_hp. Reading the wrong attribute name doesn't error, it just
        // silently returns null, which is how this shipped with every row's
        // parent contact column blank.
        $response->assertOk()
            ->assertJsonPath('students.data.0.guardian.name', 'Budi Santoso Sr.')
            ->assertJsonPath('students.data.0.guardian.phone', '081234567890');
    }

    public function test_can_list_students_with_spp_and_discounts(): void
    {
        $sppType = FeeType::create([
            'code' => 'spp',
            'name' => 'SPP Bulanan',
            'recurrence' => 'monthly',
            'allow_installment' => true,
            'is_active' => true,
        ]);

        FeeRate::create([
            'fee_type_id' => $sppType->id,
            'school_unit_id' => $this->unit->id,
            'academic_year_id' => $this->year->id,
            'tingkat' => 1,
            'amount' => 600000,
            'due_day' => 10,
            'is_active' => true,
        ]);

        $classroom = Classroom::create([
            'school_unit_id' => $this->unit->id,
            'academic_year_id' => $this->year->id,
            'name' => '1-A',
            'tingkat' => 1,
        ]);

        $student = Student::create([
            'nama_lengkap' => 'Ahmad Fajar',
            'nis' => '13001',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $this->unit->id,
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'classroom_id' => $classroom->id,
            'academic_year_id' => $this->year->id,
            'status' => 'active',
            'joined_on' => '2026-07-01',
        ]);

        $scheme = DiscountScheme::create([
            'code' => 'BEASISWA-25',
            'name' => 'Beasiswa Prestasi 25%',
            'type' => 'percent',
            'value' => 25.00,
            'school_unit_id' => $this->unit->id,
            'fee_type_id' => $sppType->id,
            'is_active' => true,
        ]);

        StudentDiscount::create([
            'student_id' => $student->id,
            'discount_scheme_id' => $scheme->id,
            'academic_year_id' => $this->year->id,
            'effective_from' => '2026-07-01',
            'reason' => 'Juara Tahfidz',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/students');

        $response->assertOk()
            ->assertJsonPath('students.data.0.nama_lengkap', 'Ahmad Fajar')
            ->assertJsonPath('students.data.0.pricing.base_spp', 600000)
            ->assertJsonPath('students.data.0.pricing.discount_amount', 150000)
            ->assertJsonPath('students.data.0.pricing.net_spp', 450000);
    }
}
