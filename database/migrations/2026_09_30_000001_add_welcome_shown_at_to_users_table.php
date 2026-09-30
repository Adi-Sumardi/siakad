<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The once-per-account welcome splash marker: null means the guardian
        // has not been greeted yet, so every wali - including those already
        // active when this ships - sees the overlay exactly once. Kept as a
        // plain nullable timestamp (same shape as activated_at) so "first
        // write wins" is a single ?? check in the acknowledge endpoint.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('welcome_shown_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('welcome_shown_at');
        });
    }
};
