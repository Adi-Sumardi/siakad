<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cambridge operating model on the manual-bill lane (school decision
 * 2026-09-22): a per-unit admin issues cambridge by hand - buku folded in as
 * itemised lines, one bill, one VA - while every other fee type stays a
 * central-admin decision, the same line FeeSettingController draws.
 */
class ManualBillCambridgeTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $sd;

    private FeeType $spp;

    private FeeType $cambridge;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SDI Al Azhar 13', 'jenjang_group' => 'sd']);
        AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'])->activate();

        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly', 'is_active' => true]);
        $this->cambridge = FeeType::create(['code' => 'cambridge', 'name' => 'Cambridge', 'recurrence' => 'once', 'is_active' => true]);

        $this->student = Student::create([
            'nama_lengkap' => 'Hafidz', 'jenis_kelamin' => 'L',
            'school_unit_id' => $this->sd->id, 'status' => 'active',
        ]);
    }

    private function admin(string $role, ?SchoolUnit $unit = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.uniqid().'@yapinet.id',
            'role' => $role, 'school_unit_id' => $unit?->id,
            'is_active' => true, 'activated_at' => now(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'student_ulid' => $this->student->ulid,
            'fee_type_ulid' => $this->cambridge->ulid,
            'description' => 'Program Cambridge & Buku TA 2026/2027',
            'amount' => 900000,
            'due_date' => now()->addDays(7)->toDateString(),
        ], $overrides);
    }

    public function test_a_unit_admin_may_issue_a_cambridge_bill_but_no_other_type(): void
    {
        $this->actingAs($this->admin('admin_unit', $this->sd))
            ->postJson('/api/admin/bills/manual', $this->payload())
            ->assertCreated();

        $this->actingAs($this->admin('admin_unit', $this->sd))
            ->postJson('/api/admin/bills/manual', $this->payload(['fee_type_ulid' => $this->spp->ulid]))
            ->assertStatus(403);

        $this->assertSame(1, Bill::count(), 'only the cambridge bill exists');
    }

    public function test_a_central_admin_may_still_issue_any_type(): void
    {
        $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload())
            ->assertCreated();

        $this->actingAs($this->admin('admin'))
            ->postJson('/api/admin/bills/manual', $this->payload(['fee_type_ulid' => $this->spp->ulid]))
            ->assertCreated();

        $this->assertSame(2, Bill::count());
    }

    public function test_itemised_lines_become_the_bills_breakdown(): void
    {
        $this->actingAs($this->admin('admin_unit', $this->sd))
            ->postJson('/api/admin/bills/manual', $this->payload([
                'amount' => 950000,
                'lines' => [
                    ['name' => 'Program Cambridge', 'qty' => 1, 'unit_price' => 700000],
                    ['name' => 'Buku', 'qty' => 1, 'unit_price' => 250000],
                ],
            ]))
            ->assertCreated()
            ->assertJsonPath('bill.total_amount', 950000);

        $bill = Bill::first();

        $lines = BillLine::where('bill_id', $bill->id)->orderBy('sort_order')->get();
        $this->assertSame(2, $lines->count());
        $this->assertSame('Program Cambridge', $lines[0]->name);
        $this->assertEquals(700000, (float) $lines[0]->amount);
        $this->assertSame('Buku', $lines[1]->name);
        $this->assertEquals(250000, (float) $lines[1]->amount);
    }

    public function test_lines_must_sum_to_the_bills_amount(): void
    {
        $this->actingAs($this->admin('admin_unit', $this->sd))
            ->postJson('/api/admin/bills/manual', $this->payload([
                'amount' => 900000,
                'lines' => [
                    ['name' => 'Program Cambridge', 'qty' => 1, 'unit_price' => 700000],
                    ['name' => 'Buku', 'qty' => 1, 'unit_price' => 300000],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines']);

        $this->assertDatabaseCount('bills', 0);
    }

    public function test_without_lines_the_single_description_line_stands(): void
    {
        $this->actingAs($this->admin('admin_unit', $this->sd))
            ->postJson('/api/admin/bills/manual', $this->payload())
            ->assertCreated();

        $bill = Bill::first();
        $this->assertSame(1, BillLine::where('bill_id', $bill->id)->count());
        $this->assertSame('Program Cambridge & Buku TA 2026/2027', BillLine::where('bill_id', $bill->id)->value('name'));
    }
}
