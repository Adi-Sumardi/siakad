<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitations', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Only the hash is stored. The plaintext token exists in the email
            // and nowhere else, so a database leak hands over no working account
            // links - the same reason Laravel hashes password reset tokens.
            $table->string('token_hash', 64)->unique();

            $table->enum('channel', ['email', 'whatsapp']);
            $table->string('sent_to');
            $table->enum('purpose', ['activation', 'reset'])->default('activation');

            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();

            $table->integer('sent_count')->default(1);
            $table->timestamp('last_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitations');
    }
};
