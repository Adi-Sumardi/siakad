# 04 — Kontrak API

Laravel API JSON, auth **Sanctum SPA cookie**: frontend memanggil
`GET /sanctum/csrf-cookie` sekali, lalu semua request memakai session cookie —
bukan bearer token di localStorage.

Konvensi:

- Semua parameter path memakai **ULID**, tidak pernah ID numerik.
- Error validasi `422` dengan bentuk standar Laravel.
- 404, bukan 403, untuk baris yang ada tapi di luar cakupan pemanggil —
  mengonfirmasi keberadaannya saja sudah bocor sesuatu tentang keluarga lain.
- Endpoint yang mengubah uang atau poin menulis `activity_logs`.
- Tidak ada endpoint kata sandi sama sekali. Satu-satunya jalan masuk untuk
  **semua peran** (wali maupun staf) adalah kode sekali pakai. Pemulihan darurat
  saat kedua gateway (email & WhatsApp) mati: `php artisan otp:issue <email|no HP>`,
  mencetak kode di terminal — butuh akses shell ke server, sengaja lebih tinggi
  dari sekadar tautan reset.

## Auth

| Method | Path | Keterangan |
|---|---|---|
| GET | `/sanctum/csrf-cookie` | |
| POST | `/api/auth/otp/request` | `{identifier}` (email atau no HP) → kirim kode ke kanal yang sesuai |
| POST | `/api/auth/otp/verify` | `{identifier, code}` → sesi dimulai, akun otomatis teraktivasi |
| POST | `/api/auth/logout` | |
| GET | `/api/auth/me` | profil pengguna yang sedang masuk |
| GET | `/api/invitations/{token}` | validasi token undangan, balikkan nama & daftar anak (tanpa auth) |
| POST | `/api/invitations/{token}/activate` | tanpa body — akun aktif → langsung login |

Respons `otp/request` sengaja identik untuk identitas terdaftar maupun tidak —
hanya kanal dan identitas tersamar (`bu**@example.com`) yang dikembalikan, plus
`expires_in_minutes` dan `resend_after_seconds` untuk hitungan mundur di UI.
Dibatasi 5× per identitas dan 5× per IP tiap 10 menit, di atas jeda kirim ulang
60 detik.

## Portal wali murid (`role: orangtua`)

Semua endpoint di bawah otomatis terbatas pada anak yang terhubung lewat
`student_guardians` — dijaga `scopeVisibleTo()` pada tiap model, bukan
pengecekan per-controller.

| Method | Path | Keterangan |
|---|---|---|
| GET | `/api/wali/students` | daftar anak — tiap baris sudah membawa `poin.balance` & `poin.threshold` semester berjalan |
| GET | `/api/wali/students/{ulid}/points` | saldo, ambang yang berlaku, dan seluruh ledger semester ini |
| GET | `/api/wali/students/{ulid}/achievements` | |
| POST | `/api/wali/students/{ulid}/achievements` | ajukan prestasi — masuk `pending`, tidak pernah membawa poin sendiri |
| GET | `/api/wali/announcements` | gabungan pengumuman untuk **semua** anak (union, bukan per-anak) |
| GET | `/api/wali/bills` | lintas anak |
| GET | `/api/wali/bills/{ulid}` | header + `lines` + pembayaran yang mengalokasikannya |
| GET | `/api/wali/bills/{ulid}/pdf` | invoice selama belum lunas, kuitansi setelah lunas — satu template |
| POST | `/api/wali/checkout` | **inti**: `{bill_ulids: [...], method}` → satu `payments` + N `payment_allocations` → invoice Xendit |
| GET | `/api/wali/payments` | riwayat transaksi + status |

`POST /api/wali/checkout` menerima banyak `bill_ulids` sekaligus — itulah yang
membuat "bayar SPP 3 bulan untuk 2 anak dalam satu transaksi" mungkin (D3).
Kepemilikan tiap tagihan diperiksa ulang di server (`CheckoutService::collectPayable`),
bukan dipercaya dari daftar yang dikirim browser.

Tidak ada endpoint unggah bukti transfer — pembayaran online lewat Xendit,
tunai dicatat langsung oleh admin; tidak ada yang pernah menghasilkan slip
untuk difoto.

## Guru (`role: guru`)

`Student::scopeVisibleTo()` memperlakukan `guru` sama seperti `admin_unit`:
melihat seluruh unit, bukan cuma kelas yang diwalikan — guru mengajar lintas
kelas dalam satu unit.

| Method | Path | Keterangan |
|---|---|---|
| GET | `/api/guru/classrooms` | kelas di unitnya, `is_homeroom` menandai kelas yang diwalikan |
| GET | `/api/guru/classrooms/{ulid}/students` | roster + saldo poin semester berjalan |
| GET | `/api/guru/point-rules` | katalog aturan: milik unitnya + yang berlaku seluruh sekolah |
| POST | `/api/guru/points` | catat satu: `{student_ulid, point_rule_ulid, occurred_on, description, evidence?}` |
| POST | `/api/guru/points/bulk` | satu aturan untuk banyak siswa sekaligus (mis. terlambat upacara); aturan berbukti wajib ditolak di sini |
| PATCH | `/api/guru/points/{ulid}/revoke` | `{reason}` wajib — mengecualikan dari saldo, baris & alasannya tetap ada (D6) |
| POST | `/api/guru/achievements` | catat prestasi — **langsung terverifikasi**, boleh sertakan `points_awarded` |

Guru tidak bisa mengisi `points` bebas — hanya menerapkan aturan dari katalog.
Mencegah nilai poin yang diketik sembarangan.

## Admin (`role: admin` pusat, `admin_unit` per unit)

Pola otorisasi konsisten di semua endpoint admin: `role:` di middleware
menentukan siapa yang boleh mencapai endpoint; `visibleTo()`/`manageableBy()`
pada model menentukan baris mana yang terlihat — dua pertanyaan terpisah, dan
tesnya membuktikan keduanya (admin_unit yang meminta baris unit lain dapat 404,
bukan 403).

### Keuangan
| Method | Path | Keterangan |
|---|---|---|
| GET, POST, PATCH | `/api/admin/fee-types` | **pusat saja** |
| GET, POST, PATCH | `/api/admin/fee-rates` | **pusat saja** — harga menyangkut ratusan keluarga |
| POST | `/api/admin/billing-runs/preview` | dry-run: berapa tagihan, total nominal, siapa dilewati & kenapa |
| POST | `/api/admin/billing-runs` | jalankan; admin_unit dipaksa ke unitnya sendiri, parameter unit lain diabaikan |
| GET | `/api/admin/billing-runs` | riwayat run |
| GET | `/api/admin/bills` | terpaginasi, filter `status`, `type`, `month`, `q` |
| GET | `/api/admin/bills/{ulid}/pdf` | |
| POST | `/api/admin/bills/{ulid}/waive` | `{reason}` wajib |
| POST | `/api/admin/bills/{ulid}/cancel` | `{reason}` wajib; ditolak bila sudah ada pembayaran masuk |
| POST | `/api/admin/bills/{ulid}/payments` | catat tunai/transfer yang sudah dikonfirmasi — langsung lunas lewat `PaymentAllocator` |
| GET | `/api/admin/reports/receivables` | tunggakan, dikelompokkan per kelas |
| GET | `/api/admin/reports/collections` | penerimaan per metode & jenis biaya, rentang tanggal bebas |

### Kesiswaan — poin & prestasi
| Method | Path | Keterangan |
|---|---|---|
| GET, POST, PATCH, DELETE | `/api/admin/point-rules` | admin_unit hanya kelola unitnya; hapus ditolak bila aturan sudah pernah dipakai (nonaktifkan saja) |
| GET, POST, PATCH | `/api/admin/point-thresholds` | sama polanya dengan point-rules |
| GET | `/api/admin/points` | roster + saldo dalam cakupan, satu query terkelompok (bukan N+1) |
| GET | `/api/admin/achievements` | `?status=pending` dst.; pending selalu tampil lebih dulu |
| POST | `/api/admin/achievements/{ulid}/verify` | `{points_awarded?}` — opsional, memicu `PointLedger::awardForAchievement` |
| POST | `/api/admin/achievements/{ulid}/reject` | `{reason}` wajib |

### Lain
| Method | Path | Keterangan |
|---|---|---|
| GET, POST, PATCH, DELETE | `/api/admin/announcements` | admin_unit **melihat** pengumuman sekolah-wide + unitnya, tapi hanya **mengubah** unitnya — dua scope terpisah (`scopeVisibleTo` vs `scopeManageableBy`) |

Belum dibangun (API maupun frontend): manajemen siswa/kelas/wali langsung dari
admin (siswa datang dari handoff PMB, kelas & guru masih lewat tinker/seed),
promosi kelas massal, dan layar admin/guru di frontend — semuanya API-lengkap,
tinggal antarmukanya.

## File privat

Satu controller untuk keempatnya — gerbangnya sama: siapa pun yang meminta
harus bisa melihat baris pemilik file lewat `visibleTo()` yang sama dengan yang
menjaga data JSON-nya.

| Method | Path |
|---|---|
| GET | `/api/files/achievements/{ulid}/sertifikat` |
| GET | `/api/files/achievements/{ulid}/foto` |
| GET | `/api/files/points/{ulid}/evidence` |
| GET | `/api/files/announcements/{ulid}/file` |

## Webhook

| Method | Path | Keterangan |
|---|---|---|
| POST | `/api/webhooks/pmb/students` | handoff dari PMB, HMAC `X-PMB-Signature` |
| POST | `/api/webhooks/xendit` | callback pembayaran, verifikasi `x-callback-token` |

Keduanya menulis ke `integration_events` dulu (kunci pada `event_id`), lalu
memproses — redelivery dari provider tidak pernah dobel-proses.

## Scheduler

| Jadwal | Command | Kegunaan |
|---|---|---|
| Tanggal 1, 00:30 | `bills:generate --type=spp` | terbitkan SPP bulan berjalan untuk siswa `active` |
| Harian 01:00 | `bills:mark-overdue` | tandai lewat jatuh tempo |
| Harian 06:30 | `points:evaluate-thresholds` | notifikasi wali saat saldo poin melewati ambang — sekali per ambang per semester |
| Harian 07:00 | `bills:send-reminders` | pengingat H-7, H-1, H+3 |
| Harian 03:00 | `units:sync` | tarik ulang master unit dari PMB |
