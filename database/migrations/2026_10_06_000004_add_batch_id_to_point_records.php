<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One ULID shared by every row of a bulk entry (audit 6 Okt 2026
        // #10): a wrong rule applied to a whole line of students used to be
        // undone one revoke at a time. Null for single entries and history.
        Schema::table('point_records', function (Blueprint $table) {
            $table->string('batch_id', 26)->nullable()->after('related_achievement_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('point_records', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
