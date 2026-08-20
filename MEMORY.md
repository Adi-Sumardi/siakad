# MEMORY: SIAKAD YAPI (Sistem Informasi Akademik)

## 📌 Ringkasan Proyek
- **Aplikasi**: SIAKAD YAPI (Akademik, Tagihan/SPP, Rekap Poin, Prestasi, Pengumuman, Portal Wali & Guru)
- **Arsitektur**:
  - Backend: Laravel 11 (PHP 8.4) Headless REST API
  - Frontend: Next.js 14 (App Router, Tailwind CSS, TypeScript)
  - Database: PostgreSQL 17 (Containerized Docker)
  - Reverse Proxy: Nginx Container (`127.0.0.1:8091`) di-proxy oleh Host Nginx dengan SSL Let's Encrypt

---

## 🌐 Server Produksi & Konfigurasi Host
- **IP VPS**: `103.94.239.109` (Port 22, User: `odoo-yapi`)
- **SSH Key**: `/Users/yapi/Adi/SSH/y4p1.pem`
- **Domain SIAKAD**: `https://siakad.yapinet.id` (Port internal Docker: `8091`)
- **Co-existing Apps**: **Odoo 18 Akuntansi** (`https://akunting.yapinet.id` di port host 8069 & 8072, DB Postgres 14 host) -> **TIDAK BOLEH DIGANGGU**.

---

## 🚀 Skrip Deployment & Pembaruan
- `deploy.sh`: Skrip instalasi lengkap / full setup dari macOS ke VPS.
- `update.sh`: Skrip pembaruan cepat satu perintah (rsync, docker build/restart, migrasi DB, refresh cache, sync volume aset statis).
- `scripts/setup-ssl.sh`: Otomasi setup & renew sertifikat SSL Let's Encrypt.
- `scripts/nginx-siakad.conf`: Template Nginx host.

---

## 💳 Fitur Keuangan & Pembayaran
- **Gateway**: SendagoPay Checkout & QRIS Invoices.
- **Inbound Webhook Endpoint**: `https://siakad.yapinet.id/api/webhooks/sendagopay`
- **Multi-Payment**: Wali murid dapat memilih banyak tagihan (multi-bulan / multi-anak) dan membayar sekaligus dalam 1x checkout SendagoPay.
- **Custom / Partial Payment**: Mendukung pembayaran cicilan atau nominal custom per tagihan (`allow_installment = true`).
- **Kelola SPP & Tarif**: `/admin/tarif` (Katalog jenis biaya, tarif per unit, per tingkat, per tahun ajaran, modal kelola tahun ajaran, dan import tarif CSV).
- **Kelola Diskon & Beasiswa**: `/admin/diskon` (CRUD skema diskon persen/nominal dan penetapan diskon siswa per tahun ajaran).
- **Generate SPP Massal**: `/admin/generate` (Pratinjau batch & generate tagihan bulanan dengan diskon otomatis terhitung).
- **Filter Tahun Ajaran & Persiapan 2027/2028**:
  - Pilihan filter tahun ajaran di seluruh modul admin (`/admin/siswa`, `/admin/tarif`, `/admin/tagihan`, `/admin/page.tsx`).
  - Master data tahun ajaran telah siap untuk `2026/2027`, `2027/2028`, dan `2028/2029`.
- **Fitur Import Massal (CSV/Excel)**:
  - **Import Data Siswa**: `/admin/siswa` (Upload CSV, auto match unit, buat kelas & rombel, link akun wali murid, serta unduh template CSV).
  - **Import Tarif SPP & Biaya**: `/admin/tarif` (Upload CSV nominal per unit, tingkat, dan tahun ajaran serta unduh template CSV).
- **Grafik & Analytics Dashboard**: `/admin` (Ringkasan)
  - **Grafik Arus Tagihan/Piutang per Unit Sekolah**: Dilengkapi filter custom date range (tanggal mulai s/d tanggal selesai) dan filter tahun ajaran.
  - **Grafik Capaian Prestasi per Unit Sekolah**: Dilengkapi filter prestasi murid vs prestasi guru dan rincian perolehan tingkat kejuaraan.

---

## 🎨 Desain UI & Layout Responsif
- **Fullspan Modern Layout**: Seluruh portal (Admin, Guru, Wali) memanfaatkan lebar monitor besar (`w-full max-w-7xl 2xl:max-w-full`) dengan padding terstruktur.
- **Mobile First & Slide-over Drawer**:
  - `StaffShell` (`/admin` & `/guru`): Topbar responsif + drawer navigasi hamburger dengan backdrop blur.
  - `WaliShell` (`/dashboard`, `/tagihan`, `/pembayaran`, `/informasi`, `/anak/[ulid]`): Header terpadu + bottom quick-navigation bar + slide-over drawer di layar mobile.
- **Favicon Resmi**: Logo YAPI (`logo-yapi.png`) terpasang pada metadata icon dan apple-touch-icon.

---

## 📲 Gateway Notifikasi & Integrasi Lainnya
- **WhatsApp Gateway**: `https://api-sendago.adilabs.id` (Sendago WhatsApp)
- **Email Gateway**: `https://sendagomail.adilabs.id` (Sendago Mail)
- **PMB Handoff**: `POST /api/webhooks/pmb/students` (HMAC SHA-256)
- **Admin Akun**: `adisumardi888@gmail.com` (Role: Super Admin / Administrator)
- **8 Unit Sekolah (Landing Page)**:
  1. RA Sakinah
  2. Playgroup Sakinah
  3. TK Islam Al Azhar 13
  4. SD Islam Al Azhar 13
  5. SMP Islam Al Azhar 12
  6. SMP Islam Al Azhar 55
  7. SMA Islam Al Azhar 33
  8. SMA Islam Al Azhar 48
