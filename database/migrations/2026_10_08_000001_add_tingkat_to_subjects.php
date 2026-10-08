<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * One subject, many grade levels: "Bahasa Indonesia" is one catalogue
         * row that runs in tingkat 7, 8 and 9, instead of IND7/IND8/IND9.
         * is_active here deactivates the subject for one tingkat only. A
         * subject with no rows at all applies to every tingkat (legacy and
         * school-wide subjects until an admin narrows them).
         */
        Schema::create('subject_tingkat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->smallInteger('tingkat');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['subject_id', 'tingkat']);
        });

        Schema::table('subjects', function (Blueprint $table) {
            // The code is no longer shown or required; existing values stay.
            $table->string('code')->nullable()->change();
            // Set on a duplicate folded into another subject by SubjectMerger.
            $table->foreignId('merged_into_id')->nullable()->after('is_active')->constrained('subjects')->nullOnDelete();
        });

        /** Every row SubjectMerger rewrote, so a merge can be rolled back exactly. */
        Schema::create('subject_merge_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('merged_into_id');
            $table->string('table_name');
            $table->unsignedBigInteger('row_id');
            $table->json('previous')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_merge_log');

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
        });

        Schema::dropIfExists('subject_tingkat');
    }
};
