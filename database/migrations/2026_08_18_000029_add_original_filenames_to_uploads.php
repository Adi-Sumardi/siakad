<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The path a file is stored under is a random, unguessable string - that's
 * the point of it. But it also means every download so far has been handed
 * to the browser under that same random name, because nothing kept the name
 * the uploader actually gave it. Announcements already did: this brings
 * achievements and point evidence in line with that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('achievements', function (Blueprint $table) {
            $table->string('sertifikat_name')->nullable()->after('sertifikat_path');
            $table->string('foto_kegiatan_name')->nullable()->after('foto_kegiatan_path');
        });

        Schema::table('point_records', function (Blueprint $table) {
            $table->string('evidence_name')->nullable()->after('evidence_path');
        });
    }

    public function down(): void
    {
        Schema::table('achievements', function (Blueprint $table) {
            $table->dropColumn(['sertifikat_name', 'foto_kegiatan_name']);
        });

        Schema::table('point_records', function (Blueprint $table) {
            $table->dropColumn('evidence_name');
        });
    }
};
