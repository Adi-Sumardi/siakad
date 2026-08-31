# SIAKAD YAPI — Aplikasi Sekolah (lanjutan PMB)

Aplikasi yang mengambil alih siswa **setelah** proses PMB selesai: begitu siswa
dinyatakan diterima **dan uang pangkalnya lunas**, PMB menyerahkan datanya ke sini,
aplikasi ini membuatkan akun orang tua, dan mengirim email undangan aktivasi.

Sejak titik itu, semua hal yang bersifat "anak sekolah" hidup di aplikasi ini:
data siswa & kelas, prestasi, poin (pelanggaran/penghargaan), serta seluruh
keuangan rutin — SPP, seragam, buku, kegiatan, dan tagihan lainnya.

## Status

**Live di produksi** — siakad.yapinet.id. Fase 1–3 selesai (lihat
[docs/06-ROADMAP.md](docs/06-ROADMAP.md)): akun & handoff dari PMB, keuangan
(SPP, diskon, laporan), dan kesiswaan (poin, prestasi, pengumuman), lengkap
dengan frontend admin & guru. Sedang mulai Fase 4 (akademik & penyempurnaan).

## Stack

Sama persis dengan PMB, supaya operasional (deploy, monitoring, orang) tidak
belajar dua hal berbeda:

| Lapis | Pilihan |
|---|---|
| Backend | Laravel 13, PHP 8.3+, API JSON |
| Auth | Sanctum SPA (cookie/session), bukan bearer token |
| Database | PostgreSQL 17 |
| Frontend | Next.js 16 (App Router) + shadcn/ui + Tailwind v4 |
| Pembayaran | Virtual Account Bank Muamalat (e-SPP billing API) — khusus per anak per jenis biaya |
| Notifikasi | Sendago (email + WhatsApp) |
| Deploy | Docker Compose di VPS (103.94.239.109, berbagi box dengan Odoo 18), `update.sh` untuk update rutin |

## Dokumen

| File | Isi |
|---|---|
| [docs/01-ARSITEKTUR.md](docs/01-ARSITEKTUR.md) | Batas sistem, keputusan desain, deployment |
| [docs/02-INTEGRASI-PMB.md](docs/02-INTEGRASI-PMB.md) | Handoff pengumuman → akun → email undangan |
| [docs/03-ERD.md](docs/03-ERD.md) | Skema database lengkap per tabel + diagram |
| [docs/04-API.md](docs/04-API.md) | Kontrak endpoint backend |
| [docs/05-UI.md](docs/05-UI.md) | Peta halaman Next.js, peran, dan komponen |
| [docs/06-ROADMAP.md](docs/06-ROADMAP.md) | Urutan pengerjaan per fase |

Mockup visual seluruh layar ada di artifact terpisah (lihat 05-UI.md).

## Prinsip yang dibawa dari PMB

Empat aturan ini bukan preferensi gaya — semuanya lahir dari masalah nyata di PMB
dan wajib dipatuhi sejak migration pertama:

1. **Satu sumber kebenaran per konsep.** Status tagihan hidup di satu ledger
   (`payments` + `payment_allocations`), bukan tersebar di kolom cache.
2. **PII terenkripsi tapi tetap bisa di-lookup.** NIK/NISN/no HP disimpan
   terenkripsi + kolom `*_hash` (HMAC-SHA256) untuk unique constraint dan pencarian.
3. **Tidak ada ID numerik di URL/API publik.** Semua resource yang dipegang
   frontend memakai ULID sebagai route key.
4. **Scope unit ditegakkan lewat scope Eloquent**, bukan `if` di tiap controller —
   `->visibleTo($user)` yang lupa dipanggil terlihat jelas saat review, `if` yang
   hilang tidak.
