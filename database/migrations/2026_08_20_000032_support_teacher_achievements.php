<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achievements', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->change();
            $table->string('achiever_type', 20)->default('siswa')->after('id'); // 'siswa' or 'guru'
            $table->foreignId('teacher_user_id')->nullable()->after('student_id')->constrained('users')->nullOnDelete();
            $table->foreignId('school_unit_id')->nullable()->after('teacher_user_id')->constrained('school_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('achievements', function (Blueprint $table) {
            $table->dropForeign(['teacher_user_id']);
            $table->dropForeign(['school_unit_id']);
            $table->dropColumn(['achiever_type', 'teacher_user_id', 'school_unit_id']);
            $table->foreignId('student_id')->nullable(false)->change();
        });
    }
};
