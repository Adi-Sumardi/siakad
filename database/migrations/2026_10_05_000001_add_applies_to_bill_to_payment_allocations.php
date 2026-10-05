<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * An allocation row is load-bearing well beyond its amount: receipts,
         * the wali payment list, every supersede/void search in CheckoutService
         * and the reminder-VA reuse all read these rows. So an allocation the
         * bill cannot absorb - the double-booked half of two near-simultaneous
         * VA payments for one bill - must stay on the ledger but stop
         * counting: recompute() and the fee-type report sum applies_to_bill
         * rows only, while the payment row carries metadata.overpayment for
         * the refund TU owes the family. Deleting or trimming the row instead
         * (the first design considered) silently detaches the payment from
         * every one of those readers.
         *
         * Default true with no backfill: every allocation that exists today
         * means what it always meant, so historical numbers do not move.
         */
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->boolean('applies_to_bill')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropColumn('applies_to_bill');
        });
    }
};
