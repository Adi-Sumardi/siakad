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

## 💳 Gateway Pembayaran: SendagoPay
- **Inbound Webhook Endpoint**: `https://siakad.yapinet.id/api/webhooks/sendagopay`
- **Controller**: `App\Http\Controllers\Api\Webhooks\SendagoPayController`
- **Gateway Service**: `App\Services\Payment\SendagoPayGateway`
- **Verifikasi Keamanan**: HMAC-SHA256 via header `X-Sendago-Signature` menggunakan `SENDAGOPAY_WEBHOOK_SECRET`.
- **Fitur Idempotensi**: Menggunakan tabel `integration_events` untuk mencegah double settlement / pemrosesan ganda.
- **Settlement**: Otomatis melalui `PaymentAllocator` untuk melunasi tagihan yang di-checkout wali murid.
- **Kredensial `.env`**:
  - `SENDAGOPAY_PUBLIC_KEY=sg_live_pk_7d1231d3a1d7861f0243b1fac45fb0ed`
  - `SENDAGOPAY_SECRET_KEY=sg_live_sk_17c0e7d2c90fed0ca129ae3ebfb614eaf7aa39fa11eec804`
  - `SENDAGOPAY_WEBHOOK_SECRET=whsec_live_1048dc2a77e79419ff3684db9e95eb7f8b966dc2`

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
