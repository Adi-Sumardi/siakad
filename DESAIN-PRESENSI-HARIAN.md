# DESAIN PRESENSI HARIAN SISWA — T14

> Dibuat: 2026-09-12 · Dibangun: 2026-09-12 s.d. 2026-09-14 ·
> Status: **sudah jalan penuh — seluruh urutan pengerjaan §11 selesai**
> (fondasi → mode wali kelas → mode gerbang → integrasi laporan → polesan).
> Semua keputusan tercatat di §10 (Log Keputusan). Yang masih menunggu
> mentor/sekolah tinggal daftar di §9.
> Dokumen ini sengaja ditulis dengan bahasa sederhana supaya bisa dibaca juga
> oleh pihak non-teknis (mentor / sekolah).

---

## 1. Latar belakang & tujuan

Sistem SIAKAD hari ini hanya punya absensi **per mata pelajaran** (guru mapel
membuka sesi tiap jam pelajaran — hanya relevan untuk SMP/SMA). Yang belum ada:

1. **Absensi "masuk sekolah"** — apakah anak datang ke sekolah hari ini? → *belum ada sama sekali*
2. **Info ke wali murid lewat WhatsApp** — anak sudah sampai sekolah & sudah pulang → *belum ada*
3. **Kenyataan lapangan SD/TK**: guru hanya wali kelas, tidak ada guru mapel per jam → absensi cukup **sekali sehari**
4. **Anti-kecurangan** — absen lewat HP bisa dicurangi (QR difoto, scan dari rumah, satu HP absenin banyak teman)

Fitur ini menjawab keempatnya sekaligus, untuk **semua 7 unit** (PG sampai SMA).

---

## 2. Konsep inti: dua lapis absensi

```
┌─────────────────────────────────────────────────────┐
│  LAPISAN 1: PRESENSI HARIAN (BARU — fitur T14)      │
│  "Anak masuk sekolah hari ini atau tidak?"          │
│  Semua unit · 1 catatan/siswa/hari (masuk)          │
│                + 1 catatan/siswa/hari (pulang)      │
│  → SUMBER RESMI kehadiran (rapor, watchlist, rekap) │
├─────────────────────────────────────────────────────┤
│  LAPISAN 2: PRESENSI PER MAPEL (SUDAH ADA)          │
│  "Anak bolos jam ke berapa?"                        │
│  Hanya SMP/SMA (yang punya guru mapel terjadwal)    │
│  → turun status jadi DETAIL pelengkap               │
└─────────────────────────────────────────────────────┘
```

**Keputusan penting (opsi 2, disetujui):** semua laporan resmi membaca dari
lapisan harian — rapor menulis "Hadir 120 hari, Sakit 2 hari", dashboard
watchlist menghitung alpa per **hari** (bukan per jam pelajaran). Presensi per
mapel tetap tersimpan sebagai rincian untuk SMP/SMA. Lebih adil antar jenjang
dan lebih benar secara cara rapor menghitung.

---

## 3. Siapa mengerjakan apa

| Peran | Tugasnya |
|---|---|
| **Admin unit / TU** | Setting awal (sekali): hari aktif, jam buka/tutup masuk & pulang, batas terlambat, mode absensi, QR & radius, link publik |
| **Sistem (scheduler)** | Buka & tutup sesi otomatis tiap hari sesuai setting — tidak ada yang "membuka" apa pun manual |
| **Wali kelas** (PG/RA/TK/SD) | Menandai roster kelasnya: pagi (hadir/terlambat/sakit/izin/alpa) & sore (pulang) |
| **Siswa** (SMP/SMA) | Buka link absen dari deskripsi grup WA kelas → scan QR gerbang → ketik NIS |
| **TU di gerbang** (SMP/SMA) | Buka "mode layar QR" di HP/laptop sendiri (tidak perlu TV), pantau sesi, input manual untuk yang gagal, koreksi |
| **Wali murid** | Terima notifikasi WA — tidak melakukan apa pun |
| **Guru wali kelas** (semua jenjang) | Lihat rekap absen harian kelasnya (baca saja) |
| **Admin pusat** | Lihat semua unit |

---

## 4. Mode absensi per unit (default per jenjang, bisa di-override)

| Jenjang | Mode default | Kenapa |
|---|---|---|
| Playgroup / RA / TK | **Wali Kelas** | Anak kecil selalu diantar, tidak punya HP — ditandai wali kelas |
| SDI | **Wali Kelas** | Guru hanya wali kelas; absensi cukup sekali sehari (keputusan desain) |
| SMPI / SMAI | **Gerbang** | Siswa punya HP; QR berputar + radius + perangkat-sekali |

Mode Gerbang tanpa layar TV sama sekali — lihat §5D: layar QR-nya cukup
HP/laptop yang sudah dimiliki TU, dan siswa masuk lewat link publik yang
ditempel di deskripsi grup WA kelas.

---

## 5. Alur lengkap

### A. Setting awal (sekali, oleh admin unit)

Halaman `/admin/presensi-harian/pengaturan`:

- **Hari aktif**: ☑ Sen–Jum ☐ Sabtu ☐ Minggu
- **Absen MASUK**: buka 06:30 → tutup 08:00, batas terlambat 07:15
- **Absen PULANG**: buka 14:30 → tutup 17:00 (bisa dimatikan)
- **Mode**: Wali Kelas / Gerbang (default otomatis sesuai jenjang)
- **Mode Gerbang saja**:
  - titik gerbang di peta + radius (default 100 m)
  - QR wajib atau tidak (default: wajib)
  - **Link Absen Publik**: tombol salin link + QR-nya (untuk ditempel ke grup WA); tombol reset link bila bocor
- **Notifikasi WA**: toggle per jenis (masuk / pulang / alpa)

### B. Setiap hari — otomatis

Scheduler seperti alarm: pagi membuka sesi masuk untuk unit yang hari itu
aktif, siang membuka sesi pulang, jam tutup menutup sendiri + memproses
sisa siswa (§5E). Aman dari dobel: kalau alarm kejalan dua kali tidak terjadi
apa-apa — database menolak duplikat (prinsip idempoten, aturan R5).

### C. Mode Wali Kelas (PG/RA/TK/SD)

```
PAGI (~07:00)
  Wali kelas → halaman kelasnya → panel "Presensi Harian"
  Tandai per anak: Hadir | Terlambat | Sakit | Izin | Alpa
  (pola cepat: "tandai semua hadir" → ubah 2–3 anak yang sakit/izin)
  → tiap penandaan mengirim WA ke wali murid anak tsb

SORE (~15:00)
  Panel yang sama, mode pulang:
  Pulang | Dijemput | Pulang Cepat (+catatan)
  → WA "Ananda telah pulang" ke wali murid
```

Tidak ada QR, tidak ada HP siswa. Wali kelas lupa mengisi? → §5E menutup celahnya.

### D. Mode Gerbang (SMP/SMA) — TANPA TV

Poin penting desain ini: **sekolah tidak membeli/ menyediakan layar TV.**
Layar QR-nya adalah HP/laptop TU sendiri; siswa masuk lewat link publik.

```
SEKALI (admin unit):
  Pengaturan → "Link Absen Publik" → salin link / QR-nya
  → link ditempel di DESKRIPSI GRUP WA tiap kelas oleh wali kelas/TU
  (satu link untuk satu unit — sama untuk semua kelas; pemisahan siswa
   terjadi lewat NIS, bukan lewat link)

PAGI (TU):
  TU buka link yang sama di HP/laptop pribadinya → pilih "Mode Layar QR"
  → layar menampilkan QR BESAR yang BERGANTI tiap ±30 detik
  → cukup diletakkan/dipegang di meja gerbang

SISWA TIBA:
  1. buka link dari deskripsi grup WA (tanpa login apa pun)
  2. halaman minta izin lokasi → server cek: dalam radius gerbang?
  3. ketik NIS → muncul nama panggilan → konfirmasi "Ya, ini saya"
     (langkah TANPA tenggat dikerjakan dulu — lihat log keputusan 2026-09-14)
  4. scan QR di layar TU → begitu terbaca LANGSUNG submit
     (milidetk setelah scan; QR tak sempat basi)
     fallback: pilih FOTO QR dari galeri (didekode sama) atau ketik
     8 karakter manual yang tertera di bawah QR gerbang
  5. server memeriksa SEMUA:
       ✓ QR masih segar (belum lewat ±60 detik)?
       ✓ posisi dalam radius?
       ✓ perangkat ini belum dipakai absen hari ini?   ← anti "1 HP banyak NIS"
       ✓ NIS ini belum absen hari ini?
  6. LOLOS → HADIR pukul 07:02 (+ "terlambat 17 menit" bila lewat batas)
           → WA langsung ke wali murid
           (pesan gagal apa pun ditampilkan di layar aktif, tidak pernah
            memantul senyap ke layar lain)
```

Kenapa QR harus tampil di gerbang (di HP TU), bukan di halaman publik?
Karena nilai keamanan QR justru dari **"harus secara fisik melihat layar yang
ada di gerbang"**. Kalau QR-nya tampil di halaman publik, anak di rumah juga
bisa melihatnya — berarti tidak membuktikan apa-apa. Dengan HP TU sebagai
layar: anak di rumah punya link pun tidak cukup — dia tidak bisa melihat QR
yang sedang berputar di gerbang, dan radius GPS tetap memblokirnya.

Antrean ramai? Jalur cepat tetap ada: TU input manual di booth (ketik NIS),
dan siswa yang benar-benar tanpa HP ditandai lewat alur lengkap sesi.

### E. Penutupan & siswa yang bolong (anti-lupa)

Yang paling penting: **data tidak boleh bolong karena manusia lupa.**

```
Pukul 08:00 (absen masuk tutup):
  Siswa yang sampai saat itu belum tercatat → OTOMATIS ditandai ALPA
  → WA ke wali murid: "Ananda tidak tercatat hadir hingga pukul 08:00…"

  Ternyata salah (orang tua telepon: anak sakit)?
  → wali kelas / TU ubah ALPA → SAKIT
  → jejak koreksi tersimpan (siapa, kapan, kenapa)
  → catatan lama TIDAK dihapus — hanya ditandai batal ber-alasan
```

Prinsip: **coret ber-alasan, jangan hapus** (sama seperti buku kas / poin —
aturan R2/D6). Sejarah koreksi selalu bisa diaudit.

### F. Notifikasi WhatsApp (3 jenis)

| Pemicu | Pesan ke wali murid |
|---|---|
| Anak tercatat masuk | "Ananda {nama} tercatat MASUK di {unit} pukul 07:02" (+ "— terlambat 17 menit") |
| Anak tercatat pulang | "Ananda {nama} tercatat PULANG pukul 15:31" |
| Absen masuk tutup & tidak tercatat | "Ananda {nama} tidak tercatat hadir hingga pukul 08:00…" |

- Dikirim ke **semua wali ter-link** ke anak (tidak ada yang terlewat)
- **Toggle per jenis** di pengaturan unit (katup kalau terlalu ramai/mahal)
- Anti-kirim-dobel: scheduler boleh kejalan dua kali, WA tetap terkirim sekali
- Dikirim lewat queue + gateway Sendago yang sudah dipakai sistem

---

## 6. Penangkal kecurangan — 5 lapis (mode Gerbang)

| # | Lapis | Menahan | Cara kerjanya |
|---|---|---|---|
| 1 | **QR berputar ±30 detik** di layar TU | Scan dari rumah (foto/link) | Foto QR basi dalam semenit; anak di rumah tidak bisa melihat layar gerbang |
| 2 | **Radius GPS** (default 100 m, diset admin unit) | Scan dari luar sekolah | Server hitung jarak posisi HP ke titik gerbang; di luar lingkaran = tolak |
| 3 | **Perangkat sekali** (baru — permintaan 2026-09-12) | "1 HP absenin banyak teman" | Tiap perangkat punya tanda ter-hash; satu perangkat = satu absen per hari. NIS kedua dari HP yang sama ditolak |
| 4 | **Manusia saksi** | Semua jenis | TU di gerbang / wali kelas di kelas bisa input manual & koreksi |
| 5 | **Alarm pola aneh** | Proksi yang lolos | Sistem mencatat perangkat+IP; beberapa NIS dari perangkat/IP mirip → layar TU menampilkan peringatan "periksa siswa ini" |

**Batas yang diakui jujur** (untuk dikomunikasikan ke sekolah):
- Posisi GPS bisa dipalsukan anak yang paham teknologi (mock location) — lapis 1+3+5 yang menahan siswa seperti ini.
- Tanda perangkat hilang kalau pengguna hapus data browser / pakai mode incognito → karena itu lapis 5 (pola perangkat+IP mirip) tetap ada sebagai jaring pengaman, dan TU tetap bisa koreksi manual.
- Radius jangan dibuat terlalu kecil (<50 m) — GPS sering meleset ±10–50 m, siswa jujur bisa ikut tertolak.
- Notifikasi lokasi & QR berputar butuh HTTPS di produksi (sudah tersedia).

### 6b. Penangkal untuk presensi per MAPEL (ditambah 2026-09-15)

Presensi per mapel semula hanya dijaga token URL sesi — URL itu statis sepanjang
jam pelajaran dan bisa disebar ke grup, lalu siapa pun bisa mengetik NIS
temannya. Sekarang pola gerbang dipakai ulang:

| # | Lapis | Menahan | Cara kerjanya |
|---|---|---|---|
| M1 | **SATU QR berputar** di layar guru (panel sesi) — tidak ada QR statis | Absen dari luar kelas / link disebar | Isi QR = `URL_sesi#kode` (kode = HMAC sesi mapel + jendela 30 dtk, `RotatingQrService`). Scan kamera HP native langsung membuka halaman DAN membawa kode segar; foto/video call mati dalam ±1 menit. URL ditulis dari origin browser guru (bukan `app.frontend_url`) agar dev lokal & tunnel ngrok sama-sama hidup. Fallback: ketik 8 karakter manual, atau scan ulang lewat kamera dalam halaman bila kode dari hash kedaluwarsa |
| M2 | **Perangkat sekali per sesi mapel** | "1 HP absenin banyak teman" | `device_hash` + partial unique index — pola & kolom sama dengan `daily_records` |
| M3 | **Guru saksi + laporan silang** | Semua jenis | Roster live + revoke oleh guru; papan TU menampilkan *diskrepansi* dua lapis: hadir gerbang tapi nol mapel (= bolos setelah masuk), dan sebaliknya |

Lapis GPS sengaja TIDAK dipakai untuk mapel: GPS indoor meleset 10–50 m dan
beberapa kelas bisa berada dalam radius yang sama — akan menolak siswa jujur.

---

## 7. Model data (3 tabel baru — penjelasan awam)

| Tabel | Isinya | Analogi |
|---|---|---|
| **`daily_settings`** (1 baris per unit) | Setting §5A: hari aktif, jam-jam, batas terlambat, mode, titik & radius gerbang, `public_slug` link publik (bisa di-reset), toggle notifikasi | "Jam kerja" tiap kampus |
| **`daily_sessions`** (1 per unit per hari per tipe masuk/pulang) | Bukti sesi: unit, tanggal, tipe, jam buka/tutup, `late_after` (snapshot dari setting — ubah setting besok tidak menulis-ulang sejarah), dibuka oleh siapa (biasanya: otomatis/scheduler) | "Absen hari ini sudah dibuka" |
| **`daily_records`** (1 per siswa per hari per tipe) | Catatan inti: siswa, tanggal, status, `is_late`, siapa menandai (mandiri/wali kelas/TU), `device_hash` (perangkat-sekali), status batal + alasan revoke | Buku kas kehadiran — sekali tulis, koreksi = coret ber-alasan |

Aturan unik yang ditegakkan **database** (bukan cuma kode — pembelajaran T5:
partial index ditulis raw SQL):

- 1 baris aktif per (siswa, hari, tipe) — NIS tidak bisa absen dua kali
- 1 baris aktif per (sesi, perangkat) — satu HP tidak bisa absen dua NIS
- 1 sesi per (unit, hari, tipe) — scheduler dobel tidak mengapa

Aturan lama tetap dipatuhi: ID yang keluar dari sistem selalu ULID (R4);
admin unit tidak pernah melihat data unit lain — 404, bukan pesan "dilarang"
(R3); jam check-in selalu dari server, tidak percaya jam perangkat.

---

## 8. Dampak ke fitur yang sudah jalan & catatan transisi

| Fitur lama | Sesudah ini |
|---|---|
| Rekap presensi kelas (panel guru T7) | Sumber jadi presensi harian; SMP/SMA bisa lihat dua lapis (harian + per mapel) |
| Rapor PDF bagian presensi | "Hadir X hari, Sakit Y hari…" dari lapisan harian |
| Dashboard watchlist "alpa ≥ 5" (T20) | Menghitung hari alpa — lebih adil antar jenjang |
| Presensi per mapel | Tetap jalan untuk SMP/SMA, turun status jadi detail |

**Catatan transisi (perlu disebut ke mentor):** data SMP/SMA yang lama tercatat
per jam pelajaran; begitu fitur aktif, angka resmi beralih ke hitungan hari.
Data lama tidak diubah/hilang — hanya ada dua "satuan" dalam sejarah.

---

## 9. Yang masih terbuka (tidak memblokir pembangunan)

1. **Kebijakan WA gagal kirim** — saat ini gagal = catat log saja, tanpa retry (pertanyaan lama §4 no. 3 PROGRESS-MAGANG, domain mentor).
2. **Libur nasional di luar pola mingguan** — v1: tidak ada sesi / matikan manual hari itu; tabel pengecualian tanggal menyusul bila perlu.
3. **Volume & biaya WA** — ribuan pesan/hari; toggle per jenis notifikasi sudah disiapkan sebagai katup.
4. **Timezone aplikasi** masih UTC (pindah ke Asia/Jakarta = domain mentor §3.1) — scheduler bergantung ini.
5. **Opsi 2 (harian = sumber resmi)** menyentuh makna data historis SMP/SMA — idealnya dikonfirmasi sekilas ke mentor.

---

## 10. Log keputusan

| Tanggal | Keputusan | Diputuskan oleh |
|---|---|---|
| 2026-09-12 | Presensi gerbang harian dikuasai **admin unit/TU** (bukan guru piket) | Iwan |
| 2026-09-12 | Sesi dibuka **otomatis** sesuai setting hari/jam per unit | Iwan |
| 2026-09-12 | Dua titik absen: **masuk & pulang**, keduanya notifikasi WA ke wali | Iwan |
| 2026-09-12 | Ambang terlambat diset **admin unit** | Iwan |
| 2026-09-12 | Guru wali kelas boleh **lihat rekap** absen masuk | Iwan |
| 2026-09-12 | Siswa tanpa catatan saat tutup → **auto-alpa + WA**, TU/wali kelas bisa koreksi (revoke ber-alasan) | Iwan |
| 2026-09-12 | WA dikirim ke **semua wali ter-link** | Iwan |
| 2026-09-12 | Tersedia **toggle per jenis notifikasi** (katup volume/biaya) | Iwan |
| 2026-09-12 | Anti-curang berlapis: QR berputar + radius GPS + manusia saksi + alarm pola | Iwan |
| 2026-09-12 | **SD: absensi sekali sehari oleh wali kelas** — presensi per mapel tidak dipakai di SD/TK | Iwan |
| 2026-09-12 | Absen **pulang di SD ditandai wali kelas** | Iwan (konfirmasi) |
| 2026-09-12 | **Mode default per jenjang** (PG/RA/TK/SD = wali kelas; SMP/SMA = gerbang), bisa di-override per unit | Iwan (konfirmasi) |
| 2026-09-12 | **Opsi 2: presensi harian jadi sumber resmi kehadiran** semua laporan | Iwan (konfirmasi; §9 no. 5) |
| 2026-09-12 | Mode Gerbang **TANPA TV**: link publik per unit ditempel di deskripsi grup WA kelas; layar QR cukup HP/laptop TU | Iwan |
| 2026-09-12 | **Perangkat sekali**: satu perangkat hanya bisa absen satu siswa per hari — anti "1 HP absenin banyak teman" | Iwan |
| 2026-09-14 | **Ubah jam = berlaku hari ini juga** (temuan uji ngrok): sesi yang masih terbuka di-refresh jam-nya; sesi yang sudah ditutup **dibuka ulang** bila jendela barunya masih di depan (`opened_by` mencatat pengubah — kolom ini memang disiapkan untuk reopen manual) dan **alpa hasil sapuan otomatis di-revoke ber-alasan** (jendela yang digambarkan alpa itu tidak pernah tutup); jendela baru yang juga sudah lewat tetap sejarah; unit dimatikan / absen pulang ditarik di tengah hari → jendela terbuka ditutup senyap tanpa sapuan alpa | Iwan |
| 2026-09-14 | **Urutan gerbang: NIS dulu → konfirmasi nama → scan QR terakhir yang langsung submit** (temuan uji lapangan: alur scan-dulu membuat QR hangus ±60 dtk di tengah while siswa mengetik NIS, dan kegagalan memantul senyap ke layar scan) | Iwan |
| 2026-09-14 | **Fallback gerbang: pilih foto QR dari galeri + ketik kode manual** — foto lama mati oleh rotasi, radius GPS tetap memagari penerima; fungsi = penyelamat ponsel yang kamera livewire-nya bermasalah | Iwan |
| 2026-09-14 | **Face recognition diparkir untuk v1** — wajah = data biometrik anak-anak (sensitif dalam UU PDP 27/2022, butuh persetujuan wali), enrolmen massal, false reject di cahaya gerbang pagi; dicatat sebagai pertanyaan kebijakan mentor/sekolah, bukan keputusan teknis internal | Iwan |
| 2026-09-15 | **Presensi mapel ikut QR berputar + perangkat-sekali** (§6b) — token URL sesi yang statis selama jam pelajaran digantikan peran kredensial oleh kode berputar di panel guru; `GateQrService` digeneralisasi jadi `RotatingQrService` untuk dua permukaan | Iwan |
| 2026-09-15 | **Gerbang menolak fix GPS ber-akurasi > ½ radius** — `accuracy` dari browser ikut dikirim; fix lebih lebar dari setengah radius tidak bisa membedakan "di gerbang" vs "sudah lewat", dan aplikasi mock location sering memberi nilai janggal; pesan menyuruh coba di tempat terbuka | Iwan |
| 2026-09-15 | **Papan TU: feed "check-in terakhir" + laporan diskrepansi gerbang-vs-mapel** — mata manusia di gerbang jadi lapis eksplisit; diskrepansi hanya dihitung setelah ≥1 sesi mapel hari itu agar papan pagi tidak menandai seisi sekolah; pengaturan gerbang tanpa radius GPS kini memunculkan peringatan lubang video-call | Iwan |
| 2026-09-15 | **Panel "Jadwal Hari Ini" guru tetap tampil saat kosong** — sebelumnya `return null`, guru mengira fitur presensi mapel hilang; kini menjelaskan bahwa presensi mapel dibuka dari panel itu | Iwan |
| 2026-09-15 | **Mapel: satu QR berputar, QR URL statis dihapus** (temuan uji ngrok: `checkin_url` dari `app.frontend_url` memuat `localhost` → QR statis mati di HP; QR 8 karakter bukan URL → kamera native tidak membuka apa pun). QR berputar kini berisi `URL_sesi#kode` dengan URL dari origin browser guru; scan native membuka halaman + kode di hash, halaman mengambilnya dan mengosongkan hash; bila kode basi saat submit → jatuh ke langkah scan dalam halaman | Iwan |
| 2026-09-15 | **HP mengingat NIS pemiliknya** (`absen-nis` di localStorage, diingat setelah satu lookup sukses; halaman gerbang & mapel sama) — scan QR mapel berikutnya langsung ke konfirmasi satu tombol / layar "sudah hadir", tanpa mengetik ulang; "Bukan saya" kembali ke form ketik dan NIS baru menimpa yang lama (HP pinjaman menyembuhkan diri). Identitas tetap dikonfirmasi satu tap — tidak pernah auto-submit tanpa konfirmasi | Iwan |

---

## 11. Urutan pengerjaan yang disarankan

1. ✅ **Fondasi** — 3 tabel + setting unit + scheduler buka/tutup otomatis
2. ✅ **Mode Wali Kelas** dulu (SD/TK — paling sederhana, tanpa QR): panel guru + WA + auto-alpa. Langsung terpakai 4 dari 7 unit dengan risiko terkecil
3. ✅ **Mode Gerbang** (SMP/SMA): link publik + layar QR di HP TU + scanner + radius + perangkat-sekali + halaman sesi TU
4. ✅ **Integrasi laporan** — rapor, watchlist, rekap kelas beralih ke sumber harian
5. ✅ **Polesan** — alarm pola perangkat+IP (bendera di papan TU), toggle notifikasi; pengecualian tanggal libur menyusul bila perlu (§9 no. 2)
