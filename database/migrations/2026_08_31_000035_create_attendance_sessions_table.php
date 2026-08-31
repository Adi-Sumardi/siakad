<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * One "roll call window" opened by a teacher for one lesson period on
         * one day. `token` is a capability-token, not a Sanctum session -
         * students never log in, so possessing a valid, unexpired token
         * (via the QR or the classroom card-reader device) is the only
         * authorization the public check-in endpoints require, same
         * unauthenticated-but-token-gated shape as the `invitations` routes.
         * `expires_at` is the schedule's own end_time on `occurred_on`, so a
         * QR goes stale the moment the lesson period ends with no scheduled
         * job needed to close it.
         */
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('class_schedule_id')->constrained('class_schedules')->cascadeOnDelete();
            $table->date('occurred_on');

            $table->string('token', 64)->unique();
            $table->enum('status', ['open', 'closed'])->default('open');

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('expires_at');

            $table->timestamps();

            $table->index(['class_schedule_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }
};
