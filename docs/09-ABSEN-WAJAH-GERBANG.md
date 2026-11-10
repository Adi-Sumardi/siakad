# 09 — Absen Siswa dengan NIS + Wajah (SMP/SMA)

> Status: **sudah dibangun** di branch `iwan-dev` (10 Nov 2026), belum diuji dengan kamera sungguhan di sekolah.
> Penulis: Iwan Hadi (PKL).
> Menggantikan QR berputar di mode Gerbang (`DESAIN-PRESENSI-HARIAN.md` §5D dan §6 lapis 1).

---

## 1. Ringkasan

Siswa SMP/SMA absen lewat **satu link publik per unit**: ketik NIS, pastikan nama yang muncul
benar, lalu hadapkan wajah ke kamera dan **berkedip sekali**. Selesai.

- **Absen wajah pertama = pendaftaran wajah.** Wajah saat itu disimpan sebagai acuan untuk NIS tersebut.
  Absen berikutnya harus cocok dengan wajah acuan.
- **Perangkat bebas**: iPad di kelas, HP sendiri, atau HP teman. Yang dicocokkan wajahnya, bukan perangkatnya.
- **Radius lokasi** tetap bisa diatur per unit.
- **QR absen dihapus.** QR di kartu "Link Absen Publik" hanya berisi link, untuk membuka halaman tanpa mengetik.
- **Gagal absen → wali kelas** yang mencatat (halaman Presensi Harian guru). TU tetap bisa input manual.

Keputusan yang sudah diambil sekolah:

| Hal | Keputusan |
|---|---|
| Cek wajah hidup | Cukup **berkedip** |
| Persetujuan orang tua | Sudah diurus sekolah. Tidak ada alur persetujuan di aplikasi. |
| Pendaftaran wajah | Otomatis di absen pertama, **satu foto** |
| Mode kiosk / perangkat terdaftar | **Tidak dipakai**. Semua lewat link web yang sama. |
| Server | Self-host. Model wajah ikut dilayani dari server sendiri (`/models/face`). |

## 2. Alur siswa

```
1. Buka link /absen/{slug}   (dari grup WA, atau iPad kelas)
2. [jika radius aktif] izin lokasi → di luar radius = berhenti
3. Ketik NIS → muncul nama panggilan → "Ya, ini saya"
   (belum punya wajah terdaftar → muncul keterangan "ini absen wajah pertamamu")
4. Kamera depan → "Sekarang berkedip sekali"
5. Setelah kedip terdeteksi, browser mengambil 1 frame:
     - 128 angka ciri wajah (descriptor)
     - 1 foto JPEG kecil (±480 px)
   lalu langsung dikirim ke server
6. Server memeriksa:
     ✓ sesi absen sedang buka
     ✓ (radius aktif) posisi dalam radius
     ✓ belum ada wajah → daftarkan wajah ini
       sudah ada wajah → jarak ciri wajah ≤ ambang (default 0,5)
     ✓ NIS ini belum absen di sesi ini
7. Berhasil → "Absen siswa berikutnya" (iPad bisa langsung dipakai siswa lain)
   Gagal 3x → "Minta wali kelasmu mencatat absenmu"
```

## 3. Di mana apa terjadi

| Bagian | Lokasi |
|---|---|
| Pelacak wajah & kedip (browser) | `frontend/src/lib/face-check.ts` + `blink-detector.ts`, memakai **MediaPipe Face Landmarker** (`@mediapipe/tasks-vision` 1.1.1). Model `public/mediapipe/face_landmarker.task` (±3,6 MB, di-commit); WASM disalin dari `node_modules` ke `public/mediapipe/wasm/` oleh `scripts/copy-mediapipe-wasm.mjs` saat `predev`/`prebuild` (tidak di-commit). |
| Ciri wajah untuk pencocokan (browser) | `@vladmandic/face-api`, model di `frontend/public/models/face/` (±7 MB). Dihitung di latar ±1x per detik selama siswa menghadap kamera. |
| Halaman absen | `frontend/src/app/absen/[slug]/page.tsx` |
| Validasi & pencocokan (server) | `app/Services/Attendance/FaceMatcher.php`, dipanggil dari `DailyAttendanceService::selfCheckIn` |
| Ambang & masa simpan foto | `config/services.php` → `face.max_distance` (`FACE_MAX_DISTANCE`, default 0,5) dan `face.photo_retention_days` (`FACE_PHOTO_RETENTION_DAYS`, default 30) |
| Link, foto, hapus wajah (staf) | `app/Http/Controllers/Api/StudentFaceController.php` |
| Hapus foto bukti lama | `php artisan attendance:prune-face-photos`, terjadwal tiap hari 03:45 |

**Deteksi kedip:** MediaPipe melacak wajah di setiap frame kamera (GPU, ±20–30 fps di HP) dan
memberi skor kedip per mata (`eyeBlinkLeft`/`eyeBlinkRight`, 0 = terbuka, 1 = tertutup). Kedip =
skor naik 0,25 di atas kondisi terbuka orang itu (minimal 0,35), lalu turun lagi. Patokan "terbuka"
adalah median 15 frame terakhir, sehingga mata sipit atau berkacamata tetap terbaca.

**Kenapa langsung:** ciri wajah (descriptor) sudah dihitung di latar sebelum kedip. Begitu kedip
selesai, descriptor terbaru (maksimal 2,5 detik) dan foto frame setelah kedip langsung dikirim,
lalu layar menampilkan hasil absen. Kedua mesin diunduh dan dipanaskan sejak halaman absen dibuka,
karena frame pertama di GPU butuh ±5 detik untuk menyiapkan shader.

## 4. Data

| Tabel / kolom | Isi |
|---|---|
| `student_faces` (baru) | 1 per siswa: `descriptor` (128 angka, **terenkripsi**, tidak pernah dikirim balik ke browser), `photo_path` (foto acuan), `enrolled_at` |
| `daily_records.face_distance` | Jarak wajah absen ini ke acuan (kosong di absen pertama) |
| `daily_records.photo_path` | Foto saat absen, dihapus otomatis setelah 30 hari |
| `daily_settings.face_required` | Saklar per unit, default **aktif** |
| `daily_settings.qr_required` | **Dihapus** |

Foto disimpan di disk privat (`storage/app/private/absen-wajah/…` dan `…/wajah-siswa/…`) dan hanya bisa
dibuka staf yang berhak melihat siswa itu (`Student::visibleTo`). Wali murid tidak bisa membukanya.

## 5. Aturan perangkat

Dulu: **satu HP = satu NIS per hari** (supaya satu HP tidak dipakai mengabsenkan teman).

Sekarang, untuk unit dengan `face_required` aktif: aturan itu **tidak berlaku**, dan absen wajah
**tidak menyimpan tanda perangkat**. Alasannya, iPad kelas memang dipakai bergantian. Kalau tanda
perangkatnya disimpan, presensi mapel di iPad yang sama akan ikut menolak siswa berikutnya.
Penjaganya sekarang wajah. Unit yang mematikan wajah kembali memakai aturan lama.

## 6. Peran staf

| Peran | Lihat & salin link | Buat / reset link | Lihat foto | Hapus wajah terdaftar |
|---|---|---|---|---|
| Admin pusat | Semua unit (pilih unit) | Ya | Ya | Ya |
| Admin unit | Unitnya | Ya | Ya | Ya |
| Waka kesiswaan / kepala sekolah | Unitnya (menu **Wajah Absen Siswa**) | Tidak | Ya | Ya |
| Guru (wali kelas) | Unitnya (kartu di **Presensi Harian**) | Tidak | Ya | Hanya siswa kelasnya |

**Hapus wajah** dipakai kalau wajah yang terdaftar salah, misalnya absen pertama dilakukan teman,
atau wajah siswa sudah banyak berubah. Absen wajah berikutnya otomatis mendaftarkan wajah baru.
Setiap penghapusan tercatat di Log Aktivitas.

## 7. Batas yang perlu diketahui sekolah

- **Absen pertama dipercaya begitu saja.** Kalau absen pertama dilakukan teman, wajah teman yang terdaftar.
  Wali kelas sebaiknya mengecek foto "Wajah terdaftar" di minggu pertama, lalu menghapus yang salah.
- **Pencocokan dan kedip dihitung di browser.** Siswa yang paham teknik bisa memalsukan data yang dikirim,
  atau menyodorkan video wajah teman. Foto setiap absen tersimpan 30 hari untuk diperiksa.
- **Lokasi iPad tanpa SIM** dibaca dari WiFi dan bisa meleset puluhan sampai ratusan meter. Kalau radius aktif,
  beri radius longgar (±150 m) atau matikan radius untuk unit yang absennya dari iPad kelas.
- **Ambang 0,5** adalah titik awal. Kalau banyak siswa jujur ditolak, naikkan ke 0,55. Kalau ada yang lolos
  memakai wajah teman, turunkan. Ubah lewat `FACE_MAX_DISTANCE` di `.env`.
- **Masker dibuka** saat absen. Cahaya dari belakang (membelakangi jendela) membuat wajah gelap dan sering gagal.
- Presensi **per mapel** tidak berubah: tetap memakai QR dari guru dan aturan perangkat sendiri.

## 8. Menguji dari HP sebelum di-deploy

Kamera di browser wajib HTTPS, jadi HP butuh tunnel. **Jangan pakai ngrok gratis**: ngrok
menampilkan halaman peringatan yang isinya diambil dari `cdn.ngrok.com`, dan di jaringan yang
memblokir CDN itu HP hanya melihat layar putih kosong (bukan error aplikasi). Pakai Cloudflare
quick tunnel (gratis, tanpa akun, tanpa halaman peringatan):

```
winget install --id Cloudflare.cloudflared
cloudflared tunnel --url http://localhost:3000
```

Buka alamat `https://….trycloudflare.com/absen/{slug}` yang dicetak. Domain ini sudah diizinkan
di `allowedDevOrigins` (`frontend/next.config.ts`). Di server produksi tidak ada halaman perantara,
jadi link bisa dibuka di Chrome/Safari mana pun. Browser di dalam aplikasi lain (Instagram,
Facebook, LINE) sering memblokir kamera; halaman absen menampilkan petunjuk "Buka di Chrome/Safari".

## 9. Uji coba yang disarankan

1. Satu kelas SMP dengan iPad kelas dan satu kelas SMA dengan HP, selama 1–2 minggu.
2. Catat: berapa siswa lolos di percobaan pertama, rata-rata waktu per siswa, dan berapa yang ditolak padahal jujur.
3. Wali kelas mengecek foto wajah terdaftar di hari-hari pertama.
4. Sesuaikan ambang bila perlu, lalu nyalakan untuk seluruh unit.
