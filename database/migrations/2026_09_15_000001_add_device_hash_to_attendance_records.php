<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The per-lesson device-once rule (the mapel counterpart of
     * daily_records' T14 anti-fraud layer 3): one live self check-in per
     * device per session, so a single phone cannot check in a friend's NIS.
     * The partial unique index is raw SQL for the same T5 reason as the
     * daily tables - Laravel's unique()->where() is silently ignored by both
     * the SQLite and Postgres grammars, and this constraint IS the rule.
     */
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            // A sha256 of the browser's localStorage device id, never the raw
            // identifier - same shape and reasoning as daily_records.
            $table->string('device_hash', 64)->nullable()->after('source');
        });

        DB::statement(
            'CREATE UNIQUE INDEX attendance_records_one_live_mark_per_device'
            .' ON attendance_records (attendance_session_id, device_hash)'
            .' WHERE record_status = \'recorded\' AND device_hash IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attendance_records_one_live_mark_per_device');

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn('device_hash');
        });
    }
};
