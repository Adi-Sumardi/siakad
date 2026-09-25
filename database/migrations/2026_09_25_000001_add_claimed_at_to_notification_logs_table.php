<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The delivery-claim marker (audit T64-b): taking ownership of a
        // send is one conditional UPDATE on this column, so two jobs can
        // never both physically deliver one row. A plain nullable column
        // rather than a new enum STATUS value ('sending') on purpose - the
        // status column carries a CHECK constraint, and changing its value
        // list would mean rebuilding the table on both drivers. A claim is
        // cleared when its job writes an outcome (sent/failed) and goes
        // stale on its own after 30 minutes if the worker died hard.
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropColumn('claimed_at');
        });
    }
};
