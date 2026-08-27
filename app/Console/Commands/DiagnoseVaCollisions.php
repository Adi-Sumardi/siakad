<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Student;
use App\Services\Billing\BillingApiClient;
use Illuminate\Console\Command;

/**
 * Read-only. Checks the two ways a Bank Muamalat VA number could end up
 * shared by more than one student or payment:
 *
 * 1. formatStudentCode() used to keep only the last 6 digits of a
 *    student's NIS, which could collide between two different (each
 *    individually unique) NIS values. It's the student's own database id
 *    now - guaranteed unique by the table itself - so this check should
 *    always report none; kept as a standing sanity check on that, and
 *    to surface anything a VA registered before that fix left behind.
 * 2. generateVaNumber() is a pure function of (fee type, unit, academic
 *    year, student) - not of the specific bill or checkout - so a basket
 *    that mixed bills from more than one child registered the combined
 *    amount under only the first student's VA. CheckoutService now
 *    refuses to build such a basket for this gateway; this reports any
 *    payment that was already created that way before that guard existed.
 *
 * Nothing here writes to the database or calls e-SPP.
 */
class DiagnoseVaCollisions extends Command
{
    protected $signature = 'diagnose:va-collisions';

    protected $description = 'Report any existing Bank Muamalat VA number collisions in real data (read-only)';

    public function handle(): int
    {
        $this->checkNisTruncationCollisions();
        $this->checkMultiStudentPayments();

        return self::SUCCESS;
    }

    private function checkNisTruncationCollisions(): void
    {
        $this->info('Checking for students whose VA student-code collides (same trailing 6 digits of NIS)...');

        $codes = Student::query()
            ->whereNotNull('nis')
            ->get(['id', 'ulid', 'nama_lengkap', 'nis', 'school_unit_id'])
            ->groupBy(fn (Student $s) => BillingApiClient::formatStudentCode($s));

        $collisions = $codes->filter(fn ($group) => $group->count() > 1);

        if ($collisions->isEmpty()) {
            $this->info('None found - every student currently has a distinct VA student-code.');

            return;
        }

        $this->error("{$collisions->count()} student-code collision(s) found. These students would be issued the identical Bank Muamalat VA number for the same fee type and academic year:");

        foreach ($collisions as $code => $group) {
            $this->line("  Code {$code}:");
            foreach ($group as $s) {
                $this->line("    - {$s->nama_lengkap} (NIS: {$s->nis}, ulid: {$s->ulid}, unit_id: {$s->school_unit_id})");
            }
        }
    }

    private function checkMultiStudentPayments(): void
    {
        $this->newLine();
        $this->info('Checking for existing payments whose basket spans more than one student...');

        $affected = Payment::query()
            ->has('allocations', '>', 1)
            ->with('allocations.bill.student')
            ->get()
            ->filter(fn (Payment $p) => $p->allocations->pluck('bill.student_id')->unique()->count() > 1);

        if ($affected->isEmpty()) {
            $this->info('None found - no existing payment mixes bills from more than one student.');

            return;
        }

        $this->error("{$affected->count()} payment(s) found whose combined amount was registered under only one child's VA number, while covering another child's bill too:");

        foreach ($affected as $payment) {
            $vaNumber = $payment->gateway_response['va_number'] ?? '(none)';
            $students = $payment->allocations->pluck('bill.student.nama_lengkap')->unique()->join(', ');
            $this->line("  {$payment->payment_number} - VA {$vaNumber} - status {$payment->status} - students: {$students}");
        }
    }
}
