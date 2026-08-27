<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Student;
use App\Services\Billing\BillingApiClient;
use Illuminate\Console\Command;

/**
 * Read-only. Checks the two ways a Bank Muamalat VA number can end up
 * shared by more than one student or payment:
 *
 * 1. formatStudentCode() keeps only the last 6 digits of a student's NIS.
 *    NIS is globally unique in the database, but two different NIS values
 *    can still share the same trailing 6 digits - this reports any pair
 *    that does, without needing to guess at what this school's real NIS
 *    values look like.
 * 2. generateVaNumber() is a pure function of (fee type, unit, academic
 *    year, student) - not of the specific bill or checkout - so a basket
 *    that mixes bills from more than one child (which nothing in checkout
 *    prevents) registers the combined amount under only the first
 *    student's VA. This reports every payment already created that way.
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
