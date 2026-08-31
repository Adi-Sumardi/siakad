<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $isPaid ? 'Kuitansi' : 'Tagihan' }} {{ $bill->bill_number }}</title>
    <style>
        @page { margin: 20mm 15mm 15mm 15mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #101B33; line-height: 1.45; }
        .muted { color: #5B6B86; }
        .right { text-align: right; }
        .center { text-align: center; }
        table { width: 100%; border-collapse: collapse; }
        
        .header-table { border-bottom: 2px solid #13286B; padding-bottom: 12px; margin-bottom: 16px; }
        .header-logo { width: 68px; vertical-align: middle; }
        .header-logo img { width: 64px; height: auto; }
        .header-text { vertical-align: middle; padding-left: 12px; }
        .org-title { font-size: 13pt; font-weight: bold; color: #13286B; letter-spacing: 0.02em; }
        .unit-title { font-size: 11pt; font-weight: bold; color: #1E3A8A; margin-top: 2px; }
        .address-text { font-size: 7.5pt; color: #5B6B86; margin-top: 3px; line-height: 1.3; }

        .doc-badge-table { margin-bottom: 14px; }
        .doc-type { font-size: 14pt; font-weight: bold; color: #13286B; }
        .bill-no { font-size: 10pt; font-weight: bold; color: #374151; }

        .meta-box { background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 10px; margin-bottom: 14px; }
        .meta-box td { padding: 2px 4px; font-size: 8.5pt; }

        .items { margin-top: 10px; width: 100%; }
        .items th {
            text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .06em;
            color: #1E293B; background-color: #F1F5F9; border-top: 1px solid #CBD5E1; border-bottom: 1px solid #CBD5E1; padding: 7px 6px;
        }
        .items td { padding: 7px 6px; border-bottom: 1px solid #E2E8F0; font-size: 8.5pt; }

        .totals { margin-top: 10px; width: 50%; margin-left: 50%; }
        .totals td { padding: 3px 6px; font-size: 8.5pt; }
        .grand td { border-top: 1.5px solid #13286B; border-bottom: 1.5px solid #13286B; padding: 6px; font-weight: bold; font-size: 10.5pt; color: #13286B; }

        .stamp-lunas {
            display: inline-block; border: 2.5px solid #166534; color: #166534;
            padding: 6px 18px; font-weight: bold; letter-spacing: .12em; font-size: 13pt;
            border-radius: 4px; background-color: #F0FDF4;
        }

        .pay-info-box {
            margin-top: 16px; background-color: #F0FDF4; border: 1px solid #BBF7D0;
            border-radius: 6px; padding: 10px 12px; font-size: 8pt; color: #166534;
        }
        .pay-info-box h4 { margin: 0 0 6px 0; font-size: 8.5pt; font-weight: bold; color: #14532D; }
        .bank-item { margin-bottom: 4px; font-size: 8pt; }

        .foot {
            margin-top: 20px; font-size: 7.5pt; color: #64748B; border-top: 1px solid #E2E8F0;
            padding-top: 8px; text-align: center;
        }
    </style>
</head>
<body>

{{-- Header Kop Surat Yayasan --}}
<table class="header-table">
    <tr>
        @if (!empty($logoBase64))
            <td class="header-logo">
                <img src="{{ $logoBase64 }}" alt="Logo YAPI" />
            </td>
        @endif
        <td class="header-text">
            <div class="org-title">YAYASAN ASRAMA PELAJAR ISLAM (YAPI) JAKARTA</div>
            <div class="unit-title">{{ $bill->student->schoolUnit?->label ?? 'Unit Pendidikan Al Azhar Rawamangun' }}</div>
            <div class="address-text">
                Jl. Sunan Giri No. 5A, Rawamangun, Pulo Gadung, Jakarta Timur 13220<br>
                Telp: (021) 4786-0000 | Email: keuangan@yapinet.id | Portal: https://siakad.yapinet.id
            </div>
        </td>
    </tr>
</table>

{{-- Dokumen Judul & Nomor --}}
<table class="doc-badge-table">
    <tr>
        <td>
            <div class="doc-type">{{ $isPaid ? 'KUITANSI PEMBAYARAN' : 'INVOICE / TAGIHAN SEKOLAH' }}</div>
            <div class="muted" style="font-size: 8.5pt; margin-top: 2px;">Status: <strong>{{ $isPaid ? 'LUNAS' : ($bill->status === 'partial' ? 'KURANG BAYAR (CICILAN)' : 'BELUM LUNAS') }}</strong></div>
        </td>
        <td class="right">
            <div class="bill-no">No. {{ $bill->bill_number }}</div>
            <div class="muted" style="font-size: 8pt; margin-top: 2px;">Diterbitkan: {{ $bill->issued_at?->translatedFormat('d F Y') ?? now()->translatedFormat('d F Y') }}</div>
        </td>
    </tr>
</table>

{{-- Informasi Siswa & Tagihan --}}
<div class="meta-box">
    <table>
        <tr>
            <td class="muted" style="width: 18%">Nama Siswa</td>
            <td style="width: 36%"><strong>{{ $bill->student->nama_lengkap }}</strong></td>
            <td class="muted" style="width: 18%">Tahun Ajaran</td>
            <td style="width: 28%"><strong>{{ $bill->academicYear?->year ?? '2026/2027' }}</strong></td>
        </tr>
        <tr>
            <td class="muted">NIS / ID Siswa</td>
            <td>{{ $bill->student->nis ?? '—' }}</td>
            <td class="muted">Jatuh Tempo</td>
            <td><strong style="color: #991B1B;">{{ $bill->due_date?->translatedFormat('d F Y') ?? '—' }}</strong></td>
        </tr>
        <tr>
            <td class="muted">Jenjang / Kelas</td>
            <td>{{ $bill->student->schoolUnit?->label }} · Kelas {{ $kelas ?? '—' }}</td>
            <td class="muted">Jenis Tagihan</td>
            <td>{{ $bill->feeType?->name ?? 'SPP Bulanan' }}</td>
        </tr>
    </table>
</div>

{{-- Rincian Komponen Biaya --}}
<table class="items">
    <thead>
        <tr>
            <th style="width: 5%">No</th>
            <th>Rincian Biaya / Deskripsi Komponen</th>
            <th class="right" style="width: 10%">Qty</th>
            <th class="right" style="width: 22%">Tarif Satuan</th>
            <th class="right" style="width: 24%">Jumlah</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($bill->lines as $index => $line)
            <tr>
                <td class="center">{{ $index + 1 }}</td>
                <td>
                    <strong>{{ $line->name }}</strong>
                    @if ($line->size_option)
                        <span class="muted">(Ukuran {{ $line->size_option }})</span>
                    @endif
                </td>
                <td class="right">{{ $line->qty }}</td>
                <td class="right">{{ $money($line->unit_price) }}</td>
                <td class="right">{{ $money($line->amount) }}</td>
            </tr>
        @empty
            <tr>
                <td class="center">1</td>
                <td><strong>{{ $bill->description }}</strong></td>
                <td class="right">1</td>
                <td class="right">{{ $money($bill->subtotal) }}</td>
                <td class="right">{{ $money($bill->subtotal) }}</td>
            </tr>
        @endforelse
    </tbody>
</table>

{{-- Rekapitulasi Total --}}
<table class="totals">
    @if ($bill->discount_amount > 0)
        <tr>
            <td class="muted">Subtotal Tagihan</td>
            <td class="right">{{ $money($bill->subtotal) }}</td>
        </tr>
        <tr>
            <td class="muted" style="color: #166534;">Potongan / Diskon Beasiswa</td>
            <td class="right" style="color: #166534;">− {{ $money($bill->discount_amount) }}</td>
        </tr>
    @endif
    @if ($bill->late_fee > 0)
        <tr>
            <td class="muted">Denda Keterlambatan</td>
            <td class="right">{{ $money($bill->late_fee) }}</td>
        </tr>
    @endif
    <tr class="grand">
        <td>Total Tagihan</td>
        <td class="right">{{ $money($bill->total_amount) }}</td>
    </tr>
    @if ($bill->paid_amount > 0 && ! $isPaid)
        <tr>
            <td class="muted">Sudah Dibayar (Cicilan)</td>
            <td class="right" style="color: #166534;">− {{ $money($bill->paid_amount) }}</td>
        </tr>
        <tr class="grand" style="color: #991B1B;">
            <td>Sisa Kewajiban</td>
            <td class="right">{{ $money($bill->remaining_amount) }}</td>
        </tr>
    @endif
</table>

@if ($isPaid)
    <div style="margin-top: 16px;">
        <span class="stamp-lunas">LUNAS</span>
        <div class="muted" style="margin-top: 6px; font-size: 8.5pt;">
            Diverifikasi lunas pada {{ $bill->paid_at?->translatedFormat('d F Y H:i') }} WIB
            @if ($payments->isNotEmpty())
                · Ref: {{ $payments->pluck('payment_number')->join(', ') }}
            @endif
        </div>
    </div>
@else
    {{-- Informasi Pembayaran Resmi --}}
    <div class="pay-info-box">
        <h4>Cara Pembayaran:</h4>
        @if ($vaNumber)
            <div class="bank-item">
                <strong>Virtual Account Bank Muamalat (BMI)</strong> — khusus untuk {{ $bill->student->nama_lengkap }},
                berlaku untuk semua tagihan {{ $bill->feeType?->name ?? 'jenis biaya ini' }} tahun ajaran
                {{ $bill->academicYear?->year }}:
            </div>
            <div style="margin-top: 6px; padding: 8px 10px; background-color: #FFFFFF; border: 1.5px solid #166534; border-radius: 4px;">
                <span style="font-size: 13pt; font-weight: bold; letter-spacing: 0.06em; color: #14532D;">{{ $vaNumber }}</span>
            </div>
            <div class="bank-item" style="margin-top: 8px;">
                Transfer dari bank/e-wallet mana pun ke nomor Virtual Account di atas. Nomor ini tetap sama untuk
                pembayaran berikutnya jenis biaya yang sama.
            </div>
        @else
            <div class="bank-item">
                Buka portal <a href="https://siakad.yapinet.id/tagihan" style="color: #14532D; text-decoration: underline;">https://siakad.yapinet.id/tagihan</a>
                untuk melihat nomor Virtual Account pembayaran Anda.
            </div>
        @endif
        <div style="margin-top: 8px; font-size: 7.5pt; color: #14532D;">
            *Konfirmasi / Bantuan Layanan Keuangan WhatsApp: <strong>0812-9270-2075</strong> | Email: <strong>keuangan@yapinet.id</strong>
        </div>
    </div>
@endif

<div class="foot">
    Dokumen ini sah diterbitkan otomatis oleh Sistem Informasi Akademik & Keuangan Terpadu (SIAKAD) YAPI Jakarta.<br>
    © {{ date('Y') }} Yayasan Asrama Pelajar Islam (YAPI) Rawamangun Jakarta. All rights reserved.
</div>

</body>
</html>
