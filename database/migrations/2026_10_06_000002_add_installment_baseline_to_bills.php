<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What was already paid when an installment plan was set (audit
        // 6 Okt 2026 #6). A plan splits the REMAINING balance, so each
        // installment's progress is (paid_amount - baseline) walked across
        // the rows in order - derived on read, never a second ledger that
        // the payment path would have to keep in sync.
        Schema::table('bills', function (Blueprint $table) {
            $table->decimal('installment_baseline', 12, 2)->nullable()->after('allow_installment');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('installment_baseline');
        });
    }
};
