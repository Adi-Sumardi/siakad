<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            // Denormalized from class_schedules at write time - a schedule
            // can be reassigned to a different teacher/classroom later, and
            // that must not retroactively rewrite who a past grade was
            // attributed to, same reasoning point_records copies a rule's
            // points at write time rather than trusting a live join.
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();

            $table->enum('category', ['tugas', 'uts', 'uas']);
            $table->decimal('score', 5, 2);
            $table->text('description')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One live score per (student, subject, term, category) - a
            // re-save updates it in place, it does not duplicate. Unlike
            // point_records/attendance_records there is no revoke ledger
            // here: a teacher is always an authenticated, authorized actor
            // correcting their own entry, not an anonymous public check-in,
            // so recorded_by + updated_at is accountability enough for now.
            $table->unique(['student_id', 'subject_id', 'term_id', 'category']);
            $table->index(['classroom_id', 'subject_id', 'term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
