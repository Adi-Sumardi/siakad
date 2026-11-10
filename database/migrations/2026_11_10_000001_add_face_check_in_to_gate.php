<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gate check-in by face + NIS (docs/09-ABSEN-WAJAH-GERBANG.md).
 *
 * - The rotating gate QR is retired: a matched face proves who is checking
 *   in, so `qr_required` goes. (Lesson presensi keeps its own QR.)
 * - `face_required` per unit, on by default - SMP/SMA are the gate units.
 * - `student_faces`: one reference face per student, enrolled on that
 *   student's first face check-in. The descriptor is encrypted at rest.
 * - Each self check-in keeps its match distance and a snapshot photo, so
 *   a wali kelas can see who actually stood in front of the camera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_settings', function (Blueprint $table) {
            $table->dropColumn('qr_required');
        });

        Schema::table('daily_settings', function (Blueprint $table) {
            $table->boolean('face_required')->default(true)->after('geo_radius_m');
        });

        Schema::create('student_faces', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            // Encrypted JSON of the 128 floats - never sent back to a browser.
            $table->text('descriptor');
            $table->string('photo_path')->nullable();
            $table->timestamp('enrolled_at');
            $table->timestamps();
        });

        Schema::table('daily_records', function (Blueprint $table) {
            $table->decimal('face_distance', 6, 4)->nullable()->after('ip_hash');
            $table->string('photo_path')->nullable()->after('face_distance');
        });
    }

    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            $table->dropColumn(['face_distance', 'photo_path']);
        });

        Schema::dropIfExists('student_faces');

        Schema::table('daily_settings', function (Blueprint $table) {
            $table->dropColumn('face_required');
        });

        Schema::table('daily_settings', function (Blueprint $table) {
            $table->boolean('qr_required')->default(true);
        });
    }
};
