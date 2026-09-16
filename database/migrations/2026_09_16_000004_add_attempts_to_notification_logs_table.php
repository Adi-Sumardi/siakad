<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Retry bookkeeping for the failed-notification sweep
         * (notifications:retry-failed). The initial send counts as attempt 1,
         * hence default 1 - which also means every failed row that already
         * exists retroactively gets its two automatic retries the first time
         * the sweep runs. Same-row updates only: a retry never inserts a
         * second row for one delivery, so the log stays one row per
         * notification whatever happens to it afterwards.
         */
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(1);

            // Serves the sweep's pickup query (status='failed' AND
            // attempts < cap) and the failure-count aggregates alike.
            $table->index(['status', 'attempts']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex(['status', 'attempts']);
            $table->dropColumn('attempts');
        });
    }
};
