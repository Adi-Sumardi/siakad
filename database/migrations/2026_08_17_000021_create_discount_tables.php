<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /** Beasiswa prestasi, potongan anak kedua, subsidi yatim. */
        Schema::create('discount_schemes', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->string('code')->unique();
            $table->string('name');

            $table->enum('type', ['percent', 'nominal']);
            $table->decimal('value', 12, 2);

            // Null on either means "applies to everything" - a hardship waiver
            // usually covers every fee, a book subsidy only one.
            $table->foreignId('fee_type_id')->nullable()->constrained('fee_types')->nullOnDelete();
            $table->foreignId('school_unit_id')->nullable()->constrained('school_units')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
        });

        /** Who actually holds one, and for how long. */
        Schema::create('student_discounts', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('discount_scheme_id')->constrained('discount_schemes')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->text('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->unique(['student_id', 'discount_scheme_id', 'academic_year_id'], 'student_discounts_unique');
            $table->index(['student_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_discounts');
        Schema::dropIfExists('discount_schemes');
    }
};
