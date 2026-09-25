<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kuitansi Pembayaran</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1f2937; padding: 10mm 12mm; }
        .kop { display: flex; align-items: center; gap: 6mm; border-bottom: 2.5px solid #14532D; padding-bottom: 3mm; margin-bottom: 4mm; }
        .kop img { height: 15mm; }
        .kop h1 { font-size: 12pt; color: #14532D; letter-spacing: 0.5px; }
        .kop p { font-size: 7pt; color: #4b5563; }
        .stamp { margin: 3mm 0; padding: 2.5mm 4mm; background: #14532D; color: #fff; font-weight: bold; font-size: 11pt; text-align: center; letter-spacing: 1px; }
        .meta { width: 100%; margin-bottom: 3mm; }
        .meta td { padding: 1mm 0; vertical-align: top; font-size: 8.5pt; }
        .meta .label { color: #6b7280; width: 38mm; }
        .meta .val { font-weight: bold; }
        table.items { width: 100%; border-collapse: collapse; margin: 2mm 0 3mm; }
        table.items th { background: #ecfdf5; color: #14532D; text-align: left; padding: 1.8mm 2.5mm; font-size: 8pt; border-bottom: 1px solid #a7f3d0; }
        table.items td { padding: 1.8mm 2.5mm; font-size: 8.5pt; border-bottom: 0.5px solid #e5e7eb; }
        table.items .right { text-align: right; }
        .total { display: flex; justify-content: space-between; padding: 2.5mm 3mm; background: #14532D; color: #fff; font-size: 11pt; font-weight: bold; margin-top: 1mm; }
        .note { font-size: 7pt; color: #6b7280; margin-top: 3mm; line-height: 1.5; }
        .footer { margin-top: 5mm; padding-top: 2mm; border-top: 0.5px solid #d1d5db; font-size: 6.5pt; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="kop">
        @if ($logoBase64)<img src="{{ $logoBase64 }}" alt="YAPI">@endif
        @if ($logoYpiaBase64)<img src="{{ $logoYpiaBase64 }}" alt="YPIA">@endif
        <div>
            <h1>YAYASAN PENDIDIKAN ISLAM AL-AZHAR (YAPI)</h1>
            <p>Kuitansi Pembayaran Siswa — Bukti Transaksi Sah</p>
        </div>
    </div>

    <div class="stamp">LUNAS</div>

    <table class="meta">
        <tr>
            <td class="label">No. Referensi</td>
            <td class="val">{{ $reference }}</td>
        </tr>
        <tr>
            <td class="label">No. Pembayaran</td>
            <td>{{ $payment->payment_number }}</td>
        </tr>
        <tr>
            <td class="label">Tanggal Bayar</td>
            <td class="val">{{ $payment->paid_at?->translatedFormat('d F Y, H.i') ?? '-' }} WIB</td>
        </tr>
        <tr>
            <td class="label">Metode</td>
            <td>{{ $bankName }}</td>
        </tr>
        <tr>
            <td class="label">Siswa</td>
            <td class="val">
                @foreach ($students as $student)
                    {{ $student->nama_lengkap }}{{ $student->schoolUnit?->label ? ' ('.$student->schoolUnit->label.')' : '' }}@if (! $loop->last)<br>@endif
                @endforeach
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Rincian Tagihan</th>
                <th class="right">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @php
                $allocations = $payment->allocations()->with('bill.feeType')->get();
            @endphp
            @foreach ($allocations as $allocation)
                <tr>
                    <td>
                        {{ $allocation->bill?->description ?? $allocation->bill?->bill_number }}
                        <span style="color:#6b7280;">· {{ $allocation->bill?->bill_number }}</span>
                    </td>
                    <td class="right">{{ $money((float) $allocation->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total">
        <span>TOTAL DIBAYAR</span>
        <span>{{ $money((float) $payment->amount) }}</span>
    </div>

    <p class="note">
        Kuitansi ini merupakan bukti pembayaran sah yang diterbitkan secara otomatis oleh Sistem Informasi
        Akademik YAPI Al-Azhar. Dokumen ini tidak memerlukan tanda tangan basah.
        Simpan baik-baik sebagai arsip pembayaran Anda.
    </p>

    <div class="footer">
        Dicetak pada {{ now()->translatedFormat('d F Y H.i') }} WIB · SIAKAD YAPI Al-Azhar
    </div>
</body>
</html>
