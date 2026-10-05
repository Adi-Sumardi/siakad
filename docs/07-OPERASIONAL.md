# 07 — Operasional & Runbook

Untuk TU/admin pusat dan siapa pun yang menjaga server produksi
(siakad.yapinet.id).

## Detak scheduler

Container `scheduler` menjalankan `php artisan schedule:work`. Sejak audit
2026-10-05 ia menulis detak satu kali per menit ke cache
(`scheduler:heartbeat`), dan ada command pemeriksa:

```
php artisan schedule:health          # exit 0 = sehat, exit 1 = basi/mati
```

Pasang ini sebagai docker healthcheck atau cek cron eksternal. **Tanpa
alarm ini, scheduler mati tidak terlihat**: SPP bulan itu tidak terbit,
reminder H-7/H-1 hari itu hilang permanen (beat-nya spesifik tanggal), dan
poller VA berhenti mengejar pelunasan.

## Runbook catch-up (scheduler pernah mati)

Semua command terjadwal **idempoten** — aman dijalankan ulang kapan pun.
Urutan pemulihan setelah scheduler kembali hidup:

| Gejala | Command pemulihan |
| --- | --- |
| SPP bulan berjalan tidak terbit | `php artisan bills:generate --type=spp` (dedup_key mencegah dobel) |
| Tagihan lewat tempo belum `overdue` | `php artisan bills:mark-overdue` |
| Denda belum dikenakan | `php artisan bills:apply-late-fees` (pratinjau) lalu `--apply` |
| Reminder terlewat | `php artisan bills:send-reminders` — **catatan**: beat H-7/H-1 yang tanggalnya sudah lewat TIDAK bisa dibuat ulang (klaim `bill_reminders` per hari); yang tertunggak harus dihubungi manual |
| Pelunasan VA belum terbukukan | `php artisan payments:poll-billing-va` (aman diulang; settle idempoten) |
| Presensi sesi tidak kebuka/ditutup | `php artisan attendance:daily-sweep` |
| Notifikasi gagal belum di-retry | `php artisan notifications:retry-failed` |

## Kredensial gateway kosong = mode log-only (bukan hijau)

Saat `QONTAK_*`, `SENDAGO*`, atau `SENDAGOMAIL_*` kosong, semua pengiriman
dicatat **`sent` dengan catatan "Mode log-only"** — tidak ada yang benar-benar
terkirim, dan untuk login via nomor HP berarti OTP tidak pernah sampai.
Layar monitoring (ringkasan kegagalan notifikasi) punya penghitung
`log_only_7d` terpisah sejak audit 2026-10-05: angka itu non-nol berarti
perbaiki env gateway, bukan periksa penerima.

## Refund overpayment (TU)

`GET /api/admin/payments/overpayments` adalah worklist pembayaran ganda
(money masuk untuk tagihan yang sudah lunas lewat VA lain). Setelah transfer
pengembalian dana benar-benar dikirim dari rekening sekolah, tandai
`POST /api/admin/payments/{ulid}/refund` — pembayaran menjadi `refunded`
dan keluar dari worklist. Refund parsial (pembayaran yang sebagian melunasi
tagihan) tetap manual oleh pusat.

## Template Qontak (transisi 5 → 4 variabel)

Kode `BillReminderSender` mengirim 4 variabel + padding kosong sebanyak
`QONTAK_SPP_REMINDER_PLACEHOLDER_VARS` (default 1) selama template
`reminder_spp_school` di sisi Qontak masih mendeklarasikan 5 variabel.
**Setelah template 4-variabel disetujui di Qontak, set env itu ke 0.**
Padding sengaja dibiarkan agar mismatch jumlah variabel tidak membatalkan
broadcast (beat reminder sudah di-claim sebelum kirim — gagal = hilang
harian itu).

## Jendela pantau VA terlantar

VA yang sudah di-fail/expire tetap bisa dibayar di bank sampai `date_end`
aslinya (endpoint tutup e-SPP rusak). Poller mengawasi pembayaran mengejut
selama `BILLING_API_SUPERSEDED_WATCH_DAYS` hari (default 60) setelah
kedaluwarsa; temuan masuk layar monitoring sebagai event
`payment.surprise_late` berstatus failed → proses refund manual oleh TU.
