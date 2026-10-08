<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the recon needs about a paid VA (2026-10-07, same as PMB): which bank
 * it reached, the VA number that was paid, and the bank's own reference -
 * the number on the bank statement. Kept in columns because settlement
 * replaces gateway_response with e-SPP's poll response, which drops the VA
 * number and bank the payment was opened with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('paid_bank', 10)->nullable()->after('status');
            $table->string('paid_va', 30)->nullable()->after('paid_bank');
            $table->string('paid_reference', 40)->nullable()->after('paid_va');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['paid_bank', 'paid_va', 'paid_reference']);
        });
    }
};
