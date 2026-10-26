<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard eksekutif (Direktur Pendidikan & BPH): siapa boleh masuk, apa
 * yang tiap role lihat, dan angka umur tunggakan yang hanya ada di sini.
 */
class ExecutiveDashboardTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private SchoolUnit $sd;

    private SchoolUnit $smp;

    private FeeType $spp;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->year = AcademicYear::create([
            'year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30', 'is_active' => true,
        ]);
        $this->sd = SchoolUnit::create(['code' => 'SD-13', 'label' => 'SDI Al Azhar 13 Rawamangun', 'jenjang_group' => 'sd', 'is_active' => true]);
        $this->smp = SchoolUnit::create(['code' => 'SMP-55', 'label' => 'SMPI Al Azhar 55 Jatimakmur', 'jenjang_group' => 'smp', 'is_active' => true]);
        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
    }

    private function user(string $role, ?SchoolUnit $unit = null): User
    {
        $this->seq++;

        return User::create([
            'name' => "User {$role} {$this->seq}", 'email' => "{$role}{$this->seq}@example.com",
            'role' => $role, 'school_unit_id' => $unit?->id, 'is_active' => true,
        ]);
    }

    private function student(SchoolUnit $unit, string $name = 'Siswa'): Student
    {
        $this->seq++;

        return Student::create([
            'nama_lengkap' => "{$name} {$this->seq}", 'jenis_kelamin' => 'L', 'school_unit_id' => $unit->id,
            'entry_year_id' => $this->year->id, 'nis' => sprintf('%06d', $this->seq), 'status' => 'active',
        ]);
    }

    private function bill(Student $student, float $total, float $remaining, string $status, string $dueDate): Bill
    {
        $this->seq++;

        return Bill::create([
            'bill_number' => "SPP/{$this->seq}", 'dedup_key' => "spp:{$this->seq}", 'description' => 'SPP',
            'student_id' => $student->id, 'academic_year_id' => $this->year->id, 'fee_type_id' => $this->spp->id,
            'subtotal' => $total, 'total_amount' => $total, 'paid_amount' => $total - $remaining,
            'remaining_amount' => $remaining, 'status' => $status, 'due_date' => $dueDate, 'issued_at' => now(),
        ]);
    }

    public function test_only_executive_roles_reach_the_dashboard(): void
    {
        $this->actingAs($this->user('direktur_pendidikan'))->getJson('/api/eksekutif/ringkasan')->assertOk();
        $this->actingAs($this->user('bph'))->getJson('/api/eksekutif/ringkasan')->assertOk();

        $this->actingAs($this->user('orangtua'))->getJson('/api/eksekutif/ringkasan')->assertForbidden();
        foreach (['admin_unit', 'guru', 'kepala_sekolah'] as $role) {
            $this->actingAs($this->user($role, $this->sd))->getJson('/api/eksekutif/ringkasan')->assertForbidden();
        }
    }

    public function test_executive_roles_cannot_open_the_admin_area(): void
    {
        foreach (['direktur_pendidikan', 'bph'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->getJson('/api/admin/dashboard/summary')->assertForbidden();
            $this->actingAs($user)->getJson('/api/admin/students')->assertForbidden();
            $this->actingAs($user)->getJson('/api/admin/bills')->assertForbidden();
        }
    }

    public function test_direktur_gets_no_finance_block_and_bph_does(): void
    {
        $this->bill($this->student($this->sd), 1000000, 1000000, 'unpaid', now()->addDays(5)->toDateString());

        $direktur = $this->actingAs($this->user('direktur_pendidikan'))->getJson('/api/eksekutif/ringkasan')->assertOk()->json();
        $this->assertArrayNotHasKey('keuangan', $direktur);
        $this->assertSame('Direktur Pendidikan', $direktur['viewer']['role_label']);
        // The one finance number the Direktur keeps.
        $this->assertSame(0.0, (float) collect($direktur['pendidikan']['per_unit'])->firstWhere('code', 'SD-13')['collection_rate']);

        $bph = $this->actingAs($this->user('bph'))->getJson('/api/eksekutif/ringkasan')->assertOk()->json();
        $this->assertArrayHasKey('keuangan', $bph);
        $this->assertSame('Badan Pengurus Harian', $bph['viewer']['role_label']);
        // BPH's education rows are the brief version.
        $this->assertArrayNotHasKey('violation_students', $bph['pendidikan']['per_unit'][0]);
    }

    public function test_arrears_are_aged_per_bill_from_the_due_date_and_count_only_what_is_left(): void
    {
        $student = $this->student($this->smp);
        $this->bill($student, 1900000, 1900000, 'overdue', now('Asia/Jakarta')->subDays(10)->toDateString());   // 0–30
        $this->bill($student, 1900000, 1900000, 'overdue', now('Asia/Jakarta')->subDays(45)->toDateString());   // 31–60
        $this->bill($student, 2000000, 1000000, 'partial', now('Asia/Jakarta')->subDays(75)->toDateString());   // 61–90, half paid
        $this->bill($student, 1900000, 1900000, 'overdue', now('Asia/Jakarta')->subDays(120)->toDateString());  // > 90
        $this->bill($student, 1900000, 1900000, 'unpaid', now('Asia/Jakarta')->addDays(5)->toDateString());    // not due yet
        $this->bill($student, 1900000, 0, 'paid', now('Asia/Jakarta')->subDays(200)->toDateString());          // settled
        $this->bill($student, 1900000, 1900000, 'cancelled', now('Asia/Jakarta')->subDays(200)->toDateString()); // void

        $body = $this->actingAs($this->user('bph'))->getJson('/api/eksekutif/ringkasan')->assertOk()->json();

        $row = collect($body['keuangan']['per_unit'])->firstWhere('code', 'SMP-55');
        $this->assertSame([1900000.0, 1900000.0, 1000000.0, 1900000.0], array_map('floatval', $row['arrears']));
        $this->assertSame(6700000.0, (float) $row['arrears_total']);
        $this->assertSame(1900000.0, (float) $body['keuangan']['tiles']['arrears_over_90']);

        // 1,9 jt of 6,7 jt is over 90 days (28%) - above the 15% line.
        $this->assertTrue(collect($body['alerts'])->contains(fn ($a) => str_starts_with($a['title'], 'Tunggakan lama SMPI Al Azhar 55')));
    }

    public function test_low_collection_raises_a_critical_alert_for_bph_only(): void
    {
        $student = $this->student($this->sd);
        $this->bill($student, 1000000, 1000000, 'unpaid', now()->addDays(5)->toDateString());

        $bph = $this->actingAs($this->user('bph'))->getJson('/api/eksekutif/ringkasan')->json();
        $alert = collect($bph['alerts'])->first(fn ($a) => str_starts_with($a['title'], 'SPP SDI Al Azhar 13'));
        $this->assertNotNull($alert);
        $this->assertSame('kritis', $alert['tone']);
        $this->assertSame('SD-13', $alert['unit_code']);

        $direktur = $this->actingAs($this->user('direktur_pendidikan'))->getJson('/api/eksekutif/ringkasan')->json();
        $this->assertFalse(collect($direktur['alerts'])->contains(fn ($a) => str_starts_with($a['title'], 'SPP ')));
    }

    public function test_falling_enrollment_is_flagged_for_both_roles(): void
    {
        $lastYear = AcademicYear::create(['year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30', 'is_active' => false]);
        $old = Classroom::create(['school_unit_id' => $this->smp->id, 'academic_year_id' => $lastYear->id, 'name' => '7A', 'tingkat' => 7, 'is_active' => true]);
        $now = Classroom::create(['school_unit_id' => $this->smp->id, 'academic_year_id' => $this->year->id, 'name' => '8A', 'tingkat' => 8, 'is_active' => true]);

        $students = collect(range(1, 3))->map(fn () => $this->student($this->smp));
        foreach ($students as $s) {
            Enrollment::create(['student_id' => $s->id, 'classroom_id' => $old->id, 'academic_year_id' => $lastYear->id, 'status' => 'promoted', 'joined_on' => '2025-07-14']);
        }
        Enrollment::create(['student_id' => $students[0]->id, 'classroom_id' => $now->id, 'academic_year_id' => $this->year->id, 'status' => 'active', 'joined_on' => '2026-07-14']);

        foreach (['direktur_pendidikan', 'bph'] as $role) {
            $body = $this->actingAs($this->user($role))->getJson('/api/eksekutif/ringkasan')->json();

            $this->assertSame(['2025/2026', '2026/2027'], $body['enrollment_trend']['years']);
            $this->assertSame([3, 1], collect($body['enrollment_trend']['units'])->firstWhere('code', 'SMP-55')['counts']);
            $this->assertTrue(collect($body['alerts'])->contains(fn ($a) => $a['title'] === 'Jumlah siswa SMPI Al Azhar 55 Jatimakmur turun'));
        }
    }

    public function test_unit_filter_narrows_tiles_and_unknown_unit_is_404(): void
    {
        $this->student($this->sd);
        $this->student($this->sd);
        $this->student($this->smp);

        $user = $this->user('direktur_pendidikan');

        $all = $this->actingAs($user)->getJson('/api/eksekutif/ringkasan')->json();
        $this->assertSame(3, $all['pendidikan']['tiles']['students_active']);
        $this->assertNull($all['selected_unit']);

        $one = $this->actingAs($user)->getJson('/api/eksekutif/ringkasan?unit=SD-13')->assertOk()->json();
        $this->assertSame(2, $one['pendidikan']['tiles']['students_active']);
        $this->assertSame('SD-13', $one['selected_unit']['code']);
        $this->assertCount(2, $one['pendidikan']['per_unit'], 'the comparison still lists every unit');

        $this->actingAs($user)->getJson('/api/eksekutif/ringkasan?unit=NOPE')->assertNotFound();
    }

    public function test_cash_received_is_counted_in_the_month_it_was_paid(): void
    {
        $student = $this->student($this->sd);
        $bill = $this->bill($student, 1000000, 0, 'paid', now()->subDays(3)->toDateString());
        $payment = Payment::create([
            'payment_number' => 'PAY-1', 'amount' => 1000000, 'method' => 'cash', 'status' => 'completed', 'paid_at' => now(),
        ]);
        PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => 1000000]);

        $body = $this->actingAs($this->user('bph'))->getJson('/api/eksekutif/ringkasan')->json();

        $trend = $body['keuangan']['cash_trend'];
        $this->assertCount(6, $trend);
        $this->assertSame(now('Asia/Jakarta')->format('Y-m'), end($trend)['key']);
        $this->assertSame(1000000.0, (float) end($trend)['amount']);
    }

    public function test_payload_carries_no_student_identity(): void
    {
        $student = $this->student($this->sd, 'Ahmad Zulkarnain');
        $this->bill($student, 1000000, 1000000, 'overdue', now()->subDays(100)->toDateString());

        foreach (['bph', 'direktur_pendidikan'] as $role) {
            $raw = $this->actingAs($this->user($role))->getJson('/api/eksekutif/ringkasan')->getContent();

            $this->assertStringNotContainsString('Ahmad Zulkarnain', $raw);
            $this->assertStringNotContainsString('"'.$student->nis.'"', $raw);
            $this->assertStringNotContainsString($student->ulid, $raw);
        }
    }

    /** docs/08 §5.2: the aging buckets add up to the admin dashboard's overdue amount. */
    public function test_aging_total_equals_the_admin_dashboard_overdue_amount(): void
    {
        $lastYear = AcademicYear::create(['year' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30', 'is_active' => false]);
        $a = $this->student($this->sd);
        $b = $this->student($this->smp);
        $this->bill($a, 1500000, 1500000, 'overdue', now('Asia/Jakarta')->toDateString());             // due today counts
        $this->bill($a, 1500000, 700000, 'partial', now('Asia/Jakarta')->subDays(40)->toDateString());
        $this->bill($b, 2000000, 2000000, 'unpaid', now('Asia/Jakarta')->subDays(95)->toDateString());
        $this->bill($b, 2000000, 2000000, 'unpaid', now('Asia/Jakarta')->addDay()->toDateString());   // not due yet
        Bill::where('student_id', $b->id)->latest('id')->first()->replicate(['ulid', 'bill_number', 'dedup_key'])
            ->fill(['bill_number' => 'OLD/1', 'dedup_key' => 'old:1', 'academic_year_id' => $lastYear->id, 'due_date' => now()->subDays(200)->toDateString()])
            ->save();

        $admin = $this->user('admin');
        $overdue = $this->actingAs($admin)->getJson('/api/admin/dashboard/summary?billing_period=all')->json('kpi.billing.overdue_amount');

        $tiles = $this->actingAs($this->user('bph'))->getJson('/api/eksekutif/ringkasan')->json('keuangan.tiles');

        $this->assertSame((float) $overdue, (float) $tiles['arrears_total']);
        $this->assertSame(6200000.0, (float) $tiles['arrears_total']);
    }

    public function test_central_admin_can_preview_either_view(): void
    {
        $admin = $this->user('admin');

        $bph = $this->actingAs($admin)->getJson('/api/eksekutif/ringkasan')->assertOk()->json();
        $this->assertSame('bph', $bph['viewer']['role']);
        $this->assertTrue($bph['viewer']['is_preview']);
        $this->assertArrayHasKey('keuangan', $bph);

        $dir = $this->actingAs($admin)->getJson('/api/eksekutif/ringkasan?as=direktur_pendidikan')->assertOk()->json();
        $this->assertSame('direktur_pendidikan', $dir['viewer']['role']);
        $this->assertArrayNotHasKey('keuangan', $dir);

        // Only the central admin may switch views; a Direktur asking for BPH stays Direktur.
        $self = $this->actingAs($this->user('direktur_pendidikan'))->getJson('/api/eksekutif/ringkasan?as=bph')->assertOk()->json();
        $this->assertSame('direktur_pendidikan', $self['viewer']['role']);
        $this->assertArrayNotHasKey('keuangan', $self);
    }

    public function test_old_arrears_above_thirty_percent_are_critical(): void
    {
        $student = $this->student($this->smp);
        $this->bill($student, 1000000, 1000000, 'overdue', now('Asia/Jakarta')->subDays(10)->toDateString());
        $this->bill($student, 1000000, 1000000, 'overdue', now('Asia/Jakarta')->subDays(120)->toDateString());

        $alerts = collect($this->actingAs($this->user('bph'))->getJson('/api/eksekutif/ringkasan')->json('alerts'));

        $old = $alerts->first(fn ($a) => str_starts_with($a['title'], 'Tunggakan lama'));
        $this->assertSame('kritis', $old['tone']);
    }

    public function test_central_admin_creates_executive_accounts_without_a_unit(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Ketua BPH', 'email' => 'ketua.bph@example.com', 'role' => 'bph',
            'school_unit_ulid' => $this->sd->ulid,
        ])->assertCreated();

        $this->assertNull(User::where('email', 'ketua.bph@example.com')->value('school_unit_id'));

        $unitAdmin = $this->user('admin_unit', $this->sd);
        $this->actingAs($unitAdmin)->postJson('/api/admin/users', [
            'name' => 'Direktur', 'email' => 'direktur@example.com', 'role' => 'direktur_pendidikan',
        ])->assertStatus(422);
    }
}
