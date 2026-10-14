<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A semester holds many assignments (Tugas 1, Tugas 2, ...), not one. Each
 * assignment is a gradebook column for one (classroom, subject, term); its
 * per-student scores live in grade_assignment_scores. The single
 * grades.category = 'tugas' row stays the figure rapor, watchlist and
 * weighting read - GradeService rewrites it as the average of the
 * assignment scores whenever they change, so nothing downstream moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_assignments', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();

            $table->string('title', 100);
            $table->date('assigned_on')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['classroom_id', 'subject_id', 'term_id']);
        });

        Schema::create('grade_assignment_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('grade_assignment_id')->constrained('grade_assignments')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->decimal('score', 5, 2);

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['grade_assignment_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_assignment_scores');
        Schema::dropIfExists('grade_assignments');
    }
};
