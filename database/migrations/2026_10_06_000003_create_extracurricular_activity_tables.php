<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit 6 Okt 2026 #8: an ekskul was membership only - no record of
        // who came to practice and nothing for the rapor. A meeting is one
        // practice day; one row per (meeting, member) says how each member
        // was marked; an assessment is the pembina's per-term predikat.
        Schema::create('extracurricular_meetings', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('extracurricular_id')->constrained('extracurriculars')->cascadeOnDelete();
            $table->date('date');
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['extracurricular_id', 'date']);
        });

        Schema::create('extracurricular_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extracurricular_meeting_id')->constrained('extracurricular_meetings')->cascadeOnDelete();
            $table->foreignId('extracurricular_member_id')->constrained('extracurricular_members')->cascadeOnDelete();
            $table->enum('status', ['hadir', 'sakit', 'izin', 'alpa']);
            $table->timestamps();

            $table->unique(['extracurricular_meeting_id', 'extracurricular_member_id'], 'ekskul_att_meeting_member_unique');
        });

        Schema::create('extracurricular_assessments', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('extracurricular_member_id')->constrained('extracurricular_members')->cascadeOnDelete();
            $table->foreignId('term_id')->constrained('terms')->cascadeOnDelete();
            $table->enum('predikat', ['A', 'B', 'C', 'D']);
            $table->string('keterangan', 500)->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['extracurricular_member_id', 'term_id'], 'ekskul_assessment_member_term_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extracurricular_assessments');
        Schema::dropIfExists('extracurricular_attendances');
        Schema::dropIfExists('extracurricular_meetings');
    }
};
