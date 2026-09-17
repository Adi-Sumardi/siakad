<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The 20-rows-per-page contract shared by the big admin lists (siswa,
 * tagihan, pengguna, log aktivitas): the LIMIT happens in the query, meta
 * describes the whole filtered set, and search/filters shrink that set - not
 * just the page on screen.
 */
class PaginationTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;

    private AcademicYear $year;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);

        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();

        $this->admin = User::create([
            'name' => 'Admin Pusat',
            'email' => 'admin@yapinet.id',
            'role' => 'admin',
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    public function test_the_student_list_serves_20_rows_per_page_with_full_set_meta(): void
    {
        $spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        FeeRate::create([
            'fee_type_id' => $spp->id,
            'school_unit_id' => $this->unit->id,
            'academic_year_id' => $this->year->id,
            'amount' => 100000,
            'due_day' => 10,
        ]);

        foreach (range(1, 45) as $i) {
            Student::create([
                'nama_lengkap' => sprintf('Siswa %02d', $i),
                'jenis_kelamin' => 'L',
                'school_unit_id' => $this->unit->id,
                'status' => 'active',
            ]);
        }

        $pageOne = $this->actingAs($this->admin)->getJson('/api/admin/students');
        $pageOne->assertOk()
            ->assertJsonCount(20, 'students.data')
            ->assertJsonPath('students.data.0.nama_lengkap', 'Siswa 01')
            ->assertJsonPath('students.data.19.nama_lengkap', 'Siswa 20')
            ->assertJsonPath('students.meta.current_page', 1)
            ->assertJsonPath('students.meta.last_page', 3)
            ->assertJsonPath('students.meta.total', 45)
            ->assertJsonPath('students.meta.per_page', 20)
            // KPI aggregates cover the whole cohort, not the 20 rows on screen.
            ->assertJsonPath('students.meta.totals.base_spp', 4500000)
            ->assertJsonPath('students.meta.totals.net_spp', 4500000);

        $this->actingAs($this->admin)->getJson('/api/admin/students?page=3')
            ->assertOk()
            ->assertJsonCount(5, 'students.data')
            ->assertJsonPath('students.data.0.nama_lengkap', 'Siswa 41')
            ->assertJsonPath('students.data.4.nama_lengkap', 'Siswa 45');
    }

    public function test_student_pagination_follows_the_search_filter(): void
    {
        foreach (range(1, 25) as $i) {
            Student::create([
                'nama_lengkap' => "Target Cari {$i}",
                'jenis_kelamin' => 'P',
                'school_unit_id' => $this->unit->id,
                'status' => 'active',
            ]);
        }
        foreach (range(1, 10) as $i) {
            Student::create([
                'nama_lengkap' => "Lain {$i}",
                'jenis_kelamin' => 'P',
                'school_unit_id' => $this->unit->id,
                'status' => 'active',
            ]);
        }

        $this->actingAs($this->admin)->getJson('/api/admin/students?search=Target Cari')
            ->assertOk()
            ->assertJsonCount(20, 'students.data')
            ->assertJsonPath('students.meta.total', 25)
            ->assertJsonPath('students.meta.last_page', 2);

        $this->actingAs($this->admin)->getJson('/api/admin/students?search=Target Cari&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'students.data');
    }

    public function test_the_bill_list_defaults_to_20_rows_per_page(): void
    {
        $spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
        $student = Student::create([
            'nama_lengkap' => 'Anak SD',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->unit->id,
            'status' => 'active',
        ]);

        foreach (range(1, 25) as $i) {
            Bill::create([
                'student_id' => $student->id,
                'academic_year_id' => $this->year->id,
                'fee_type_id' => $spp->id,
                'bill_number' => sprintf('SPP/2026/08/%05d', $i),
                'dedup_key' => "spp:2026:08:{$i}",
                'description' => "SPP Bulan ke-{$i}",
                'subtotal' => 650000,
                'total_amount' => 650000,
                'remaining_amount' => 650000,
                'status' => 'unpaid',
                'due_date' => now()->addDays($i),
                'issued_at' => now(),
            ]);
        }

        $this->actingAs($this->admin)->getJson('/api/admin/bills')
            ->assertOk()
            ->assertJsonCount(20, 'bills.data')
            ->assertJsonPath('bills.meta.current_page', 1)
            ->assertJsonPath('bills.meta.last_page', 2)
            ->assertJsonPath('bills.meta.total', 25)
            ->assertJsonPath('bills.meta.per_page', 20);

        $this->actingAs($this->admin)->getJson('/api/admin/bills?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'bills.data');
    }

    public function test_the_user_list_paginates_at_20_per_page(): void
    {
        foreach (range(1, 25) as $i) {
            User::create([
                'name' => "Guru {$i}",
                'email' => "guru{$i}@yapinet.id",
                'role' => 'guru',
                'school_unit_id' => $this->unit->id,
                'is_active' => true,
                'activated_at' => now(),
            ]);
        }

        $this->actingAs($this->admin)->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(20, 'users.data')
            ->assertJsonPath('users.meta.total', 26) // 25 guru + the admin
            ->assertJsonPath('users.meta.last_page', 2);

        $this->actingAs($this->admin)->getJson('/api/admin/users?page=2')
            ->assertOk()
            ->assertJsonCount(6, 'users.data');
    }

    public function test_the_activity_log_paginates_at_20_per_page(): void
    {
        $student = Student::create([
            'nama_lengkap' => 'Anak SD',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $this->unit->id,
            'status' => 'active',
        ]);

        foreach (range(1, 25) as $i) {
            ActivityLog::record($this->admin, 'student.updated', $student, ['n' => $i]);
        }

        $this->actingAs($this->admin)->getJson('/api/admin/activity-logs')
            ->assertOk()
            ->assertJsonCount(20, 'logs.data')
            ->assertJsonPath('logs.meta.total', 25)
            ->assertJsonPath('logs.meta.last_page', 2)
            ->assertJsonPath('logs.meta.per_page', 20);

        $this->actingAs($this->admin)->getJson('/api/admin/activity-logs?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'logs.data');
    }
}
