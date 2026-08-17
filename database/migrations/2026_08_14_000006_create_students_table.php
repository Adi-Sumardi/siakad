<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Student identity. Deliberately holds no class and no pipeline state:
     * which room a student sits in lives in `enrollments` (one row per academic
     * year), so promoting a year is an insert, not an overwrite that erases
     * where they were last year.
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            // Set for everyone who arrived through the PMB handoff; null for a
            // transfer student an admin enters by hand. Unique, so a redelivered
            // webhook can never create the same child twice.
            $table->string('pmb_student_ulid')->nullable()->unique();
            $table->string('no_pendaftaran')->nullable();

            $table->string('nis')->nullable()->unique();

            // Encrypted at rest with a companion *_hash carrying the real unique
            // constraint. PMB learned this the hard way: a unique index over
            // non-deterministic ciphertext never fires, so two students could
            // hold the same NIK. The hash is deterministic (HMAC-SHA256), so it
            // both constrains and stays searchable.
            $table->text('nisn')->nullable();
            $table->string('nisn_hash', 64)->nullable()->unique();
            $table->text('nik')->nullable();
            $table->string('nik_hash', 64)->nullable()->unique();

            $table->string('nama_lengkap');
            $table->string('nama_panggilan')->nullable();
            $table->enum('jenis_kelamin', ['L', 'P']);
            $table->string('tempat_lahir')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('agama')->nullable();
            $table->string('kewarganegaraan')->nullable();
            $table->string('golongan_darah')->nullable();

            $table->text('alamat_lengkap')->nullable();
            $table->string('rt', 8)->nullable();
            $table->string('rw', 8)->nullable();
            $table->string('kelurahan')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kota_kabupaten')->nullable();
            $table->string('provinsi')->nullable();
            $table->string('kode_pos', 10)->nullable();

            $table->foreignId('school_unit_id')->constrained('school_units')->restrictOnDelete();
            $table->foreignId('entry_year_id')->nullable()->constrained('academic_years')->nullOnDelete();

            // A handoff creates 'active' directly - PMB only hands over students
            // whose uang pangkal is settled. 'prospective' is for a transfer
            // student typed in by an admin before their paperwork is complete;
            // billing only ever touches 'active'.
            $table->enum('status', ['prospective', 'active', 'graduated', 'transferred', 'dropped_out'])
                ->default('prospective');
            $table->text('status_notes')->nullable();
            $table->timestamp('status_changed_at')->nullable();

            $table->string('photo_path')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['school_unit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
