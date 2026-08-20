<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE integration_events DROP CONSTRAINT IF EXISTS integration_events_source_check');
            DB::statement("ALTER TABLE integration_events ADD CONSTRAINT integration_events_source_check CHECK (source::text = ANY (ARRAY['pmb'::character varying, 'xendit'::character varying]::text[]))");
        }
    }
};
