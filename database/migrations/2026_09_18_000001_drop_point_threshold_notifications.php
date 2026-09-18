<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * The guardian notification is gone - email and WhatsApp both - so its
         * bookkeeping goes with it. Thresholds themselves stay: they still
         * bracket balances into bands for badges and the pembinaan note, they
         * just no longer promise anyone a message when a balance crosses one.
         */
        Schema::dropIfExists('point_threshold_notifications');

        Schema::table('point_thresholds', function (Blueprint $table) {
            $table->dropColumn('notify_guardian');
        });
    }

    public function down(): void
    {
        Schema::table('point_thresholds', function (Blueprint $table) {
            $table->boolean('notify_guardian')->default(true);
        });

        Schema::create('point_threshold_notifications', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();
            $table->foreignId('point_threshold_id')->constrained('point_thresholds')->cascadeOnDelete();
            $table->integer('balance_at_notification');
            $table->timestamp('notified_at');

            $table->timestamps();

            $table->unique(['student_id', 'term_id', 'point_threshold_id']);
        });
    }
};
