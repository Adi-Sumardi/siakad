# 06 — Roadmap

Urutannya ditentukan satu hal: **tanggal siswa baru melunasi uang pangkal.** Sejak
hari itu, keluarga sudah menunggu akunnya, dan bulan berikutnya SPP pertama harus
bisa ditagih. Modul lain menunggu.

## Fase 1 — Handoff & akun (prasyarat semua)

Tanpa ini tidak ada satu pun data siswa di aplikasi.

- Scaffolding Laravel + Next.js + Docker, meniru struktur PMB
- Migration kelompok A & B (auth, unit, tahun ajaran, kelas, siswa, wali, enrollment)
- Endpoint webhook `/api/webhooks/pmb/students` + `integration_events` (idempoten)
- Di sisi PMB: auto-`enrolled` saat uang pangkal lunas + job `HandoffStudentToSchoolApp`
- `account_invitations` + email/WA undangan lewat Sendago
- Halaman login & aktivasi, dashboard wali minimal ("anak Anda terdaftar")
- Command backfill untuk yang sudah lunas sebelum aplikasi hidup

**Selesai berarti:** satu siswa uji yang lunas di PMB muncul di sini, walinya
menerima email, bisa aktivasi, dan bisa login.

## Fase 2 — Keuangan (nilai utamanya)

- Migration kelompok D lengkap
- Master tarif: `fee_types`, `fee_rates`, `fee_components`, beasiswa
- Generator tagihan + `billing_runs` + preview dry-run
- Portal tagihan wali: daftar lintas anak, detail, keranjang, checkout multi-tagihan
- Integrasi Xendit + webhook + `payment_allocations` + `PaymentAllocator`
- Pembayaran manual: unggah bukti, verifikasi admin, catat tunai
- Invoice/kuitansi PDF (`InvoicePdfService` PMB bisa disalin)
- Scheduler: generate SPP, tandai overdue, kirim pengingat
- Laporan tunggakan & penerimaan per unit

**Selesai berarti:** SPP bulan berjalan terbit otomatis, orang tua bisa membayar
beberapa bulan sekaligus, dan admin unit bisa melihat siapa yang menunggak.

## Fase 3 — Kesiswaan

- Katalog `point_rules` + ambang + ledger `point_records`
- Layar guru: catat poin satuan & massal, batalkan dengan alasan
- Portal wali: meteran poin, riwayat, notifikasi saat melewati ambang
- Prestasi: input guru, pengajuan wali, verifikasi admin, unggah sertifikat
- Pengumuman (sekolah/unit/kelas)
- Presensi harian (`attendances`) + rekap di enrollment

## Fase 4 — Akademik & penyempurnaan

- Mata pelajaran, penugasan mengajar, nilai, rapor
- Ekstrakurikuler
- Kenaikan kelas massal antar tahun ajaran
- Ekspor Dapodik
- SSO PMB ↔ Sekolah (bila aplikasi ketiga muncul)
- Aplikasi mobile / PWA notifikasi

## Yang harus dibereskan sebelum produksi

Dua hal terbawa dari PMB dan berlaku di sini juga:

1. **Xendit masih memakai key sandbox.** Harus diganti ke key produksi sebelum
   tagihan SPP asli terbit — kalau tidak, semua pembayaran hanya simulasi.
2. **`public/` adalah Docker volume**, jadi rebuild image tidak memperbarui aset di
   dalamnya; aset baru harus `docker cp` masuk.

Ditambah satu yang khas aplikasi ini:

3. **Uang riil, berulang, banyak keluarga.** Sebelum billing run pertama menyentuh
   produksi, jalankan `--preview` pada seluruh siswa dan cocokkan totalnya dengan
   rekap bendahara secara manual. Salah tarif × 400 siswa adalah 400 percakapan.
