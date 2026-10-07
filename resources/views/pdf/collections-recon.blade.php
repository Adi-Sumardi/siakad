<!DOCTYPE html>
<html lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Recon Penerimaan</title>
    @php
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $typeCount = count($fee_types);
        // Unit, per type (trx + jumlah), Muamalat, BSI, Lainnya, total trx + jumlah.
        $summaryCols = 1 + ($typeCount * 2) + 3 + 2;
    @endphp
    <style>
        @page {
            margin: 10mm 8mm 12mm;
            size: a4 landscape;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8pt;
            color: #1a1a1a;
        }
        .header {
            text-align: center;
            margin-bottom: 3mm;
        }
        .header .org {
            font-size: 12pt;
            font-weight: bold;
            color: #1e3a8a;
        }
        .header .title {
            font-size: 10pt;
            font-weight: bold;
            color: #1e3a8a;
            margin-top: 2px;
        }
        .header hr {
            border: none;
            border-top: 1.5px solid #1e3a8a;
            margin-top: 3mm;
        }
        table.meta {
            margin-bottom: 3mm;
            font-size: 8pt;
        }
        table.meta td {
            padding: 1px 6px 1px 0;
        }
        table.meta td.label {
            color: #64748b;
        }
        h2 {
            font-size: 10pt;
            color: #1e3a8a;
            margin: 0 0 2mm;
        }
        table.grid {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        table.grid th, table.grid td {
            border: 1px solid #cbd5e1;
            padding: 2px 4px;
            word-wrap: break-word;
        }
        table.grid thead th {
            background: #1e3a8a;
            color: #fff;
            text-align: center;
            font-weight: bold;
        }
        table.grid td.center {
            text-align: center;
        }
        table.grid td.num {
            text-align: right;
        }
        table.grid tbody tr.alt {
            background: #f1f5f9;
        }
        table.grid tr.total td {
            font-weight: bold;
            background: #e2e8f0;
        }
        .warn {
            color: #b91c1c;
        }
        .unit-page {
            page-break-before: always;
        }
        .note {
            margin-top: 3mm;
            font-size: 7pt;
            color: #64748b;
        }
        .footer {
            margin-top: 4mm;
            font-size: 7pt;
            color: #64748b;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="org">YAYASAN ASRAMA PELAJAR ISLAM (YAPI)</div>
        <div class="title">RECON PENERIMAAN PEMBAYARAN SIAKAD YAPI AL AZHAR</div>
        <hr>
    </div>

    <table class="meta">
        <tr><td class="label">Tanggal bayar</td><td>: {{ $period_label }}</td></tr>
        @foreach ($filters as $label => $value)
            <tr><td class="label">{{ $label }}</td><td>: {{ $value }}</td></tr>
        @endforeach
    </table>

    <h2>Ringkasan per Unit</h2>
    <table class="grid">
        <thead>
            <tr>
                <th rowspan="2" style="width: 22%">Unit</th>
                @foreach ($fee_types as $label)
                    <th colspan="2">{{ $label }}</th>
                @endforeach
                <th rowspan="2">Bank Muamalat</th>
                <th rowspan="2">BSI</th>
                <th rowspan="2">Lainnya</th>
                <th colspan="2">Total</th>
            </tr>
            <tr>
                @foreach ($fee_types as $label)
                    <th style="width: 5%">Trx</th>
                    <th>Jumlah</th>
                @endforeach
                <th style="width: 5%">Trx</th>
                <th>Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($units as $i => $unit)
                <tr class="{{ $i % 2 ? 'alt' : '' }}">
                    <td>{{ $unit['unit'] }}</td>
                    @foreach ($fee_types as $type => $label)
                        <td class="center">{{ $unit['by_type'][$type]['count'] }}</td>
                        <td class="num">{{ $rp($unit['by_type'][$type]['amount']) }}</td>
                    @endforeach
                    <td class="num">{{ $rp($unit['by_bank']['muamalat']['amount']) }}</td>
                    <td class="num">{{ $rp($unit['by_bank']['bsi']['amount']) }}</td>
                    <td class="num">{{ $rp($unit['by_bank']['other']['amount']) }}</td>
                    <td class="center">{{ $unit['count'] }}</td>
                    <td class="num">{{ $rp($unit['amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $summaryCols }}" class="center">Tidak ada pembayaran lunas pada periode ini.</td></tr>
            @endforelse
        </tbody>
        <tbody>
            <tr class="total">
                <td>TOTAL</td>
                @foreach ($fee_types as $type => $label)
                    <td class="center">{{ $grand['by_type'][$type]['count'] }}</td>
                    <td class="num">{{ $rp($grand['by_type'][$type]['amount']) }}</td>
                @endforeach
                <td class="num">{{ $rp($grand['by_bank']['muamalat']['amount']) }}</td>
                <td class="num">{{ $rp($grand['by_bank']['bsi']['amount']) }}</td>
                <td class="num">{{ $rp($grand['by_bank']['other']['amount']) }}</td>
                <td class="center">{{ $grand['count'] }}</td>
                <td class="num">Rp {{ $rp($grand['amount']) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="note">
        Kolom Bank Muamalat dan BSI adalah dana yang masuk ke masing-masing rekening, untuk dicocokkan dengan rekening koran.
        Lainnya = pembayaran di luar VA, atau VA yang banknya belum tercatat.
        Satu transfer yang melunasi tagihan beberapa anak tampil per anak dengan No. Pembayaran yang sama.
        Tanggal bayar = saat pembayaran tercatat lunas di SIAKAD (WIB).
    </p>

    @foreach ($units as $unit)
        <div class="unit-page">
            <h2>{{ $unit['unit'] }}</h2>
            <table class="grid">
                <thead>
                    <tr>
                        <th style="width: 3%">No</th>
                        <th style="width: 9%">Tgl Bayar</th>
                        <th style="width: 7%">NIS</th>
                        <th style="width: 15%">Nama Murid</th>
                        <th style="width: 12%">Tagihan</th>
                        <th style="width: 7%">Bank</th>
                        <th style="width: 11%">No. VA</th>
                        <th style="width: 16%">Ref. Bank</th>
                        <th style="width: 9%">No. Pembayaran</th>
                        <th style="width: 11%">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($unit['rows'] as $i => $row)
                        <tr class="{{ $i % 2 ? 'alt' : '' }}">
                            <td class="center">{{ $i + 1 }}</td>
                            <td class="center">{{ $row['paid_at'] }}</td>
                            <td class="center">{{ $row['nis'] }}</td>
                            <td>{{ $row['nama'] }}</td>
                            <td>{{ $row['tagihan'] }}</td>
                            <td class="{{ $row['bank'] ? '' : 'warn' }}">{{ $row['bank_label'] }}</td>
                            <td class="center">{{ $row['va_number'] }}</td>
                            <td class="center">{{ $row['reference'] }}</td>
                            <td class="center">{{ $row['payment_number'] }}</td>
                            <td class="num">{{ $rp($row['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tbody>
                    <tr class="total">
                        <td colspan="9" class="num">
                            Subtotal {{ $unit['unit'] }} ({{ $unit['count'] }} trx &middot; Muamalat Rp {{ $rp($unit['by_bank']['muamalat']['amount']) }} &middot; BSI Rp {{ $rp($unit['by_bank']['bsi']['amount']) }})
                        </td>
                        <td class="num">Rp {{ $rp($unit['amount']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endforeach

    <div class="footer">Dicetak {{ $printed_at }} oleh {{ $printed_by }}</div>
</body>
</html>
