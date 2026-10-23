<?php

/*
 * Dashboard eksekutif (Direktur Pendidikan & BPH) - lihat
 * docs/08-DASHBOARD-EKSEKUTIF.md §5.3.
 *
 * Semua ambang di bawah masih USULAN, belum kebijakan yayasan. Ubah di sini
 * (atau lewat .env) setelah nilai resmi disahkan.
 */
return [

    // Agregasi penuh mahal; sama dengan cache ringkasan Yapinet.
    'cache_seconds' => (int) env('EKSEKUTIF_CACHE_SECONDS', 120),

    'thresholds' => [
        // Kehadiran harian unit, semester berjalan (%).
        'attendance_warn' => 90.0,
        'attendance_crit' => 85.0,

        // SPP terbayar / ditagih, tahun ajaran berjalan (%).
        'collection_warn' => 75.0,
        'collection_crit' => 60.0,

        // Tunggakan > 90 hari dibanding seluruh tunggakan unit (%).
        'old_arrears_warn' => 15.0,
        'old_arrears_crit' => 30.0,

        // Siswa bernilai akhir yang di bawah KKM (%).
        'below_kkm_warn' => 20.0,

        // Siswa aktif dengan catatan pelanggaran semester ini (%).
        'violation_warn' => 10.0,

        // Hari sekolah tanpa presensi sebelum angka kehadiran dianggap belum lengkap.
        'stale_attendance_days' => 2,

        // Nilai akhir baru dibandingkan antar unit bila sekian % siswa sudah punya.
        'grades_coverage_min' => 50.0,
    ],
];
