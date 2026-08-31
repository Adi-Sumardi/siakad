<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * The subject catalogue - "Bahasa Indonesia", "Matematika". Null
         * school_unit_id means school-wide, same convention as
         * point_rules.school_unit_id.
         */
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('school_unit_id')->nullable()->constrained('school_units')->cascadeOnDelete();

            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['school_unit_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
