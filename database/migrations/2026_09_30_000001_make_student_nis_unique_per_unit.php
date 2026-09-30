<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NIS unique per unit instead of across the foundation (2026-09-30): PMB
 * now assigns it per unit on Kelulusan > Murid Diterima, and two units may
 * reuse a number. Safe for the public check-in pages - both resolve a NIS
 * inside one classroom / one unit already (AttendancePresensiController,
 * DailyGateController), as does the student import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['nis']);
            $table->unique(['school_unit_id', 'nis']);
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['school_unit_id', 'nis']);
            $table->unique(['nis']);
        });
    }
};
