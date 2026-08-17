# 01 — Arsitektur & Keputusan Desain

## Batas sistem: kenapa aplikasi terpisah

Keputusan ini sudah diambil sebelumnya di PMB (`docs/ERD_REDESIGN.md` baris 16):
SPP sengaja dikeluarkan dari enum `student_bills.bill_type` karena "pindah ke
aplikasi terpisah". Dokumen ini melanjutkan keputusan itu, bukan meninjaunya ulang.

Alasan yang membuatnya tetap benar:

- **Siklus hidup datanya beda.** PMB hidup per-gelombang pendaftaran dan selesai;
  data sekolah hidup bertahun-tahun dan bertambah tiap bulan (SPP × 12 × jumlah
  siswa × jumlah tahun). Menggabungkannya membuat tabel PMB tumbuh tanpa batas
  untuk data yang tidak pernah dipakai proses pendaftaran.
- **Penggunanya beda.** PMB dipakai calon wali murid selama ±3 bulan. Aplikasi ini
  dipakai wali murid aktif tiap bulan, plus guru dan wali kelas yang tidak punya
  urusan apa pun dengan pendaftaran.
- **Risikonya beda.** Bug di modul poin/prestasi tidak boleh bisa menjatuhkan
  pendaftaran yang sedang berjalan di musim PMB.

Konsekuensi yang harus diterima: **data siswa ada di dua tempat**. Itu dikelola
lewat aturan kepemilikan yang tegas di [02-INTEGRASI-PMB.md](02-INTEGRASI-PMB.md) —
PMB pemilik data pendaftaran, aplikasi ini pemilik data kesiswaan.

## Keputusan desain

### D1 — Handoff terjadi saat uang pangkal lunas

Pemicunya adalah **pelunasan uang pangkal**, yaitu saat PMB memindahkan registrasi
ke stage `enrolled` — bukan saat pengumuman kelulusan dirilis.

Pengumuman hanya menyatakan diterima; sebagian yang diterima akhirnya tidak jadi
masuk. Kalau akun dibuat di titik itu, aplikasi sekolah akan berisi "siswa" yang
tidak pernah datang, generator SPP akan menagih mereka, dan email berisi akun
sekolah terkirim ke keluarga yang belum tentu jadi mendaftar.

Jadi urutannya:

| Peristiwa | Di mana | Efek di aplikasi ini |
|---|---|---|
| Pengumuman dirilis, verdict `accepted` | PMB | *Tidak ada.* PMB yang mengumumkan dan menagih uang pangkal |
| Uang pangkal lunas → stage `enrolled` | PMB | Handoff dikirim: siswa dibuat `active`, akun wali dibuat, email undangan terkirim |

Siswa yang lahir dari handoff langsung berstatus `active` dan ikut generator SPP
sejak bulan berjalan. Status `prospective` tetap ada di enum, tapi untuk kasus lain:
siswa pindahan yang didaftarkan admin secara manual dan berkasnya belum lengkap.

Konsekuensinya, semua tagihan pra-masuk (formulir, uang pangkal) tetap 100% urusan
PMB — batas tanggung jawabnya jadi bersih: **PMB menagih sampai lunas uang pangkal,
aplikasi ini menagih semua yang sesudahnya.**

### D2 — Wali murid masuk dengan kode sekali pakai, tanpa kata sandi

Wali murid tidak pernah punya kata sandi. Mereka mengetik email atau nomor HP,
lalu memasukkan kode 6 digit yang dikirim ke **kanal yang mereka ketik sendiri**:
email → kode lewat email, nomor HP → kode lewat WhatsApp.

Alasannya cocok dengan penggunanya. Wali murid membuka aplikasi ini beberapa kali
sebulan, bukan tiap hari — kata sandi yang jarang dipakai adalah kata sandi yang
dilupakan, lalu direset, lalu dilupakan lagi. Yang mereka pasti punya dan pasti
bisa diakses adalah nomor WhatsApp. Menghapus kata sandi juga menghapus seluruh
kelas masalah yang menyertainya: password lemah, dipakai ulang dari aplikasi lain,
dibagikan satu keluarga dan tidak pernah diganti, serta alur lupa-password yang
harus dibangun dan dijaga.

**Staf tetap memakai kata sandi.** Admin dan guru login tiap hari; menunggu kode
setiap pagi adalah beban, bukan kemudahan. Yang membedakan keduanya cuma satu hal:
kolom `users.password` terisi atau tidak — tidak ada flag kedua yang harus dijaga
supaya tetap sinkron.

Aturan pengamanan kode:

| Aturan | Nilai | Alasan |
|---|---|---|
| Panjang & masa berlaku | 6 digit, 10 menit | Cukup lama untuk sampai dan diketik, cukup pendek agar kode yang bocor keburu mati |
| Salah tebak | maks. 5×, lalu kode mati | Tanpa batas, 6 digit bisa ditebak habis dalam hitungan menit |
| Kirim ulang | jeda 60 detik | Tanpa jeda, endpoint ini jadi alat membanjiri HP orang |
| Kode aktif | 1 per akun | Minta ulang mematikan yang lama, jadi wali tidak menebak-nebak pesan mana yang masih berlaku |
| Penyimpanan | hanya hash (HMAC) | Bocornya database tidak memberi satu pun kode yang hidup |
| Jawaban endpoint | sama untuk nomor terdaftar & tidak | Kalau berbeda, endpoint ini jadi cara mendata keluarga mana yang bersekolah di sini |

Konsekuensi lain: **halaman aktivasi tidak lagi meminta kata sandi.** Memegang
tautan undangan sudah membuktikan wali menguasai alamat yang tercatat di sekolah —
persis hal yang diperiksa kode OTP. Jadi tautan itu langsung mengaktifkan akun dan
memulai sesi, dan login berikutnya lewat kode.

SSO (OIDC) antar PMB dan aplikasi ini tetap di luar scope: satu komponen
infrastruktur tambahan yang kalau mati, dua aplikasi ikut mati — tidak sepadan
untuk ±1 handoff per siswa seumur hidup. Kandidat Fase 4 bila aplikasi bertambah.

### D3 — Satu akun wali murid, banyak anak

Ini pembeda utama dari PMB. Di PMB satu akun = satu pendaftar. Di sekolah, satu
orang tua bisa punya 3 anak di 3 unit berbeda, dan mereka ingin melihat semua
tagihan dalam satu layar dan membayarnya sekaligus.

Karena itu relasinya `guardians ↔ students` many-to-many lewat `student_guardians`,
dan pembayaran memakai `payment_allocations` (satu pembayaran bisa melunasi
beberapa tagihan lintas anak).

### D4 — Tagihan punya baris (`bill_lines`)

PMB memakai tagihan datar (satu nominal per tagihan) karena tagihannya memang
tunggal: formulir, uang pangkal. Di sini tidak: "uang seragam" itu kemeja + celana
+ olahraga + jilbab, dengan ukuran dan harga masing-masing, dan orang tua akan
menanyakan rinciannya.

Jadi `bills` (header) + `bill_lines` (rincian). Tagihan SPP tetap satu baris —
strukturnya sama, isinya saja yang sederhana.

### D5 — Generator tagihan idempoten lewat `dedup_key`

Pola ini diambil langsung dari PMB (`student_bills.dedup_key` +
`firstOrCreate(['student_id', 'dedup_key'])`). SPP dibuat oleh scheduler tiap awal
bulan; kalau scheduler jalan dua kali, atau admin menekan "generate" ulang, tidak
boleh terbit tagihan ganda.

Format: `spp:2026-2027:07`, `seragam:2026-2027`, `buku:2026-2027:ganjil`.
Unique index di `(student_id, dedup_key)` yang menegakkannya, bukan kode.

### D6 — Poin adalah ledger, bukan kolom saldo

`point_records` mencatat tiap kejadian (pelanggaran/penghargaan) sebagai baris
bertanda tangan pencatat. Saldo poin siswa = SUM baris pada periode berjalan, bukan
kolom `total_poin` yang di-update.

Alasan: poin itu data yang disengketakan ("kata siapa anak saya terlambat 3 kali?").
Kolom saldo tidak bisa menjawab; ledger bisa. Pembatalan dilakukan dengan
`status = revoked` + alasan, bukan `DELETE`, supaya riwayatnya utuh.

### D7 — Scope unit persis seperti PMB

Empat peran: `admin` (pusat, lihat semua), `admin_unit` (satu unit),
`guru` (kelas yang diampu), `orangtua` (anak sendiri). Ditegakkan lewat
`scopeVisibleTo($user)` di model `Student`, `Bill`, `PointRecord` — sama seperti
`Student::scopeVisibleTo` di PMB.

## Deployment

Pola PMB diulang, dengan nama & port sendiri:

```
Cloudflare Tunnel → 127.0.0.1:8091 → nginx
                                      ├── /api/*  → laravel (php-fpm)
                                      └── /*      → nextjs
laravel + scheduler + queue worker → postgres (siakad-net, tanpa port host)
```

Yang berbeda dari PMB:

- **Ada queue worker terpisah.** PMB belum punya; di sini generator SPP massal
  (ribuan tagihan + ribuan email) tidak boleh jalan di request HTTP.
- **Scheduler dipakai serius**: generate SPP bulanan, tandai tagihan `overdue`,
  kirim pengingat H-7/H-1/H+1, kedaluwarsakan pembayaran menggantung.

Catatan operasional yang berlaku sama seperti PMB: `public/` adalah Docker volume,
jadi rebuild image tidak memperbarui asetnya — harus `docker cp`. Dan Xendit
sekarang masih memakai key sandbox; harus diganti sebelum tagihan asli jalan.

## Struktur repo (rencana)

```
sekolah/
├── app/
│   ├── Http/Controllers/Api/
│   │   ├── Admin/      # keuangan, siswa, kelas, poin, pengumuman
│   │   ├── Guru/       # poin & prestasi kelas yang diampu
│   │   ├── Wali/       # portal orang tua
│   │   ├── Auth/
│   │   └── WebhookController.php   # Xendit + PMB
│   ├── Models/
│   ├── Services/
│   │   ├── Billing/    # BillGenerator, PaymentAllocator, LateFeeCalculator
│   │   ├── Handoff/    # PmbHandoffProcessor, InvitationSender
│   │   ├── Points/     # PointLedger, ThresholdEvaluator
│   │   └── Notification/  # gateway Sendago (disalin dari PMB)
│   └── Traits/HasEncryptedAttributes.php   # disalin dari PMB
├── database/migrations/
├── frontend/           # Next.js 16 + shadcn
└── docker/             # nginx, php, nextjs — pola PMB
```
