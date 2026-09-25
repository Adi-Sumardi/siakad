<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Structured announcement targeting (feature batch Poin 8): one row
        // per targeted jenjang. The legacy school_unit_id/classroom_id
        // columns on announcements stay the canonical representation for
        // those two scopes (every existing reader keeps working untouched);
        // this pivot holds the kinds the old columns cannot - today
        // kind='jenjang' with the Jenjang ladder key as value, open for
        // 'unit'/'classroom' multi-values later if the school ever wants
        // them.
        Schema::create('announcement_targets', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('value', 32);

            $table->timestamps();

            $table->unique(['announcement_id', 'kind', 'value']);
            $table->index(['kind', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_targets');
    }
};
