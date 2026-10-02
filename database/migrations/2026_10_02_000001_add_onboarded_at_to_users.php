<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wali "Panduan Fitur" tour (2026-10-02) runs automatically once per
 * account - after the welcome splash on a first visit, and again for accounts
 * that were already welcomed before this shipped. Same server-owned shape as
 * welcomed_at: a parent finishing the tour on one phone is not asked again on
 * another, and skipping counts as seeing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('onboarded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarded_at');
        });
    }
};
