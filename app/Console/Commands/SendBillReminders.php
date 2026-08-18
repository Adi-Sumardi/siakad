<?php

namespace App\Console\Commands;

use App\Models\Bill;
use App\Services\Billing\BillReminderSender;
use Illuminate\Console\Command;

/**
 * The daily nudge run.
 *
 * Scans open bills, works out which beat each one is on today, and sends at
 * most one message per bill per beat. Running it twice in a day sends nothing
 * the second time - bill_reminders has a unique index on (bill_id, kind), and
 * that constraint is what makes the schedule safe rather than a promise that
 * the job only fires once.
 */
class SendBillReminders extends Command
{
    protected $signature = 'bills:send-reminders
                            {--dry-run : Tampilkan siapa yang akan dikirimi, tanpa mengirim}';

    protected $description = 'Kirim pengingat jatuh tempo H-7, H-1, dan H+3';

    public function handle(BillReminderSender $sender): int
    {
        // Only bills whose due date is near one of the beats are worth loading;
        // a school with four years of history has far more bills than families.
        $bills = Bill::query()
            ->open()
            ->whereBetween('due_date', [now()->subDays(4)->toDateString(), now()->addDays(8)->toDateString()])
            ->with(['student.guardians', 'feeType'])
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($bills as $bill) {
            $kind = $sender->kindFor($bill);

            if (! $kind) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  {$kind}: {$bill->bill_number} — {$bill->student->nama_lengkap}");
                $sent++;

                continue;
            }

            $sender->send($bill, $kind) ? $sent++ : $skipped++;
        }

        $this->option('dry-run')
            ? $this->info("{$sent} pengingat akan dikirim. Tidak ada yang dikirim (dry run).")
            : $this->info("{$sent} pengingat terkirim, {$skipped} dilewati (sudah pernah dikirim atau tanpa kontak).");

        return self::SUCCESS;
    }
}
