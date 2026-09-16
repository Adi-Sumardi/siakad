<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Adds 'billing_api' to the source check. Nothing writes that source
         * yet - the e-SPP callback settles straight through PaymentAllocator
         * (idempotent on payments.external_transaction_id) - but the inbox is
         * the designated home for anything arriving from outside, and on
         * PostgreSQL a row the constraint does not know about is rejected
         * outright. Adding the value now keeps the door open without waiting
         * for the first INSERT to fail in production.
         */
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE integration_events DROP CONSTRAINT IF EXISTS integration_events_source_check');
            DB::statement("ALTER TABLE integration_events ADD CONSTRAINT integration_events_source_check CHECK (source::text = ANY (ARRAY['pmb'::character varying, 'xendit'::character varying, 'sendagopay'::character varying, 'billing_api'::character varying]::text[]))");
        } else {
            // SQLite (tests, dev): the column is already a plain string since
            // 2026_08_20_000030, so there is no check to widen - no-op change
            // kept so the schema state stays comparable across drivers.
            Schema::table('integration_events', function (Blueprint $table) {
                $table->string('source')->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE integration_events DROP CONSTRAINT IF EXISTS integration_events_source_check');
            DB::statement("ALTER TABLE integration_events ADD CONSTRAINT integration_events_source_check CHECK (source::text = ANY (ARRAY['pmb'::character varying, 'xendit'::character varying, 'sendagopay'::character varying]::text[]))");
        } else {
            Schema::table('integration_events', function (Blueprint $table) {
                $table->string('source')->change();
            });
        }
    }
};
