# 02 — Integrasi PMB → Sekolah

Alur inti: **diterima → uang pangkal lunas → email berisi akun untuk masuk aplikasi
sekolah.**

## Titik sambung di PMB

Pemicunya adalah tagihan `uang_pangkal` menjadi lunas. Di PMB ada tepat satu tempat
di mana itu terjadi: `StudentBill::updatePaymentStatus()`
(`app/Models/StudentBill.php:183`) — dipanggil baik dari webhook Xendit maupun dari
verifikasi pembayaran manual oleh admin, jadi kedua jalur tercakup tanpa duplikasi
logika.

Yang perlu ditambahkan di PMB ada dua, dan yang pertama adalah **celah yang sudah
ada sekarang**:

**1. Stage `enrolled` belum pernah dipasang otomatis.** Hari ini satu-satunya cara
registrasi sampai ke `enrolled` adalah admin memindahkannya manual lewat
`PendaftarController::updateStage()` / layar Progres. Artinya "sudah lunas uang
pangkal" dan "stage = enrolled" bisa tidak sinkron berhari-hari. Selama handoff
bergantung pada peristiwa itu, transisinya harus otomatis:

```php
// PMB — StudentBill, saat status berubah menjadi 'paid'
if ($this->bill_type === 'uang_pangkal' && $this->payment_status === 'paid') {
    $registration = $this->student->registration;

    if ($registration?->stage === 'uang_pangkal_payment' || $registration?->stage === 'accepted') {
        $registration->moveToStage('enrolled', null, 'Uang pangkal lunas');
    }
}
```

`moveToStage()` sekaligus menulis `registration_stage_history`, jadi lompatan ini
tetap terlihat di layar Progres — bukan perubahan stage yang muncul tanpa sebab.

**2. Kirim handoff ke aplikasi sekolah.**

```php
HandoffStudentToSchoolApp::dispatch($this->student_id);
```

Job, bukan panggilan langsung: kalau aplikasi sekolah sedang mati, retry otomatis
(`tries = 5`, backoff eksponensial) tanpa perlu admin mengulang apa pun. Dan
di-dispatch **setelah** transaksi pembayaran commit (`dispatch()->afterCommit()`),
mengikuti pola yang sudah dipakai `SelectionAnnouncementService`: tidak ada apa pun
yang dikirim untuk perubahan yang masih bisa di-rollback.

Untuk siswa yang membayar uang pangkal secara tunai di sekolah, jalurnya tetap sama —
admin memverifikasi pembayaran manual, `updatePaymentStatus()` jalan, handoff terkirim.

## Kontrak handoff

**Arah:** PMB (klien) → Sekolah (server). PMB mendorong; aplikasi sekolah tidak
pernah menarik dari PMB. Satu arah, jadi hanya satu sisi yang perlu kredensial.

**Endpoint:** `POST https://sekolah.yapinet.id/api/webhooks/pmb/students`

**Auth:** header `X-PMB-Signature: sha256=<hmac>` — HMAC-SHA256 dari raw body dengan
shared secret (`PMB_HANDOFF_SECRET`). Pola yang sama dengan verifikasi webhook Xendit
yang sudah dipakai PMB, jadi tidak ada mekanisme baru untuk dipelajari.

**Body:**

```json
{
  "event": "student.enrolled",
  "event_id": "01JCXYZ...",
  "occurred_at": "2026-08-14T09:00:00+07:00",
  "student": {
    "pmb_ulid": "01JC...",
    "no_pendaftaran": "PMB-2026-00214",
    "nama_lengkap": "Aisyah Nur Ramadhani",
    "nama_panggilan": "Aisyah",
    "jenis_kelamin": "P",
    "tempat_lahir": "Bandung",
    "tanggal_lahir": "2014-03-11",
    "agama": "Islam",
    "nisn": "0123456789",
    "nik": "3273...",
    "alamat_lengkap": "...", "kelurahan": "...", "kecamatan": "...",
    "kota_kabupaten": "...", "provinsi": "...", "kode_pos": "40123",
    "unit_code": "SD-SAKINAH",
    "jenjang": "SD",
    "academic_year": "2026/2027",
    "tingkat_masuk": 1
  },
  "guardians": [
    {
      "hubungan": "ayah", "nama": "Budi Ramadhani",
      "no_hp": "081234567890", "email": "budi@example.com",
      "pekerjaan": "Wiraswasta", "is_primary": true
    },
    { "hubungan": "ibu", "nama": "Siti Aminah", "no_hp": "081298765432", "email": null }
  ],
  "achievements": [
    { "nama_prestasi": "Juara 1 Tahfidz", "kategori": "Non-Akademik",
      "tingkat": "Kabupaten/Kota", "juara": "1", "tanggal_event": "2025-11-02" }
  ],
  "account": { "email": "budi@example.com", "name": "Budi Ramadhani" }
}
```

**Event yang dikirim:**

| Event | Kapan | Efek di aplikasi sekolah |
|---|---|---|
| `student.enrolled` | Uang pangkal lunas → stage `enrolled` | Buat siswa `active` + wali + akun + kirim email undangan. **Satu-satunya pemicu pembuatan akun.** |
| `student.updated` | Data siswa diperbaiki di PMB sebelum tahun ajaran mulai | Perbarui field identitas yang masih dimiliki PMB |
| `student.cancelled` | Registrasi dibatalkan / uang pangkal di-refund | Ubah status siswa ke `dropped_out`, nonaktifkan akun, batalkan tagihan yang belum dibayar |

Pengumuman kelulusan sendiri **tidak** mengirim event apa pun. Yang diterima tapi
belum melunasi uang pangkal tidak pernah muncul di aplikasi ini — mereka masih
sepenuhnya urusan PMB.

**Respons:** `202 Accepted` dengan `{"status":"queued","event_id":"..."}`.
Duplikat `event_id` juga menjawab `202` (bukan error) — retry yang aman.

## Idempotensi

Tabel `integration_events` menyimpan tiap `event_id` dengan unique index. Handler
memakai `firstOrCreate` pada `event_id`; kalau barisnya sudah ada dan
`status = processed`, handler langsung keluar.

Ini penting karena jaringan akan mengirim ulang. Tanpa ini, satu batch pengumuman
yang di-retry akan membuat siswa ganda dan mengirim dua email undangan.

Baris siswa sendiri di-dedup lewat `students.pmb_student_ulid` (unique, nullable).
Siswa yang lahir di aplikasi ini sendiri (mis. siswa pindahan yang tidak lewat PMB)
punya kolom itu `NULL`.

## Kepemilikan data setelah handoff

Aturan yang mencegah dua aplikasi saling menimpa:

| Data | Pemilik | Catatan |
|---|---|---|
| Identitas siswa (nama, NIK, TTL, alamat) | PMB sampai tahun ajaran mulai, lalu **Sekolah** | Setelah itu `student.updated` dari PMB ditolak dengan `409` |
| Data pendaftaran (stage, hasil tes, uang pangkal) | **PMB**, selamanya | Aplikasi sekolah hanya menyimpan salinan baca |
| Kelas, poin, prestasi baru, SPP, tagihan sekolah | **Sekolah**, selamanya | PMB tidak pernah membacanya |
| Prestasi dari formulir PMB | Disalin sekali saat handoff (`source = 'pmb'`) | Tidak disinkron ulang |

## Email undangan akun

Dikirim lewat gateway Sendago yang sama (`MailGateway`), template `school_account_invite`.

Penerima: `guardians` yang `is_primary` dan punya email. Kalau tidak ada email sama
sekali (ada di PMB — sebagian wali hanya punya nomor HP), akun tetap dibuat dengan
username = nomor HP dan undangan dikirim lewat WhatsApp memakai
`SendagoWhatsAppGateway`. Ini bukan kasus tepi; di jenjang PG/TK cukup sering.

**Isi email:**

- Konfirmasi uang pangkal lunas + selamat bergabung, dengan nama anak & unitnya
- Bahwa akun aplikasi sekolah sudah dibuat, dengan email yang dipakai
- Tombol **Aktifkan Akun** → `https://sekolah.yapinet.id/aktivasi?token=...`
- Masa berlaku token: **7 hari**
- Apa yang bisa dilakukan setelah masuk (tagihan, poin, prestasi anak)

**Token:** disimpan sebagai hash di `account_invitations.token_hash`
(plaintext hanya ada di email — bocornya database tidak memberi siapa pun akses akun).
Kedaluwarsa bisa dikirim ulang oleh admin lewat tombol "Kirim ulang undangan"
di daftar siswa, yang membuat token baru dan membatalkan yang lama.

**Password:** tidak ada, sama sekali. Wali murid tidak pernah membuat kata sandi —
tautan undangan langsung mengaktifkan akun dan memulai sesi, dan setiap login
sesudahnya memakai kode sekali pakai yang dikirim ke email atau WhatsApp mereka
(lihat D2 di [01-ARSITEKTUR.md](01-ARSITEKTUR.md)). Undangan yang kedaluwarsa pun
tidak menjadi masalah: wali tetap bisa masuk kapan saja lewat kode.

## Kalau aplikasi sekolah belum siap

Selama Fase 1 belum rilis, `HandoffStudentToSchoolApp` cukup dimatikan lewat flag
`SCHOOL_HANDOFF_ENABLED=false` di PMB.

Siswa yang sudah melunasi uang pangkal sebelum aplikasi ini hidup di-backfill sekali
lewat `php artisan pmb:backfill-handoff --year=2026/2027`. Command itu mencari
berdasarkan **tagihan uang pangkal yang lunas**, bukan stage — karena stage `enrolled`
selama ini dipasang manual, sebagian siswa yang sudah lunas kemungkinan masih
tertinggal di stage lama. Command yang sama sekaligus merapikan stage mereka.
Karena handler-nya idempoten, aman dijalankan berkali-kali.
