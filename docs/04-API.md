# 04 — Kontrak API

Laravel API JSON, auth **Sanctum SPA cookie** (sama seperti PMB): frontend memanggil
`GET /sanctum/csrf-cookie` sekali, lalu semua request memakai session cookie —
bukan bearer token di localStorage.

Konvensi:

- Semua parameter path memakai **ULID**, tidak pernah ID numerik.
- Daftar selalu terpaginasi: `?page=&per_page=&q=&sort=`.
- Error validasi `422` dengan bentuk standar Laravel.
- Endpoint yang mengubah uang atau poin menulis `activity_logs`.

## Publik / auth

| Method | Path | Keterangan |
|---|---|---|
| GET | `/sanctum/csrf-cookie` | |
| POST | `/api/auth/otp/request` | **wali murid**: `{identifier}` (email atau no HP) → kirim kode ke kanal yang sesuai |
| POST | `/api/auth/otp/verify` | `{identifier, code}` → sesi dimulai, akun otomatis teraktivasi |
| POST | `/api/auth/login` | **staf saja**: email + password. Akun wali dijawab 422 dengan arahan memakai kode |
| POST | `/api/auth/logout` | |
| GET | `/api/auth/me` | profil pengguna yang sedang masuk |
| GET | `/api/invitations/{token}` | validasi token undangan, balikkan nama & daftar anak (tanpa auth) |
| POST | `/api/invitations/{token}/activate` | tanpa body — akun aktif → langsung login |
| POST | `/api/auth/forgot-password`, `/api/auth/reset-password` | staf saja; wali tidak punya kata sandi untuk direset |

Respons `otp/request` sengaja identik untuk nomor terdaftar maupun tidak — hanya
kanal dan identitas tersamar (`bu**@example.com`) yang dikembalikan, plus
`expires_in_minutes` dan `resend_after_seconds` untuk hitungan mundur di UI.
Membedakan keduanya akan menjadikan endpoint ini alat mendata keluarga sekolah.
Permintaan kode dibatasi 5× per identitas dan 5× per IP tiap 10 menit, di atas
jeda kirim ulang 60 detik.

## Portal wali murid (`role: orangtua`)

Semua endpoint di bawah otomatis terbatas pada anak yang terhubung lewat
`student_guardians` — dijaga `scopeVisibleTo()`, bukan pengecekan per-controller.

| Method | Path | Keterangan |
|---|---|---|
| GET | `/api/wali/dashboard` | ringkasan per anak: tagihan jatuh tempo terdekat, total tunggakan, saldo poin, prestasi terbaru |
| GET | `/api/wali/students` | daftar anak |
| GET | `/api/wali/students/{ulid}` | profil, kelas & wali kelas, status |
| GET | `/api/wali/students/{ulid}/points` | ledger poin + saldo term berjalan + ambang yang berlaku |
| GET | `/api/wali/students/{ulid}/achievements` | |
| POST | `/api/wali/students/{ulid}/achievements` | ajukan prestasi (masuk sebagai belum terverifikasi) |
| GET | `/api/wali/bills` | `?student=&status=&type=&year=` — lintas anak |
| GET | `/api/wali/bills/{ulid}` | header + `bill_lines` + riwayat alokasi pembayaran |
| GET | `/api/wali/bills/{ulid}/invoice.pdf` | |
| POST | `/api/wali/checkout` | **inti**: `{ "bill_ulids": [...], "method": "virtual_account" }` → buat `payments` + invoice Xendit → balikkan `invoice_url` |
| POST | `/api/wali/payments/{ulid}/receipt` | unggah bukti transfer manual |
| GET | `/api/wali/payments` | riwayat transaksi + status |
| GET | `/api/wali/announcements` | pengumuman sekolah/unit/kelas anak |
| PATCH | `/api/wali/profile` | |

`POST /api/wali/checkout` menerima banyak `bill_ulids` sekaligus — itulah yang
membuat "bayar SPP 3 bulan untuk 2 anak dalam satu transaksi" mungkin (D3).
Server memvalidasi setiap tagihan memang milik anak si pemanggil dan berstatus
`unpaid`/`partial`/`overdue`, lalu menulis satu `payments` + N `payment_allocations`.

## Guru & wali kelas (`role: guru`)

| Method | Path | Keterangan |
|---|---|---|
| GET | `/api/guru/classrooms` | kelas yang diampu / diwalikan |
| GET | `/api/guru/classrooms/{ulid}/students` | daftar siswa + saldo poin term berjalan |
| POST | `/api/guru/points` | catat poin: `{student_ulid, point_rule_ulid, occurred_on, description, evidence}` |
| POST | `/api/guru/points/bulk` | catat satu aturan untuk banyak siswa sekaligus (mis. terlambat upacara) |
| PATCH | `/api/guru/points/{ulid}/revoke` | batalkan dengan alasan — tidak pernah DELETE |
| GET | `/api/guru/point-rules` | katalog aturan unitnya |
| POST | `/api/guru/achievements` | catat prestasi siswa |
| GET | `/api/guru/students/{ulid}` | profil ringkas untuk keperluan wali kelas |

## Admin (`role: admin` pusat, `admin_unit` per unit)

### Kesiswaan
| Method | Path |
|---|---|
| GET, POST | `/api/admin/students` (POST = siswa pindahan, di luar jalur PMB) |
| GET, PATCH | `/api/admin/students/{ulid}` |
| PATCH | `/api/admin/students/{ulid}/status` (mutasi, lulus, keluar) |
| POST | `/api/admin/students/{ulid}/resend-invitation` |
| GET, POST | `/api/admin/guardians`, `/api/admin/guardians/{ulid}` |
| GET, POST | `/api/admin/classrooms`, PATCH `/api/admin/classrooms/{ulid}` |
| POST | `/api/admin/classrooms/{ulid}/enrollments` (penempatan siswa, bisa massal) |
| POST | `/api/admin/promotions` (kenaikan kelas massal per tahun ajaran) |

### Keuangan
| Method | Path | Keterangan |
|---|---|---|
| GET, POST, PATCH | `/api/admin/fee-types` | |
| GET, POST, PATCH | `/api/admin/fee-rates` | tarif per unit/tingkat/tahun + `fee_components` |
| GET, POST | `/api/admin/discount-schemes`, `/api/admin/student-discounts` | beasiswa & potongan |
| POST | `/api/admin/billing-runs/preview` | **dry-run**: berapa tagihan akan terbit, total nominal, siapa yang dilewati |
| POST | `/api/admin/billing-runs` | jalankan generate (antre di queue) |
| GET | `/api/admin/billing-runs/{ulid}` | progres & hasil |
| GET | `/api/admin/bills` | `?unit=&class=&status=&type=&month=&overdue=` |
| POST | `/api/admin/bills` | tagihan manual (satu siswa / pilih banyak siswa) |
| PATCH | `/api/admin/bills/{ulid}` | ubah jatuh tempo, catatan |
| POST | `/api/admin/bills/{ulid}/waive` | bebaskan, wajib alasan |
| POST | `/api/admin/bills/{ulid}/cancel` | batalkan, wajib alasan |
| POST | `/api/admin/bills/{ulid}/manual-payment` | catat pembayaran tunai di sekolah |
| GET | `/api/admin/payments` | ledger transaksi |
| POST | `/api/admin/payments/{ulid}/verify` | verifikasi bukti transfer |
| POST | `/api/admin/payments/{ulid}/reject` | tolak bukti + alasan |
| GET | `/api/admin/reports/receivables` | tunggakan per unit/kelas/bulan |
| GET | `/api/admin/reports/cashflow` | penerimaan per periode & jenis biaya |
| GET | `/api/admin/reports/export` | XLSX |

### Poin & prestasi
| Method | Path |
|---|---|
| GET, POST, PATCH, DELETE | `/api/admin/point-rules` |
| GET, POST, PATCH | `/api/admin/point-thresholds` |
| GET | `/api/admin/points` (semua siswa dalam scope, dengan filter ambang) |
| GET | `/api/admin/achievements`, POST `/api/admin/achievements/{ulid}/verify` |

### Lain
| Method | Path |
|---|---|
| GET, POST, PATCH, DELETE | `/api/admin/announcements` |
| GET, POST, PATCH | `/api/admin/users` (staf & guru) |
| GET | `/api/admin/academic-years`, `/api/admin/terms` |
| GET | `/api/admin/dashboard` (ringkasan per unit, mengikuti pola dashboard PMB) |
| GET | `/api/admin/logs` (activity + notification logs) |

## Webhook

| Method | Path | Keterangan |
|---|---|---|
| POST | `/api/webhooks/pmb/students` | handoff dari PMB, HMAC `X-PMB-Signature` |
| POST | `/api/webhooks/xendit` | callback pembayaran, verifikasi `x-callback-token` |

Keduanya menulis ke `integration_events` dulu, lalu memproses lewat queue. Handler
mengembalikan `2xx` secepat mungkin — provider yang menunggu proses panjang akan
timeout lalu mengirim ulang.

## Scheduler

| Jadwal | Command | Kegunaan |
|---|---|---|
| Harian 00:30 tanggal 1 | `bills:generate-spp` | terbitkan SPP bulan berjalan untuk siswa `active` |
| Harian 01:00 | `bills:mark-overdue` | tandai lewat jatuh tempo + hitung denda |
| Harian 07:00 | `bills:send-reminders` | pengingat H-7, H-1, dan H+3 lewat email/WA |
| Tiap 10 menit | `payments:expire-stale` | kedaluwarsakan checkout menggantung (disalin dari PMB) |
| Harian 02:00 | `points:evaluate-thresholds` | notifikasi wali saat saldo poin melewati ambang |
