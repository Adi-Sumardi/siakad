<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the pre-merge welcome_shown_at flag: its overlay and endpoint lost
 * to main's welcomed_at splash in merge 899fe4d, and the column has been
 * written never since. Guarded so a fresh SQLite run (add, then drop) and a
 * long-lived database that somehow lost the column both stay safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'welcome_shown_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('welcome_shown_at');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'welcome_shown_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('welcome_shown_at')->nullable()->after('last_login_at');
            });
        }
    }
};
