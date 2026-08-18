{{--
  One template for both faces of a bill: an invoice while it is owed, a receipt
  once it is settled. The rows and totals are identical either way - what
  changes is the heading and whether a paid stamp appears - so there is no
  second layout to keep in step when a fee type gains a component.

  Inline styles and a table layout on purpose: dompdf supports neither modern
  CSS layout nor external stylesheets.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $isPaid ? 'Kuitansi' : 'Tagihan' }} {{ $bill->bill_number }}</title>
    <style>
        @page { margin: 28mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color: #101B33; line-height: 1.45; }
        .muted { color: #4A5875; }
        .right { text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; padding-bottom: 18px; }
        .brand { font-size: 15pt; font-weight: bold; color: #13286B; }
        .doc-title { font-size: 13pt; font-weight: bold; }
        .meta td { padding: 2px 0; font-size: 10pt; }
        .items { margin-top: 18px; }
        .items th {
            text-align: left; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .06em;
            color: #4A5875; border-bottom: 1px solid #E1E8F6; padding: 6px 0;
        }
        .items td { padding: 7px 0; border-bottom: 1px solid #F0F4FC; }
        .totals td { padding: 3px 0; }
        .grand td { border-top: 1.5px solid #101B33; padding-top: 7px; font-weight: bold; font-size: 11.5pt; }
        .stamp {
            display: inline-block; border: 2px solid #16745B; color: #16745B;
            padding: 5px 14px; font-weight: bold; letter-spacing: .08em; font-size: 12pt;
        }
        .foot { margin-top: 26px; font-size: 8.5pt; color: #4A5875; }
    </style>
</head>
<body>

<table class="head">
    <tr>
        <td>
            <div class="brand">{{ $schoolName }}</div>
            <div class="muted">{{ $bill->student->schoolUnit?->label }}</div>
        </td>
        <td class="right">
            <div class="doc-title">{{ $isPaid ? 'KUITANSI' : 'TAGIHAN' }}</div>
            <div class="muted">{{ $bill->bill_number }}</div>
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td class="muted" style="width: 22%">Nama siswa</td>
        <td style="width: 45%"><strong>{{ $bill->student->nama_lengkap }}</strong></td>
        <td class="muted" style="width: 15%">Diterbitkan</td>
        <td>{{ $bill->issued_at?->translatedFormat('d F Y') }}</td>
    </tr>
    <tr>
        <td class="muted">NIS</td>
        <td>{{ $bill->student->nis ?? '—' }}</td>
        <td class="muted">Jatuh tempo</td>
        <td>{{ $bill->due_date?->translatedFormat('d F Y') }}</td>
    </tr>
    <tr>
        <td class="muted">Kelas</td>
        <td>{{ $kelas ?? '—' }}</td>
        <td class="muted">Tahun ajaran</td>
        <td>{{ $bill->academicYear?->year }}</td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th>Keterangan</th>
            <th class="right" style="width: 12%">Qty</th>
            <th class="right" style="width: 20%">Harga</th>
            <th class="right" style="width: 22%">Jumlah</th>
        </tr>
    </thead>
    <tbody>
        {{-- Discounts are lines too, with a negative amount, so these rows
             always add up to the total without an explanatory footnote. --}}
        @foreach ($bill->lines as $line)
            <tr>
                <td>
                    {{ $line->name }}
                    @if ($line->size_option)
                        <span class="muted">· ukuran {{ $line->size_option }}</span>
                    @endif
                </td>
                <td class="right">{{ $line->qty }}</td>
                <td class="right">{{ $money($line->unit_price) }}</td>
                <td class="right">{{ $money($line->amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals" style="margin-top: 14px; width: 55%; margin-left: 45%;">
    @if ($bill->discount_amount > 0)
        <tr>
            <td class="muted">Subtotal</td>
            <td class="right">{{ $money($bill->subtotal) }}</td>
        </tr>
        <tr>
            <td class="muted">Potongan</td>
            <td class="right">− {{ $money($bill->discount_amount) }}</td>
        </tr>
    @endif
    @if ($bill->late_fee > 0)
        <tr>
            <td class="muted">Denda keterlambatan</td>
            <td class="right">{{ $money($bill->late_fee) }}</td>
        </tr>
    @endif
    <tr class="grand">
        <td>Total</td>
        <td class="right">{{ $money($bill->total_amount) }}</td>
    </tr>
    @if ($bill->paid_amount > 0 && ! $isPaid)
        <tr>
            <td class="muted">Sudah dibayar</td>
            <td class="right">− {{ $money($bill->paid_amount) }}</td>
        </tr>
        <tr class="grand">
            <td>Sisa</td>
            <td class="right">{{ $money($bill->remaining_amount) }}</td>
        </tr>
    @endif
</table>

@if ($isPaid)
    <div style="margin-top: 22px">
        <span class="stamp">LUNAS</span>
        <div class="muted" style="margin-top: 6px">
            Dibayar {{ $bill->paid_at?->translatedFormat('d F Y') }}
            @if ($payments->isNotEmpty())
                · {{ $payments->pluck('payment_number')->join(', ') }}
            @endif
        </div>
    </div>
@endif

<div class="foot">
    Dokumen ini diterbitkan otomatis oleh sistem dan sah tanpa tanda tangan.
    @unless ($isPaid)
        Abaikan bila pembayaran sudah dilakukan.
    @endunless
</div>

</body>
</html>
