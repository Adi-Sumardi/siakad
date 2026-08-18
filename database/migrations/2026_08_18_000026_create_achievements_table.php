<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            $table->string('nama_prestasi', 200);
            $table->enum('kategori', ['Akademik', 'Non-Akademik', 'Olahraga', 'Seni', 'Lainnya']);
            $table->enum('tingkat', ['Kelas', 'Sekolah', 'Kecamatan', 'Kabupaten/Kota', 'Provinsi', 'Nasional', 'Internasional']);
            $table->enum('juara', ['1', '2', '3', 'Harapan 1', 'Harapan 2', 'Harapan 3', 'Peserta'])->nullable();

            $table->string('nama_event', 200)->nullable();
            $table->string('penyelenggara', 200)->nullable();
            $table->date('tanggal_event')->nullable();
            $table->string('tempat_event', 200)->nullable();

            // Stored on the private disk, served through an authenticated
            // route with the same visibleTo() check every other document in
            // this app goes through - never a public URL.
            $table->string('sertifikat_path', 500)->nullable();
            $table->string('foto_kegiatan_path', 500)->nullable();

            // A row carried over from the PMB registration form arrives
            // pre-verified (source=pmb) and a teacher must not be able to edit
            // what a family originally submitted during registration - only
            // rows recorded here (source=sekolah) are theirs to touch.
            $table->enum('source', ['pmb', 'sekolah'])->default('sekolah');

            // Guru-recorded rows are trusted immediately; a guardian's own
            // submission sits pending until staff confirms it actually
            // happened - the difference between a teacher who was there and a
            // parent's own account of it.
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->smallInteger('point_awarded')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
