<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();

            $table->enum('status', ['active', 'promoted', 'repeated', 'left', 'graduated'])->default('active');
            $table->date('joined_on');
            $table->date('left_on')->nullable();

            // Attendance rollups, written by the Fase 3 attendance module. Kept
            // here rather than recomputed per report: a homeroom teacher opens
            // this list daily and it must not scan a year of attendance rows.
            $table->smallInteger('absent_count')->default(0);
            $table->smallInteger('sick_count')->default(0);
            $table->smallInteger('permit_count')->default(0);

            $table->timestamps();

            // One room per student per year. This is what turns "kenaikan kelas"
            // into an insert for the new year instead of an update that would
            // erase which class they were in last year.
            $table->unique(['student_id', 'academic_year_id']);
            $table->index(['classroom_id', 'status']);
        });

        Schema::create('student_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            // One generic table rather than akta_path / kk_path / ijazah_path
            // columns on students - the pattern PMB settled on in its v2 schema.
            $table->enum('document_type', [
                'akta', 'kk', 'ijazah', 'foto', 'rapor_sebelumnya', 'kip', 'lainnya',
            ]);

            $table->string('file_path');
            $table->string('file_name');
            $table->integer('file_size')->nullable();
            $table->string('mime')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['student_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
        Schema::dropIfExists('enrollments');
    }
};
