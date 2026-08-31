<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\FeeComponent;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\StudentFeeSelection;
use App\Models\User;
use App\Services\Billing\BillGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * requires_selection/has_size_option existed since the first fee migration
 * with nothing reading them - BillGenerator unconditionally skipped every
 * such fee type. This is the missing half: a family submits a
 * StudentFeeSelection, and only then does BillGenerator charge them, for
 * exactly what they chose.
 */
class FeeSelectionTest extends TestCase
{
    use RefreshDatabase;

    private SchoolUnit $unit;

    private AcademicYear $year;

    private FeeType $seragam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = SchoolUnit::create(['code' => 'SD-SAKINAH', 'label' => 'SD Sakinah', 'jenjang_group' => 'sd']);
        $this->year = AcademicYear::create(['year' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->year->activate();

        $this->seragam = FeeType::create([
            'code' => 'seragam', 'name' => 'Seragam & atribut', 'recurrence' => 'once',
            'requires_selection' => true,
        ]);
    }

    private function rateWithComponents(): FeeRate
    {
        $rate = FeeRate::create([
            'fee_type_id' => $this->seragam->id, 'school_unit_id' => $this->unit->id,
            'academic_year_id' => $this->year->id, 'amount' => 0,
        ]);

        FeeComponent::create([
            'fee_rate_id' => $rate->id, 'name' => 'Kemeja Putih', 'amount' => 120000,
            'default_qty' => 1, 'is_optional' => false, 'has_size_option' => true, 'size_options' => 'S,M,L,XL',
        ]);
        FeeComponent::create([
            'fee_rate_id' => $rate->id, 'name' => 'Celana/Rok', 'amount' => 100000,
            'default_qty' => 1, 'is_optional' => false, 'has_size_option' => true, 'size_options' => 'S,M,L,XL',
        ]);
        FeeComponent::create([
            'fee_rate_id' => $rate->id, 'name' => 'Topi', 'amount' => 25000,
            'default_qty' => 1, 'is_optional' => true, 'has_size_option' => false,
        ]);

        return $rate->fresh('components');
    }

    private function student(string $name = 'Aisyah Nur Ramadhani'): Student
    {
        return Student::create([
            'nama_lengkap' => $name, 'jenis_kelamin' => 'P',
            'school_unit_id' => $this->unit->id, 'entry_year_id' => $this->year->id, 'status' => 'active',
        ]);
    }

    /** A guardian with a login, holding the given student. */
    private function guardianFor(Student $student): User
    {
        $user = User::create([
            'name' => 'Budi Ramadhani', 'email' => 'budi'.uniqid().'@example.com',
            'role' => 'orangtua', 'is_active' => true, 'activated_at' => now(),
        ]);

        $guardian = Guardian::create([
            'user_id' => $user->id, 'nama' => 'Budi Ramadhani', 'hubungan' => 'ayah', 'email' => $user->email,
        ]);

        $student->guardians()->attach($guardian->id, [
            'relationship' => 'ayah', 'is_primary' => true, 'is_billing_contact' => true,
        ]);

        return $user;
    }

    private function submitPayload(FeeRate $rate, bool $includeHat = true): array
    {
        return [
            'fee_rate_ulid' => $rate->ulid,
            'items' => $rate->components->map(fn (FeeComponent $c) => [
                'component_ulid' => $c->ulid,
                'included' => $c->name === 'Topi' ? $includeHat : true,
                'size_option' => $c->has_size_option ? 'M' : null,
            ])->all(),
        ];
    }

    public function test_a_guardian_can_submit_a_full_selection_via_the_api(): void
    {
        $rate = $this->rateWithComponents();
        $student = $this->student();
        $guardian = $this->guardianFor($student);

        $this->actingAs($guardian)->postJson(
            "/api/wali/students/{$student->ulid}/fee-selections",
            $this->submitPayload($rate),
        )->assertStatus(201);

        $selection = StudentFeeSelection::where('student_id', $student->id)->firstOrFail();
        $this->assertNotNull($selection->submitted_at);
        $this->assertNull($selection->locked_at);
        $this->assertSame(3, $selection->items()->count());
    }

    public function test_submit_is_rejected_when_a_sized_component_has_no_size(): void
    {
        $rate = $this->rateWithComponents();
        $student = $this->student();
        $guardian = $this->guardianFor($student);

        $payload = $this->submitPayload($rate);
        $payload['items'][0]['size_option'] = null; // Kemeja Putih, has_size_option=true

        $this->actingAs($guardian)->postJson("/api/wali/students/{$student->ulid}/fee-selections", $payload)
            ->assertStatus(422);

        $this->assertDatabaseCount('student_fee_selections', 0);
    }

    public function test_submit_is_rejected_when_the_size_is_outside_the_components_own_list(): void
    {
        $rate = $this->rateWithComponents();
        $student = $this->student();
        $guardian = $this->guardianFor($student);

        $payload = $this->submitPayload($rate);
        $payload['items'][0]['size_option'] = 'XXXL'; // not in "S,M,L,XL"

        $this->actingAs($guardian)->postJson("/api/wali/students/{$student->ulid}/fee-selections", $payload)
            ->assertStatus(422);
    }

    public function test_a_required_component_stays_included_even_if_the_request_says_otherwise(): void
    {
        $rate = $this->rateWithComponents();
        $student = $this->student();
        $guardian = $this->guardianFor($student);

        $payload = $this->submitPayload($rate);
        $payload['items'][0]['included'] = false; // Kemeja Putih is_optional=false

        $this->actingAs($guardian)->postJson("/api/wali/students/{$student->ulid}/fee-selections", $payload)
            ->assertStatus(201);

        $selection = StudentFeeSelection::where('student_id', $student->id)->firstOrFail();
        $kemeja = $selection->items()->whereHas('component', fn ($q) => $q->where('name', 'Kemeja Putih'))->first();
        $this->assertTrue($kemeja->included);
    }

    public function test_bill_generator_still_skips_a_student_with_no_submitted_selection(): void
    {
        $this->rateWithComponents();
        $this->student();

        $preview = app(BillGenerator::class)->preview($this->seragam, $this->year, $this->unit);

        $this->assertSame(0, $preview['eligible']);
        $this->assertSame('Menunggu pemilihan orang tua', $preview['skipped'][0]['reason']);
    }

    public function test_bill_generator_charges_exactly_the_selected_components_once_submitted(): void
    {
        $rate = $this->rateWithComponents();
        $student = $this->student();
        $guardian = $this->guardianFor($student);

        $this->actingAs($guardian)->postJson(
            "/api/wali/students/{$student->ulid}/fee-selections",
            $this->submitPayload($rate, includeHat: true),
        )->assertStatus(201);

        app(BillGenerator::class)->run($this->seragam, $this->year, $this->unit);

        $bill = Bill::where('student_id', $student->id)->firstOrFail();
        // Kemeja 120000 + Celana 100000 + Topi 25000
        $this->assertSame(245000.0, (float) $bill->total_amount);

        $lines = BillLine::where('bill_id', $bill->id)->get();
        $this->assertSame(3, $lines->count());

        $kemeja = $lines->firstWhere('name', 'Kemeja Putih');
        $this->assertSame('M', $kemeja->size_option);
    }

    public function test_an_excluded_optional_component_never_becomes_a_bill_line(): void
    {
        $rate = $this->rateWithComponents();
        $student = $this->student();
        $guardian = $this->guardianFor($student);

        $this->actingAs($guardian)->postJson(
            "/api/wali/students/{$student->ulid}/fee-selections",
            $this->submitPayload($rate, includeHat: false),
        )->assertStatus(201);

        app(BillGenerator::class)->run($this->seragam, $this->year, $this->unit);

        $bill = Bill::where('student_id', $student->id)->firstOrFail();
        $this->assertSame(220000.0, (float) $bill->total_amount); // no Topi
        $this->assertSame(2, BillLine::where('bill_id', $bill->id)->count());
    }

    public function test_the_selection_is_locked_after_a_bill_is_issued_and_further_edits_are_rejected(): void
    {
        $rate = $this->rateWithComponents();
        $student = $this->student();
        $guardian = $this->guardianFor($student);

        $this->actingAs($guardian)->postJson(
            "/api/wali/students/{$student->ulid}/fee-selections",
            $this->submitPayload($rate),
        );

        app(BillGenerator::class)->run($this->seragam, $this->year, $this->unit);

        $selection = StudentFeeSelection::where('student_id', $student->id)->firstOrFail();
        $this->assertNotNull($selection->locked_at);

        $this->actingAs($guardian)->postJson(
            "/api/wali/students/{$student->ulid}/fee-selections",
            $this->submitPayload($rate),
        )->assertStatus(422);
    }

    public function test_a_guardian_cannot_submit_a_selection_for_another_familys_child(): void
    {
        $rate = $this->rateWithComponents();
        $student = $this->student();
        $someoneElse = $this->guardianFor($this->student('Anak Lain'));

        $this->actingAs($someoneElse)->postJson(
            "/api/wali/students/{$student->ulid}/fee-selections",
            $this->submitPayload($rate),
        )->assertStatus(404);
    }

    public function test_a_guardian_cannot_smuggle_a_fee_rate_from_a_different_unit(): void
    {
        $otherUnit = SchoolUnit::create(['code' => 'SMP-SAKINAH', 'label' => 'SMP Sakinah', 'jenjang_group' => 'smp']);
        $foreignRate = FeeRate::create([
            'fee_type_id' => $this->seragam->id, 'school_unit_id' => $otherUnit->id,
            'academic_year_id' => $this->year->id, 'amount' => 0,
        ]);
        FeeComponent::create([
            'fee_rate_id' => $foreignRate->id, 'name' => 'Kemeja', 'amount' => 50000,
            'default_qty' => 1, 'is_optional' => false, 'has_size_option' => false,
        ]);

        $student = $this->student(); // in $this->unit, not $otherUnit
        $guardian = $this->guardianFor($student);

        $this->actingAs($guardian)->postJson(
            "/api/wali/students/{$student->ulid}/fee-selections",
            $this->submitPayload($foreignRate->fresh('components')),
        )->assertStatus(404);
    }
}
