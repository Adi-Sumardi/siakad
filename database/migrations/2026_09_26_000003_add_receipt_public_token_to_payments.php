<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Public receipt token (feature batch Poin 11C): a cryptographically
        // random, per-payment credential so a receipt link works without a
        // login while the transaction ULID never becomes a public handle
        // (it appears in logs and internal URLs). Nullable-unique, the same
        // proven shape as payments.external_transaction_id: minted lazily
        // (only settled payments ever get one), so history stays null until
        // shared. No expiry and no secondary verification - the school's
        // decision, matched by the absen/presensi public-link precedent.
        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_public_token', 64)->nullable()->unique()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('receipt_public_token');
        });
    }
};
