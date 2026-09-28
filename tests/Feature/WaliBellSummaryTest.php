<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The wali bell's light poll (T67-c): counts + a change signature instead
 * of the open-bill rows and payments page the navbar used to drag down
 * every 60 seconds. The numbers must agree with what the real pages show -
 * same visibleTo scope, same open statuses, same 7-day receipts window.
 */
class WaliBellSummaryTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;

    private AcademicYear $year;

    private FeeType $spp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));

        $this->unit = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->spp = FeeType::create(['code' => 'spp', 'name' => 'SPP', 'recurrence' => 'monthly']);
    }

    private function student(string $name = 'Aisyah Nur Ramadhani'): Student
    {
        return Student::create([
            'nama_lengkap' => $name, 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->unit->id, 'entry_year_id' => $this->year->id, 'status' => 'active',
        ]);
    }

    private function guardianFor(Student ...$students): User
    {
        $user = User::create([
            'name' => 'Budi', 'email' => 'budi'.uniqid().'@example.com',
            'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
        ]);
        $guardian = Guardian::create([
            'user_id' => $user->id, 'nama' => 'Budi', 'hubungan' => 'ayah', 'email' => $user->email,
        ]);
        foreach ($students as $student) {
            $student->guardians()->attach($guardian->id, ['relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true]);
        }

        return $user;
    }

    private function bill(Student $student, string $status, float $remaining, ?string $dueDate = null): Bill
    {
        return Bill::create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $this->spp->id,
            'dedup_key' => uniqid('bell-'),
            'bill_number' => 'SPP/2026/09/'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'description' => 'SPP September', 'subtotal' => $remaining, 'total_amount' => $remaining,
            'remaining_amount' => $remaining, 'status' => $status,
            'due_date' => $dueDate ?? now()->addDays(10)->toDateString(), 'issued_at' => now(),
        ]);
    }

    private function payment(Guardian $payer, string $status, ?string $paidAt): Payment
    {
        return Payment::create([
            'payment_number' => 'PAY-'.uniqid(),
            'payer_guardian_id' => $payer->id,
            'amount' => 500000,
            'method' => 'bank_transfer',
            'status' => $status,
            'paid_at' => $paidAt,
        ]);
    }

    public function test_the_summary_counts_only_what_the_bell_lists(): void
    {
        $student = $this->student();
        $user = $this->guardianFor($student);
        $payer = Guardian::where('user_id', $user->id)->first();

        $this->bill($student, 'unpaid', 650000);
        $this->bill($student, 'overdue', 200000, now()->subDays(3)->toDateString());
        $this->bill($student, 'paid', 0); // closed - invisible to the bell

        $this->payment($payer, 'completed', now()->subDays(2)->toIso8601String()); // inside the window
        $this->payment($payer, 'completed', now()->subDays(20)->toIso8601String()); // outside
        $this->payment($payer, 'pending', null); // not good news yet

        $body = $this->actingAs($user)->getJson('/api/wali/bell-summary')->assertOk();

        $body->assertJsonPath('open_count', 2)
            ->assertJsonPath('overdue_count', 1)
            ->assertJsonPath('outstanding', 850000)
            ->assertJsonPath('receipts_count', 1);
        $this->assertNotNull($body->json('latest_change_at'));
        $this->assertNull($body->json('changed_since'), 'tanpa ?since= tidak mengklaim apa-apa');
    }

    public function test_since_flags_whether_anything_moved_after_the_marker(): void
    {
        $student = $this->student();
        $user = $this->guardianFor($student);

        $this->bill($student, 'unpaid', 650000);

        // urlencode: the ISO timestamp carries a +00:00 that a raw URL
        // would turn into a space.
        $before = urlencode(now()->subDay()->toIso8601String());
        $after = urlencode(now()->addDay()->toIso8601String());

        $this->actingAs($user)->getJson("/api/wali/bell-summary?since={$before}")
            ->assertOk()->assertJsonPath('changed_since', true);

        $this->actingAs($user)->getJson("/api/wali/bell-summary?since={$after}")
            ->assertOk()->assertJsonPath('changed_since', false);
    }

    public function test_a_fully_paid_family_with_a_recent_receipt_still_gets_a_summary(): void
    {
        // The endpoint's flagship scenario, which used to 500: zero open
        // bills, so the signature's only input is the raw-string payment
        // max() - calling toIso8601String() on it fataled, the frontend
        // swallowed the 500, and the bell silently vanished for a week
        // after every full payoff.
        $student = $this->student();
        $user = $this->guardianFor($student);
        $payer = Guardian::where('user_id', $user->id)->first();

        $this->payment($payer, 'completed', now()->subDays(2)->toIso8601String());

        $this->actingAs($user)->getJson('/api/wali/bell-summary')->assertOk()
            ->assertJsonPath('open_count', 0)
            ->assertJsonPath('overdue_count', 0)
            ->assertJsonPath('outstanding', 0)
            ->assertJsonPath('receipts_count', 1);
        $this->assertNotNull(
            $this->actingAs($user)->getJson('/api/wali/bell-summary')->json('latest_change_at'),
        );
    }

    public function test_another_familys_money_never_reaches_the_summary(): void
    {
        $mine = $this->student('Anak Sendiri');
        $me = $this->guardianFor($mine);

        $stranger = $this->student('Anak Orang Lain');
        $strangerUser = $this->guardianFor($stranger);
        $strangerPayer = Guardian::where('user_id', $strangerUser->id)->first();

        $this->bill($mine, 'unpaid', 100000);
        $this->bill($stranger, 'overdue', 999999, now()->subDay()->toDateString());
        $this->payment($strangerPayer, 'completed', now()->toIso8601String());

        $this->actingAs($me)->getJson('/api/wali/bell-summary')->assertOk()
            ->assertJsonPath('open_count', 1)
            ->assertJsonPath('overdue_count', 0)
            ->assertJsonPath('outstanding', 100000)
            ->assertJsonPath('receipts_count', 0);
    }
}
