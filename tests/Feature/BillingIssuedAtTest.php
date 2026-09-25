<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tanggal terbit pada billing run (feature batch Poin 10, user's explicit
 * choice: the PRINTING date, not the due date): an optional issued_at that
 * overrides Bill::issued_at for the whole run - backdating a late-issued
 * month is the point. Everything else (due_day, dedup, skipping) untouched.
 */
class BillingIssuedAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_run_carries_the_requested_issued_date_on_every_bill(): void
    {
        $unit = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD 13', 'jenjang_group' => 'sd']);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);
        $spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        FeeRate::create([
            'fee_type_id' => $spp->id,
            'school_unit_id' => $unit->id,
            'academic_year_id' => $year->id,
            'amount' => 650000,
            'due_day' => 10,
        ]);

        Student::create([
            'nama_lengkap' => 'Siswa Terbit',
            'jenis_kelamin' => 'L',
            'school_unit_id' => $unit->id,
            'entry_year_id' => $year->id,
            'status' => 'active',
        ]);

        $admin = User::create([
            'name' => 'Admin Pusat',
            'email' => 'admin'.uniqid().'@yapinet.id',
            'role' => 'admin',
            'is_active' => true,
            'activated_at' => now(),
        ]);

        app(BillGenerator::class)->run($spp, $year, $unit, 8, null, null, $admin, Carbon::parse('2026-08-01'));

        $bill = Bill::sole();

        // The printing date is the requested one; the due date still comes
        // from the rate's due_day (10th of the billed month).
        $this->assertSame('2026-08-01', $bill->issued_at->toDateString());
        $this->assertSame('2026-08-10', $bill->due_date->toDateString());
    }

    public function test_a_run_without_issued_at_prints_today(): void
    {
        $unit = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SD 13', 'jenjang_group' => 'sd']);
        $year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true]);
        $spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);

        FeeRate::create([
            'fee_type_id' => $spp->id,
            'school_unit_id' => $unit->id,
            'academic_year_id' => $year->id,
            'amount' => 100000,
            'due_day' => 10,
        ]);

        Student::create([
            'nama_lengkap' => 'Siswa Hari Ini',
            'jenis_kelamin' => 'P',
            'school_unit_id' => $unit->id,
            'entry_year_id' => $year->id,
            'status' => 'active',
        ]);

        app(BillGenerator::class)->run($spp, $year, $unit, 9);

        $this->assertSame(
            now()->toDateString(),
            Bill::sole()->issued_at->toDateString(),
            'tanpa issued_at, perilaku lama (hari ini) utuh',
        );
    }
}
