<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Answers "the parent says they never got the invitation email".
         * Without a row per send attempt, that question has no answer except
         * asking the gateway's support desk.
         */
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->enum('channel', ['email', 'whatsapp']);
            $table->string('template');
            $table->string('recipient');
            $table->json('payload')->nullable();

            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->nullableMorphs('notifiable');

            $table->timestamps();

            $table->index(['channel', 'status']);
            $table->index('recipient');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->nullableMorphs('subject');

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('notification_logs');
    }
};
