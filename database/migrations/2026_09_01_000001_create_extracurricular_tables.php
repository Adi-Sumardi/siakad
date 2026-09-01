<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * The catalogue of activities - Pramuka, Futsal, Robotik. Scoped per
         * academic year, like Classroom, so a roster resets each year rather
         * than accumulating forever. Null school_unit_id means a school-wide
         * activity, the same null-is-school-wide convention point_rules uses.
         */
        Schema::create('extracurriculars', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('school_unit_id')->nullable()->constrained('school_units')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // Nullable, nullOnDelete - same convention as
            // classrooms.homeroom_teacher_id and class_schedules.teacher_id.
            // Role (guru) is checked in the controller, not the DB.
            $table->foreignId('pembina_id')->nullable()->constrained('users')->nullOnDelete();

            $table->integer('capacity')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['school_unit_id', 'academic_year_id']);
        });

        /**
         * The roster. Modelled on Enrollment (status + joined_on/left_on),
         * not on StudentDiscount's hard-delete-as-revoke - membership history
         * is worth keeping here, and costs nothing to keep.
         */
        Schema::create('extracurricular_members', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('extracurricular_id')->constrained('extracurriculars')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();

            $table->enum('status', ['active', 'left'])->default('active');
            $table->date('joined_on');
            $table->date('left_on')->nullable();

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            $table->timestamps();

            $table->index(['student_id', 'academic_year_id', 'status']);
            $table->index(['extracurricular_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extracurricular_members');
        Schema::dropIfExists('extracurriculars');
    }
};
