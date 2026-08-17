<?php

namespace App\Console\Commands;

use App\Models\Bill;
use Illuminate\Console\Command;

/**
 * Moves bills past their due date into 'overdue'.
 *
 * The status is stored rather than derived on read so admin lists can filter
 * and count on it in SQL; this command is what keeps the stored value honest.
 * Partly-paid bills are included - owing part of it is still owing it.
 */
class MarkOverdueBills extends Command
{
    protected $signature = 'bills:mark-overdue';

    protected $description = 'Tandai tagihan yang lewat jatuh tempo';

    public function handle(): int
    {
        $marked = Bill::query()
            ->whereIn('status', ['unpaid', 'partial'])
            ->whereDate('due_date', '<', now()->startOfDay())
            ->update(['status' => 'overdue']);

        $this->info("{$marked} tagihan ditandai lewat jatuh tempo.");

        return self::SUCCESS;
    }
}
