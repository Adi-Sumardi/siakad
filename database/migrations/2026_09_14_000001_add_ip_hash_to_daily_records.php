<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Layer 5 of the gate's anti-fraud stack (DESAIN-PRESENSI-HARIAN.md §6):
     * a student can clear browser data to shake off the device-once hash, but
     * every check-in from the same school WiFi still egresses from the same
     * IP - hashed, never stored raw, exactly like device_hash. The TU board
     * groups by it to flag "several NIS from one network" for a human look.
     */
    public function up(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            $table->string('ip_hash', 64)->nullable()->after('device_hash');
        });
    }

    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            $table->dropColumn('ip_hash');
        });
    }
};
