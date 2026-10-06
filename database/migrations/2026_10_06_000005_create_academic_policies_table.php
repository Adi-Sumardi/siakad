<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Grade weights and watchlist thresholds as data (audit 6 Okt 2026
        // #11-12, T24) - they were constants waiting on a school decision.
        // A NULL school_unit_id row is the school-wide default; a unit row
        // overrides it. With no rows at all, the code's old constants apply,
        // so nothing changes until someone saves a policy.
        Schema::create('academic_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_unit_id')->nullable()->unique()->constrained('school_units')->cascadeOnDelete();

            // Percent, summing to 100 (validated in the request).
            $table->unsignedTinyInteger('weight_tugas');
            $table->unsignedTinyInteger('weight_uts');
            $table->unsignedTinyInteger('weight_uas');

            $table->unsignedTinyInteger('kkm');
            $table->unsignedSmallInteger('alpa_threshold');
            $table->unsignedTinyInteger('grade_drop');

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_policies');
    }
};
