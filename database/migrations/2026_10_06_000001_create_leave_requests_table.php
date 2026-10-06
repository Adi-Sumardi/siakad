<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A guardian's izin/sakit notice (audit 6 Okt 2026 #3). Until now the
        // only lane was a phone call to TU, and the morning sweep had already
        // written alpa by the time anyone heard. A pending row changes
        // nothing; an approved one marks the covered days' masuk windows
        // (past/today on approval, future ones at close-time instead of alpa).
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('school_unit_id')->constrained('school_units')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('type', ['sakit', 'izin']);
            $table->date('date_from');
            $table->date('date_to');
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();

            $table->timestamps();

            $table->index(['school_unit_id', 'status']);
            $table->index(['student_id', 'status', 'date_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
