<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->string('year')->unique();   // "2026/2027"
            $table->date('starts_on');
            $table->date('ends_on');

            // Exactly one row should carry this. Postgres could enforce it with
            // a partial unique index, but SQLite (used by the test suite) would
            // then need a different migration path for the same rule, so it is
            // enforced in AcademicYear::activate() instead - one method, one
            // transaction, both drivers.
            $table->boolean('is_active')->default(false);

            $table->timestamps();
        });

        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->enum('name', ['ganjil', 'genap']);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_active')->default(false);

            $table->timestamps();

            $table->unique(['academic_year_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms');
        Schema::dropIfExists('academic_years');
    }
};
