<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors PMB's own `announcements` table and its scoping rule, extended
     * with one more level: a classroom. Both scope columns nullable, read
     * narrowest-first - classroom set is one room, unit set (classroom null) is
     * one unit, both null is the whole school.
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('school_unit_id')->nullable()->constrained('school_units')->cascadeOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms')->cascadeOnDelete();

            $table->string('title');
            $table->text('body');

            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->integer('file_size')->nullable();

            $table->boolean('is_pinned')->default(false);
            // Nullable/future-dated so an admin can draft or schedule one -
            // the controller decides the value, never the raw request.
            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['school_unit_id', 'classroom_id']);
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
