<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('school_unit_id')->constrained('school_units')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();

            $table->smallInteger('tingkat');           // 1-6 SD, 7-9 SMP
            $table->string('name');                    // "1A", "7 Ibnu Sina"

            // nullOnDelete, not cascade: losing the homeroom teacher's account
            // must not delete the class and every enrolment hanging off it.
            $table->foreignId('homeroom_teacher_id')->nullable()->constrained('users')->nullOnDelete();

            $table->smallInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['academic_year_id', 'school_unit_id', 'name']);
            $table->index(['school_unit_id', 'tingkat']);
        });

        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('nip')->nullable();
            $table->string('jabatan')->nullable();
            $table->string('photo_path')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
        Schema::dropIfExists('classrooms');
    }
};
