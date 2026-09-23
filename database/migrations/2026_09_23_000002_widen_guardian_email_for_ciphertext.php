<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safety net for any database that ran 2026_09_21_000003 before it learned to
 * widen guardians.email itself: an encrypted address outgrows varchar(255)
 * from about 26 characters up, and Postgres rejects the write outright - which
 * would fail every PMB handoff whose guardian has an ordinary-length email.
 * A no-op where the column is already text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardians', function (Blueprint $table) {
            $table->text('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately not narrowed back: existing ciphertext would not fit.
    }
};
