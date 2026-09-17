<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // School-wide calendar closure dates (national holidays, etc.). One
        // row per date: on a holiday no unit's daily attendance sessions are
        // created, and a session already open when the holiday is marked
        // closes quietly - never a mass auto-alpa for a day school was shut.
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->date('date')->unique();
            $table->string('label');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
