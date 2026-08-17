<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The school's unit master, mirrored from PMB.
     *
     * `code` - not `label` - is the key the PMB handoff payload carries. PMB
     * pairs units by free text (SchoolUnit::matching() there normalises
     * punctuation and casing to cope with rows stored as "Playgroup PG
     * Sakinah"), which works inside one app but would make an integration
     * silently drop students the day someone renames a unit. A stable code
     * removes that whole class of failure.
     */
    public function up(): void
    {
        Schema::create('school_units', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->string('code')->unique();
            $table->string('label');
            $table->string('jenjang_group')->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_units');
    }
};
