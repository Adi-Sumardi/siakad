<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * The lesson-period timetable: which subject a classroom has, on
         * which day, taught by which teacher. Academic year is inherited via
         * classroom_id -> classrooms.academic_year_id, not stored again here.
         */
        Schema::create('class_schedules', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();

            // 1 = Senin ... 6 = Sabtu.
            $table->tinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');

            $table->timestamps();

            $table->index(['classroom_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_schedules');
    }
};
