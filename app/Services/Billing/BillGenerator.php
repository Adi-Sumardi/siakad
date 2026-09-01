<?php

namespace App\Services\Billing;

use App\Models\AcademicYear;
use App\Models\Bill;
use App\Models\BillingRun;
use App\Models\BillLine;
use App\Models\ExtracurricularMember;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\SchoolUnit;
use App\Models\Student;
use App\Models\StudentDiscount;
use App\Models\StudentFeeSelection;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issues bills for a whole cohort at once.
 *
 * Two entry points over the same logic: preview() reports what would happen and
 * writes nothing, run() does it. They must not drift, so run() is preview()
 * plus the writes - an admin who was shown 312 bills gets 312 bills.
 *
 * A mistake here is not one wrong screen, it is several hundred families billed
 * the wrong amount, which is why nothing is guessed: a student with no matching
 * rate is skipped and named, not charged a default.
 */
class BillGenerator
{
    /**
     * @return array{
     *   eligible: int, total_amount: float, discount_amount: float,
     *   skipped: list<array{student: string, kelas: string|null, reason: string, detail: string}>
     * }
     */
    public function preview(
        FeeType $type,
        AcademicYear $year,
        ?SchoolUnit $unit = null,
        ?int $month = null,
        ?Term $term = null,
        ?Carbon $dueDate = null,
    ): array {
        $eligible = 0;
        $totalAmount = 0.0;
        $totalDiscount = 0.0;
        $skipped = [];

        foreach ($this->candidates($unit) as $student) {
            $outcome = $this->evaluate($student, $type, $year, $month, $term, $dueDate);

            if (isset($outcome['reason'])) {
                $skipped[] = [
                    'student' => $student->nama_lengkap,
                    'kelas' => $outcome['kelas'],
                    'reason' => $outcome['reason'],
                    'detail' => $outcome['detail'],
                ];

                continue;
            }

            $eligible++;
            $totalAmount += $outcome['total'];
            $totalDiscount += $outcome['discount'];
        }

        return [
            'eligible' => $eligible,
            'total_amount' => round($totalAmount, 2),
            'discount_amount' => round($totalDiscount, 2),
            'skipped' => $skipped,
        ];
    }

    public function run(
        FeeType $type,
        AcademicYear $year,
        ?SchoolUnit $unit = null,
        ?int $month = null,
        ?Term $term = null,
        ?Carbon $dueDate = null,
        ?User $actor = null,
    ): BillingRun {
        $run = BillingRun::create([
            'fee_type_id' => $type->id,
            'academic_year_id' => $year->id,
            'term_id' => $term?->id,
            'school_unit_id' => $unit?->id,
            'period_month' => $month,
            'status' => 'running',
            'run_by' => $actor?->id,
            'started_at' => now(),
        ]);

        $created = 0;
        $skipped = [];
        $total = 0.0;

        try {
            foreach ($this->candidates($unit) as $student) {
                $outcome = $this->evaluate($student, $type, $year, $month, $term, $dueDate);

                if (isset($outcome['reason'])) {
                    $skipped[] = [
                        'student' => $student->nama_lengkap,
                        'kelas' => $outcome['kelas'],
                        'reason' => $outcome['reason'],
                        'detail' => $outcome['detail'],
                    ];

                    continue;
                }

                $bill = $this->issue($student, $type, $year, $outcome, $run, $actor);

                if ($bill->wasRecentlyCreated) {
                    $created++;
                    $total += (float) $bill->total_amount;
                }
            }

            $run->forceFill([
                'status' => 'completed',
                'bills_created' => $created,
                'bills_skipped' => count($skipped),
                'total_amount' => round($total, 2),
                'skipped_detail' => $skipped,
                'finished_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }

        return $run;
    }

    /**
     * Only active students. `prospective` is a transfer whose paperwork is
     * unfinished, and the rest have left - billing either is how a school ends
     * up chasing a family whose child is not there.
     *
     * @return Collection<int, Student>
     */
    private function candidates(?SchoolUnit $unit): Collection
    {
        return Student::query()
            ->active()
            ->when($unit, fn ($q) => $q->where('school_unit_id', $unit->id))
            ->with(['enrollments.classroom', 'schoolUnit'])
            ->orderBy('nama_lengkap')
            ->get();
    }

    /**
     * Decides what this student would be billed, or why they would not be.
     *
     * @return array{reason?: string, detail?: string, kelas: string|null, rate?: FeeRate, subtotal?: float, discount?: float, total?: float, dedup?: string, due?: Carbon}
     */
    private function evaluate(
        Student $student,
        FeeType $type,
        AcademicYear $year,
        ?int $month,
        ?Term $term,
        ?Carbon $dueDate,
    ): array {
        $enrollment = $student->currentEnrollment();
        $kelas = $enrollment?->classroom?->name;
        $base = ['kelas' => $kelas];

        $rate = FeeRate::resolve($type, $student, $year, $enrollment?->classroom?->tingkat);

        if (! $rate) {
            return $base + [
                'reason' => 'Tarif belum ada',
                'detail' => $type->name.' untuk '.($student->schoolUnit?->label ?? 'unit ini')
                    .($kelas ? " kelas {$kelas}" : '').' TA '.$year->year,
            ];
        }

        $selection = null;

        if ($type->requires_selection) {
            // A type flagged this way - seragam is the one in the dev seed -
            // means a family chooses which items and sizes apply to them
            // before this can charge anything. Billing everyone the full
            // bundle would be a guess dressed up as a bill, so this only
            // proceeds once a submitted, not-yet-locked StudentFeeSelection
            // exists for this exact (student, rate) - see FeeSelectionService.
            $selection = StudentFeeSelection::where('student_id', $student->id)
                ->where('fee_rate_id', $rate->id)
                ->whereNotNull('submitted_at')
                ->whereNull('locked_at')
                ->first();

            if (! $selection) {
                return $base + [
                    'reason' => 'Menunggu pemilihan orang tua',
                    'detail' => $type->name.' membutuhkan pemilihan item/ukuran - orang tua belum mengisinya di portal wali.',
                ];
            }
        }

        if ($type->requires_roster_membership) {
            // ekskul - only students actively rostered into at least one
            // extracurricular this academic year get billed. See
            // ExtracurricularService and the migration that flips this flag.
            $hasActiveMembership = ExtracurricularMember::where('student_id', $student->id)
                ->where('academic_year_id', $year->id)
                ->where('status', 'active')
                ->exists();

            if (! $hasActiveMembership) {
                return $base + [
                    'reason' => 'Belum terdaftar ekstrakurikuler',
                    'detail' => $type->name.' hanya ditagih untuk siswa yang terdaftar aktif di minimal satu ekstrakurikuler.',
                ];
            }
        }

        $dedup = $this->dedupKey($type, $year, $month, $term);

        if (Bill::where('student_id', $student->id)->where('dedup_key', $dedup)->exists()) {
            return $base + [
                'reason' => 'Sudah punya tagihan',
                'detail' => $type->name.($month ? ' bulan '.$this->monthName($month) : '').' sudah terbit',
            ];
        }

        // A selection's total depends on which sizes/items this family
        // chose, so it is summed from their picks rather than read off the
        // rate's flat amount - two families on the same rate can owe
        // different totals.
        $subtotal = $selection
            ? app(FeeSelectionService::class)->computeTotal($selection)
            : (float) $rate->amount;
        $discount = $this->discountFor($student, $type, $year, $dueDate ?? now(), $subtotal);

        return $base + [
            'rate' => $rate,
            'selection' => $selection,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => round($subtotal - $discount, 2),
            'dedup' => $dedup,
            'due' => $this->dueDateFor($rate, $month, $dueDate),
        ];
    }

    private function issue(
        Student $student,
        FeeType $type,
        AcademicYear $year,
        array $outcome,
        BillingRun $run,
        ?User $actor,
    ): Bill {
        /** @var FeeRate $rate */
        $rate = $outcome['rate'];

        // generateNumber() is a plain count()+1 - not atomic, and nothing else
        // in the app is guaranteed to be the only writer touching this fee
        // type/year/month at once (a billing run and a seeder have collided on
        // it before). Retrying on a unique-constraint hit, with a freshly
        // recomputed number each time, is cheap insurance against a crash that
        // would otherwise stop an entire run partway through hundreds of
        // students.
        return retry(3, function () use ($student, $type, $year, $outcome, $run, $actor, $rate) {
            return DB::transaction(function () use ($student, $type, $year, $outcome, $run, $actor, $rate) {
                // firstOrCreate against the unique (student_id, dedup_key): two
                // admins pressing the button at once, or a scheduler that fires
                // twice, end up with one bill either way.
                $bill = Bill::firstOrCreate(
                    ['student_id' => $student->id, 'dedup_key' => $outcome['dedup']],
                    [
                        'bill_number' => Bill::generateNumber($type, $year->year, $run->period_month),
                        'academic_year_id' => $year->id,
                        'term_id' => $run->term_id,
                        'fee_type_id' => $type->id,
                        'fee_rate_id' => $rate->id,
                        'billing_run_id' => $run->id,
                        'period_month' => $run->period_month,
                        'description' => $this->describe($type, $year, $run->period_month),
                        'subtotal' => $outcome['subtotal'],
                        'discount_amount' => $outcome['discount'],
                        'late_fee' => 0,
                        'total_amount' => $outcome['total'],
                        'paid_amount' => 0,
                        'remaining_amount' => $outcome['total'],
                        'status' => 'unpaid',
                        'due_date' => $outcome['due'],
                        'grace_period_end' => $rate->late_fee_grace_days
                            ? $outcome['due']->copy()->addDays($rate->late_fee_grace_days)
                            : null,
                        // Cast, not passed through: a FeeType built in memory
                        // without this key holds null, and the column's default
                        // only applies to rows the database inserts on its own.
                        'allow_installment' => (bool) $type->allow_installment,
                        'issued_at' => now(),
                        'issued_by' => $actor?->id,
                    ]
                );

                if ($bill->wasRecentlyCreated) {
                    if ($outcome['selection'] ?? null) {
                        $this->writeLinesFromSelection($bill, $outcome['selection'], $outcome['discount']);
                        // Locks the selection the moment its bill exists, not
                        // before - a run that fails partway through must not
                        // strand a family locked out of editing a choice
                        // nothing was ever billed for.
                        $outcome['selection']->update(['locked_at' => now()]);
                    } else {
                        $this->writeLines($bill, $rate, $outcome['discount']);
                    }
                }

                return $bill;
            });
        }, 0, fn (\Throwable $e) => $e instanceof \Illuminate\Database\UniqueConstraintViolationException);
    }

    /**
     * The itemisation. A packaged fee expands into its components; a plain one
     * gets a single line. The discount is a line too, negative, so the printed
     * rows always sum to total_amount rather than needing a footnote.
     */
    private function writeLines(Bill $bill, FeeRate $rate, float $discount): void
    {
        $components = $rate->components;
        $order = 0;

        if ($components->isEmpty()) {
            BillLine::create([
                'bill_id' => $bill->id,
                'name' => $bill->description,
                'qty' => 1,
                'unit_price' => $rate->amount,
                'amount' => $rate->amount,
                'sort_order' => $order++,
            ]);
        } else {
            foreach ($components->where('is_optional', false) as $component) {
                BillLine::create([
                    'bill_id' => $bill->id,
                    'fee_component_id' => $component->id,
                    'name' => $component->name,
                    'qty' => $component->default_qty,
                    'unit_price' => $component->amount,
                    'amount' => round((float) $component->amount * $component->default_qty, 2),
                    'sort_order' => $order++,
                ]);
            }
        }

        if ($discount > 0) {
            BillLine::create([
                'bill_id' => $bill->id,
                'name' => 'Potongan / beasiswa',
                'qty' => 1,
                'unit_price' => -$discount,
                'amount' => -$discount,
                'sort_order' => $order,
            ]);
        }
    }

    /**
     * Same shape as writeLines(), but the components and their sizes come
     * from what the family chose (StudentFeeSelection), not from the rate's
     * generic is_optional=false bundle - two families on the same rate can
     * end up with different line items and totals.
     */
    private function writeLinesFromSelection(Bill $bill, StudentFeeSelection $selection, float $discount): void
    {
        $order = 0;

        foreach ($selection->items->where('included', true) as $item) {
            $component = $item->component;

            BillLine::create([
                'bill_id' => $bill->id,
                'fee_component_id' => $component->id,
                'name' => $component->name,
                'qty' => $component->default_qty,
                'unit_price' => $component->amount,
                'amount' => round((float) $component->amount * $component->default_qty, 2),
                'size_option' => $item->size_option,
                'sort_order' => $order++,
            ]);
        }

        if ($discount > 0) {
            BillLine::create([
                'bill_id' => $bill->id,
                'name' => 'Potongan / beasiswa',
                'qty' => 1,
                'unit_price' => -$discount,
                'amount' => -$discount,
                'sort_order' => $order,
            ]);
        }
    }

    /**
     * Every discount in force on the due date, applied to the subtotal.
     *
     * The result is frozen onto the bill. A scheme edited next month must not
     * change an amount a family has already been told to pay.
     */
    private function discountFor(
        Student $student,
        FeeType $type,
        AcademicYear $year,
        Carbon $on,
        float $subtotal,
    ): float {
        $grants = StudentDiscount::with('scheme')
            ->where('student_id', $student->id)
            ->where('academic_year_id', $year->id)
            ->effectiveOn($on)
            ->get();

        $cut = 0.0;

        foreach ($grants as $grant) {
            if ($grant->scheme && $grant->scheme->is_active && $grant->scheme->appliesTo($type, $student)) {
                $cut += $grant->scheme->amountFor($subtotal);
            }
        }

        // Two grants cannot together take off more than the fee itself.
        return round(min($cut, $subtotal), 2);
    }

    private function dueDateFor(FeeRate $rate, ?int $month, ?Carbon $override): Carbon
    {
        if ($override) {
            return $override->copy();
        }

        if ($month && $rate->due_day) {
            $year = (int) now()->year;

            return Carbon::create($year, $month, min($rate->due_day, 28));
        }

        return now()->addDays(14);
    }

    /** `spp:2026-2027:07` - stable for a given student, fee, and period. */
    private function dedupKey(FeeType $type, AcademicYear $year, ?int $month, ?Term $term): string
    {
        $parts = [$type->code, str_replace('/', '-', $year->year)];

        if ($month) {
            $parts[] = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        } elseif ($term) {
            $parts[] = $term->name;
        }

        return implode(':', $parts);
    }

    private function describe(FeeType $type, AcademicYear $year, ?int $month): string
    {
        return $month
            ? "{$type->name} {$this->monthName($month)} ".mb_substr($year->year, 0, 4)
            : "{$type->name} TA {$year->year}";
    }

    private function monthName(int $month): string
    {
        return [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ][$month] ?? (string) $month;
    }
}
