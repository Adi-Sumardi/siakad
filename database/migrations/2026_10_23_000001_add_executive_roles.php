<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dua role yayasan yang hanya membaca: Direktur Pendidikan dan Badan Pengawas
 * Harian (BPH). Keduanya lintas unit (school_unit_id kosong) dan hanya melihat
 * dashboard eksekutif - angka per unit, tanpa data siswa perorangan.
 */
return new class extends Migration
{
    private const BEFORE = ['admin', 'admin_unit', 'guru', 'orangtua', 'waka_kesiswaan', 'kepala_sekolah'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', [...self::BEFORE, 'direktur_pendidikan', 'bph'])
                ->default('orangtua')->change();
        });
    }

    public function down(): void
    {
        // Akun kedua role ini ikut dihapus: enum lama tidak mengenal nilainya,
        // dan memindahkannya ke role lain justru memberi akses yang salah.
        DB::table('users')->whereIn('role', ['direktur_pendidikan', 'bph'])->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', self::BEFORE)->default('orangtua')->change();
        });
    }
};
