<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Brings payment_allocations in line with the every-table-has-a-ULID
         * convention. The allocation rows themselves are never addressed from
         * the frontend (you address the payment or the bill), but the column
         * is how every other ledger-ish table is keyed, and an export or
         * support query that joins across them wants one shape, not "all
         * except that one".
         *
         * Nullable plus backfill rather than NOT NULL: ALTER ADD COLUMN NOT
         * NULL without a default is rejected on a table that already holds
         * production rows, and generating ULIDs inside a single DEFAULT is not
         * portable. Rows inserted after this keep getting a ULID from the
         * model's HasUlidKey; the column stays nullable so any raw insert
         * path that misses the model does not start failing payments.
         */
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->ulid()->nullable();
        });

        // Ordered by id so backfilled ULIDs sort in the same order the rows
        // were written - a ULID's first bytes are a timestamp, so id order and
        // ULID order agree for the backfill too.
        DB::table('payment_allocations')
            ->whereNull('ulid')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('payment_allocations')
                        ->where('id', $row->id)
                        ->update(['ulid' => (string) Str::ulid()]);
                }
            });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->unique('ulid');
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropUnique('payment_allocations_ulid_unique');
            $table->dropColumn('ulid');
        });
    }
};
