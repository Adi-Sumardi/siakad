<?php

namespace App\Console\Commands;

use App\Models\Bill;
use App\Services\Billing\BillReminderSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The daily nudge run.
 *
 * Scans open bills, works out which beat each one is on today, and sends at
 * most one message per bill per beat per channel. Running it twice in a day
 * sends nothing the second time - bill_reminders has a unique index on
 * (bill_id, kind, channel), and that constraint is what makes the schedule
 * safe rather than a promise that the job only fires once. The sender itself
 * re-reads each bill's status from the database right before dispatching, so
 * a bill paid between the SELECT and the send is skipped, not nagged.
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
        // The open() scope here is the first status check; the sender's fresh
        // re-read inside send() is the authoritative one.
        $bills = Bill::query()
            ->open()
            ->whereBetween('due_date', [now()->subDays(4)->toDateString(), now()->addDays(8)->toDateString()])
            ->with(['student.guardians', 'feeType'])
            ->get();

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($bills as $bill) {
            try {
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
            } catch (\Throwable $e) {
                // One bill blowing up must not take the run down with it - the
                // other families still need their reminders today.
                $this->error("[Reminder] {$bill->bill_number} gagal diproses: {$e->getMessage()}");
                Log::warning('[Reminder] Bill gagal diproses, lanjut ke tagihan berikutnya', [
                    'bill' => $bill->bill_number,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        if ($this->option('dry-run')) {
            $this->info("{$sent} pengingat akan dikirim. Tidak ada yang dikirim (dry run).");

            return self::SUCCESS;
        }

        $summary = "{$sent} tagihan teringatkan, {$skipped} dilewati (lunas, sudah pernah dikirim, atau tanpa kontak)";
        $summary .= $failed > 0 ? ", {$failed} gagal diproses." : '.';

        $this->info($summary);

        return self::SUCCESS;
    }
}
