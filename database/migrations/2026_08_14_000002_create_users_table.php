<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->string('name');

            // Both nullable, at least one required - enforced in the request
            // rules, not here, because "either/or" is not a column constraint.
            // A guardian in PG/TK frequently has no email at all, so an email
            // that is NOT NULL would either block their account or invite a
            // placeholder address that bounces every notification we send.
            $table->string('email')->nullable()->unique();
            $table->text('phone')->nullable();
            $table->string('phone_hash', 64)->nullable()->unique();

            $table->timestamp('email_verified_at')->nullable();

            // Nullable until the invitation is accepted: the account exists
            // from the moment PMB hands the student over, but the guardian
            // chooses the password themselves. A password is never generated
            // for them and mailed out - that would leave a working credential
            // sitting in an inbox forever.
            $table->string('password')->nullable();

            $table->enum('role', ['admin', 'admin_unit', 'guru', 'orangtua'])->default('orangtua');

            // Required for admin_unit and guru, null for a central admin.
            $table->foreignId('school_unit_id')->nullable()->constrained('school_units')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index(['role', 'school_unit_id']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
