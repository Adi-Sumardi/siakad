<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nobody has a password any more - staff sign in with a one-time code
     * exactly as guardians do.
     *
     * The column goes rather than sitting nullable forever. Nothing has ever
     * written to it here, and a dead column that half the framework still knows
     * how to fill is how an unused login path quietly comes back. Recovery when
     * both gateways are down is `php artisan otp:issue`, which needs shell
     * access to the server - a deliberately higher bar than a password reset.
     *
     * password_reset_tokens goes with it: there is nothing left to reset.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password');
        });

        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email_verified_at');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
