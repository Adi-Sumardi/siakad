<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_components', function (Blueprint $table) {
            // Admin-defined dropdown values (e.g. "S,M,L,XL" or "6,8,10,12"),
            // not free text - so a size never ends up as "L" in one row and
            // "l"/"Large" in another across a report.
            $table->string('size_options')->nullable()->after('has_size_option');
        });

        /**
         * A family's choice for one fee_rate, before BillGenerator can charge
         * them - fee_types.requires_selection existed since the first fee
         * migration but nothing ever wrote here until this. One row per
         * (student, fee_rate); locked_at is set the moment a bill is issued
         * from it, so a size can never change after the kuitansi is printed.
         */
        Schema::create('student_fee_selections', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('fee_rate_id')->constrained('fee_rates')->cascadeOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            $table->unique(['student_id', 'fee_rate_id']);
        });

        /**
         * One row per component the family decided on: included or not (only
         * meaningful for is_optional components - a required one is always
         * included), and the size chosen if the component has_size_option.
         */
        Schema::create('student_fee_selection_items', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('student_fee_selection_id')->constrained('student_fee_selections')->cascadeOnDelete();
            $table->foreignId('fee_component_id')->constrained('fee_components')->cascadeOnDelete();

            $table->boolean('included')->default(true);
            $table->string('size_option')->nullable();

            $table->timestamps();

            $table->unique(['student_fee_selection_id', 'fee_component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_fee_selection_items');
        Schema::dropIfExists('student_fee_selections');

        Schema::table('fee_components', function (Blueprint $table) {
            $table->dropColumn('size_options');
        });
    }
};
