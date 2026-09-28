<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Jenis Beasiswa" is free text now (REVISI Poin 11): values like
     * "Beasiswa Yatim Putra/Putri Al-Azhar" need more than the 32 chars
     * the enum-era column was sized for. Widen only - no data changes,
     * the default stays 'lainnya' and every stored value survives as-is.
     */
    public function up(): void
    {
        Schema::table('discount_schemes', function (Blueprint $table) {
            $table->string('jenis', 64)->default('lainnya')->change();
        });
    }

    public function down(): void
    {
        Schema::table('discount_schemes', function (Blueprint $table) {
            $table->string('jenis', 32)->default('lainnya')->change();
        });
    }
};
