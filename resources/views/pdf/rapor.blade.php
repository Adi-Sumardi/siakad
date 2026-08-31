<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rapor {{ $student->nama_lengkap }} - {{ $term->label() }}</title>
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

        .meta-box { background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px; padding: 10px; margin-bottom: 14px; }
        .meta-box td { padding: 2px 4px; font-size: 8.5pt; }

        .items { margin-top: 10px; width: 100%; }
        .items th {
            text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: .06em;
            color: #1E293B; background-color: #F1F5F9; border-top: 1px solid #CBD5E1; border-bottom: 1px solid #CBD5E1; padding: 7px 6px;
        }
        .items td { padding: 7px 6px; border-bottom: 1px solid #E2E8F0; font-size: 8.5pt; }
        .incomplete { color: #9A5C0B; font-style: italic; }

        .summary-row { margin-top: 16px; }
        .summary-box {
            background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 6px;
            padding: 10px 12px; font-size: 8.5pt; width: 47%;
        }
        .summary-box h4 { margin: 0 0 8px 0; font-size: 9pt; font-weight: bold; color: #13286B; }
        .summary-box td { padding: 2px 4px; font-size: 8.5pt; }

        .foot {
            margin-top: 20px; font-size: 7.5pt; color: #64748B; border-top: 1px solid #E2E8F0;
            padding-top: 8px; text-align: center;
        }
    </style>
</head>
<body>

<table class="header-table">
    <tr>
        @if (!empty($logoBase64))
            <td class="header-logo">
                <img src="{{ $logoBase64 }}" alt="Logo YAPI" />
            </td>
        @endif
        <td class="header-text">
            <div class="org-title">YAYASAN ASRAMA PELAJAR ISLAM (YAPI) JAKARTA</div>
            <div class="unit-title">{{ $student->schoolUnit?->label ?? 'Unit Pendidikan Al Azhar Rawamangun' }}</div>
            <div class="address-text">
                Jl. Sunan Giri No. 5A, Rawamangun, Pulo Gadung, Jakarta Timur 13220<br>
                Telp: (021) 4786-0000 | Email: info@yapinet.id | Portal: https://siakad.yapinet.id
            </div>
        </td>
    </tr>
</table>

<table class="doc-badge-table">
    <tr>
        <td>
            <div class="doc-type">LAPORAN HASIL BELAJAR (RAPOR)</div>
            <div class="muted" style="font-size: 8.5pt; margin-top: 2px;">Semester {{ $term->label() }}</div>
        </td>
        <td class="right">
            <div class="muted" style="font-size: 8pt;">Diterbitkan: {{ now()->translatedFormat('d F Y') }}</div>
        </td>
    </tr>
</table>

<div class="meta-box">
    <table>
        <tr>
            <td class="muted" style="width: 18%">Nama Siswa</td>
            <td style="width: 36%"><strong>{{ $student->nama_lengkap }}</strong></td>
            <td class="muted" style="width: 18%">Kelas</td>
            <td style="width: 28%"><strong>{{ $kelas ?? '—' }}</strong></td>
        </tr>
        <tr>
            <td class="muted">NIS</td>
            <td>{{ $student->nis ?? '—' }}</td>
            <td class="muted">Unit</td>
            <td>{{ $student->schoolUnit?->label ?? '—' }}</td>
        </tr>
    </table>
</div>

<table class="items">
    <thead>
        <tr>
            <th style="width: 5%">No</th>
            <th>Mata Pelajaran</th>
            <th class="right" style="width: 15%">Tugas</th>
            <th class="right" style="width: 15%">UTS</th>
            <th class="right" style="width: 15%">UAS</th>
            <th class="right" style="width: 18%">Nilai Akhir</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($subjects as $index => $row)
            <tr>
                <td class="center">{{ $index + 1 }}</td>
                <td><strong>{{ $row['subject']['name'] }}</strong></td>
                <td class="right">{{ $row['tugas'] ?? '—' }}</td>
                <td class="right">{{ $row['uts'] ?? '—' }}</td>
                <td class="right">{{ $row['uas'] ?? '—' }}</td>
                <td class="right">
                    @if ($row['final'] !== null)
                        <strong>{{ $row['final'] }}</strong>
                    @else
                        <span class="incomplete">Belum lengkap</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="center muted">Belum ada mata pelajaran terjadwal untuk kelas ini.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<table class="summary-row">
    <tr>
        <td style="width: 3%"></td>
        <td>
            <div class="summary-box" style="float: left;">
                <h4>Rekap Presensi Semester Ini</h4>
                <table>
                    <tr><td class="muted">Hadir</td><td class="right"><strong>{{ $attendance['hadir'] }}</strong></td></tr>
                    <tr><td class="muted">Sakit</td><td class="right"><strong>{{ $attendance['sakit'] }}</strong></td></tr>
                    <tr><td class="muted">Izin</td><td class="right"><strong>{{ $attendance['izin'] }}</strong></td></tr>
                    <tr><td class="muted">Alpa</td><td class="right"><strong>{{ $attendance['alpa'] }}</strong></td></tr>
                </table>
            </div>
        </td>
        <td style="width: 3%"></td>
        <td>
            <div class="summary-box" style="float: right;">
                <h4>Poin Kedisiplinan</h4>
                <table>
                    <tr>
                        <td class="muted">Saldo Poin Semester Ini</td>
                        <td class="right"><strong>{{ $pointBalance }}</strong></td>
                    </tr>
                </table>
            </div>
        </td>
    </tr>
</table>

<div class="foot">
    Dokumen ini sah diterbitkan otomatis oleh Sistem Informasi Akademik & Keuangan Terpadu (SIAKAD) YAPI Jakarta.<br>
    © {{ date('Y') }} Yayasan Asrama Pelajar Islam (YAPI) Rawamangun Jakarta. All rights reserved.
</div>

</body>
</html>
