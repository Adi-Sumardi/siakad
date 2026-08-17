<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The idempotent inbox for everything arriving from outside: the PMB handoff
     * today, Xendit callbacks in Fase 2.
     *
     * Networks redeliver. Without the unique `event_id`, one retried batch of
     * announcements would create duplicate students and send a second account
     * invitation to families who already have one.
     */
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->enum('source', ['pmb', 'xendit']);
            $table->string('event_type');
            $table->string('event_id')->unique();

            $table->json('payload');

            $table->enum('status', ['received', 'processed', 'failed'])->default('received');
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['source', 'status']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};
