<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time codes for guardian sign-in.
     *
     * Guardians have no password at all: they enter their email or phone, and
     * the code arrives on whichever of the two they used. That removes the
     * whole class of problems a password brings for this audience - forgotten
     * credentials, reset links that expire unread, one password shared across
     * a family and never changed.
     */
    public function up(): void
    {
        Schema::create('login_otps', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // What the guardian typed, normalised. Kept so a code issued for an
            // email can never be redeemed by quoting the phone number, and so
            // the throttle has something to key on.
            $table->string('identifier');
            $table->enum('channel', ['email', 'whatsapp']);

            // Only the hash. A leaked database must not hand anyone a live code,
            // and six digits are trivially readable if stored as they are sent.
            $table->string('code_hash', 64);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            // Guessing budget for a 6-digit code. Without a cap, 1,000,000
            // tries against a 10-minute window is a matter of minutes.
            $table->smallInteger('attempts')->default(0);

            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['identifier', 'consumed_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_otps');
    }
};
