<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Jenis Beasiswa" (feature batch Poin 13, user's choice b): one
        // column distinguishing what a scheme IS, validated in code with
        // Rule::in - string + default rather than a DB enum, the same
        // pattern achiever_type uses. Existing rows land on 'lainnya'.
        Schema::table('discount_schemes', function (Blueprint $table) {
            $table->string('jenis', 32)->default('lainnya')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('discount_schemes', function (Blueprint $table) {
            $table->dropColumn('jenis');
        });
    }
};
