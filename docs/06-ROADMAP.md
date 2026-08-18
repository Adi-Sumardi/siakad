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

## Fase 2 — Keuangan (nilai utamanya) — selesai

- Migration kelompok D lengkap
- Master tarif: `fee_types`, `fee_rates`, `fee_components`, beasiswa
- Generator tagihan (`BillGenerator`) + `billing_runs` + preview dry-run
- Portal tagihan wali: daftar lintas anak, detail, keranjang, checkout multi-tagihan
- Integrasi Xendit + webhook + `payment_allocations` + `PaymentAllocator`
- Pembayaran tunai dicatat admin (`CheckoutService::recordManual`) — **bukan**
  unggah bukti transfer: semua pembayaran online lewat Xendit, jadi tidak ada
  slip untuk difoto. Endpoint verifikasi manual tidak dibangun karena tidak ada
  yang pernah menulis ke sana.
- Invoice/kuitansi PDF (`BillPdfService`, satu template untuk keduanya)
- Scheduler: `bills:generate`, `bills:mark-overdue`, `bills:send-reminders` (H-7/H-1/H+3)
- Laporan tunggakan & penerimaan per unit (`ReportController`)

**Selesai berarti:** SPP bulan berjalan terbit otomatis, orang tua bisa membayar
beberapa bulan sekaligus, dan admin unit bisa melihat siapa yang menunggak.
Terbukti dengan 68 tes lulus dan alur end-to-end diverifikasi langsung
(bukan cuma di test suite).

## Fase 3 — Kesiswaan — selesai (kecuali presensi)

- Katalog `point_rules` + `point_thresholds` + ledger `point_records`
  (`PointLedger` — satu-satunya penulis, revoke bukan delete, lihat D6)
- Layar guru: catat poin satuan & massal (`/api/guru/points`, `points/bulk`),
  batalkan dengan alasan wajib (`points/{ulid}/revoke`)
- Portal wali: meteran poin (`<PointMeter />`), riwayat lengkap per semester,
  notifikasi otomatis saat saldo melewati ambang — sekali per ambang per
  semester (`point_threshold_notifications`, pola sama dengan `bill_reminders`)
- Prestasi: input guru (langsung terverifikasi, boleh sekalian beri poin),
  pengajuan wali (menunggu verifikasi, tidak pernah bawa poin sendiri),
  verifikasi/tolak admin, sertifikat & foto tersimpan privat di balik
  pemeriksaan kepemilikan (`FileController`)
- Pengumuman sekolah/unit/kelas (`Announcement`, pola `scopeLive()` dari PMB,
  ditambah satu level cakupan kelas)
- Scheduler: `points:evaluate-thresholds`

**Selesai berarti:** guru mencatat poin dalam hitungan detik, wali murid tahu di
hari yang sama lewat notifikasi otomatis — bukan setiap hari saldo tetap di
ambang yang sama, hanya sekali. 115 tes lulus, dan alur guru→poin,
prestasi→poin, serta idempotensi notifikasi ambang diverifikasi langsung di
luar test suite.

Frontend admin & guru (10 halaman admin, 3 halaman guru) menyusul setelahnya,
menutup celah `tunggakan` di portal wali (dulu placeholder `null`) sekaligus:
akses baca `fee-types`/`fee-rates` dibuka untuk admin_unit (dulu pusat saja),
endpoint referensi (`school-units`, `academic-years`, `classrooms`) dan ledger
poin per siswa untuk guru (`/api/guru/students/{ulid}/points`) ditambahkan
untuk mendukung layar-layar ini.

**Presensi harian (`attendances`) sengaja tidak dibangun di fase ini.**
`docs/03-ERD.md` sendiri sejak awal mendaftarkan `attendances` di bawah "tabel
fase berikutnya (tidak dibuat sekarang)" — dua dokumen perencanaan ini sempat
tidak sinkron soal fase mana presensi berada. Skemanya belum pernah dirancang
(berbeda dengan poin/prestasi/pengumuman yang sudah punya rancangan ERD utuh
sebelum fase ini dimulai), dan presensi harian adalah fitur besar tersendiri —
input harian per siswa, rekap mingguan/bulanan, laporan per kelas. Dipindah ke
Fase 4, sebagai item pertama.

## Fase 4 — Akademik & penyempurnaan

- Presensi harian (`attendances`) + rekap ke `enrollments.absent_count` dkk. —
  dipindah dari Fase 3, lihat catatan di atas
- Pemilihan item/ukuran per siswa untuk fee type `requires_selection` (mis.
  seragam). Kolomnya (`requires_selection`, `has_size_option`) sudah ada sejak
  Fase 2 tapi tidak pernah dibaca di mana pun; `BillGenerator` sekarang
  melewati fee type ini dengan alasan eksplisit alih-alih menagih bundel penuh
  ke setiap siswa tanpa pilihan apa pun (ditemukan lewat debugging menyeluruh,
  2026-08-18)
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
