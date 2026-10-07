<?php

namespace App\Services\Reporting;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Recon Excel (2026-10-07): "Ringkasan", one sheet per unit, and "Semua
 * Transaksi" for finance to filter themselves. Amounts are numbers so they
 * sum in Excel; VA numbers and bank references are text, or Excel shows
 * 8.02002E+15 and drops digits.
 */
class CollectionReconExcel
{
    private const ROW_HEADERS = ['No', 'Tgl Bayar', 'NIS', 'Nama Murid', 'Tagihan', 'Bank', 'No. VA', 'Ref. Bank', 'No. Pembayaran', 'Jumlah (Rp)'];

    /** @param  array<string, mixed>  $report  CollectionReconService::build() */
    public function render(array $report): string
    {
        $book = new Spreadsheet;
        $this->summary($book->getActiveSheet(), $report);

        $used = ['Ringkasan' => true, 'Semua Transaksi' => true];
        foreach ($report['units'] as $unit) {
            $this->rows($book->createSheet(), $this->uniqueTitle($unit['unit'], $used), $unit['rows'], false);
        }

        $all = collect($report['units'])->flatMap(fn ($u) => $u['rows'])->sortBy('paid_date')->values()->all();
        $this->rows($book->createSheet(), 'Semua Transaksi', $all, true);
        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    private function summary(Worksheet $sheet, array $report): void
    {
        $sheet->setTitle('Ringkasan');
        $lines = [
            ['RECON PENERIMAAN PEMBAYARAN SIAKAD YAPI AL AZHAR'],
            ['Tanggal bayar', $report['period_label']],
        ];
        foreach ($report['filters'] as $label => $value) {
            $lines[] = [$label, $value];
        }
        $lines[] = ['Dicetak', $report['printed_at'].' oleh '.$report['printed_by']];
        $lines[] = [];

        $header = ['Unit'];
        foreach ($report['fee_types'] as $name) {
            array_push($header, "{$name} (Trx)", "{$name} (Rp)");
        }
        array_push($header, 'Bank Muamalat (Rp)', 'BSI (Rp)', 'Lainnya (Rp)', 'Total Trx', 'Total (Rp)');
        $lines[] = $header;
        $headerRow = count($lines);

        foreach ($report['units'] as $unit) {
            $lines[] = $this->totalsLine($unit['unit'], $unit, $report);
        }
        $lines[] = $this->totalsLine('TOTAL', $report['grand'], $report);

        $sheet->fromArray($lines, null, 'A1', true);
        $lastColumn = $sheet->getHighestColumn();
        $lastRow = count($lines);

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$lastRow}:{$lastColumn}{$lastRow}")->getFont()->setBold(true);
        $sheet->getStyle("B".($headerRow + 1).":{$lastColumn}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
        $this->autoSize($sheet);
    }

    private function totalsLine(string $name, array $t, array $report): array
    {
        $line = [$name];
        foreach (array_keys($report['fee_types']) as $code) {
            array_push($line, $t['by_type'][$code]['count'], $t['by_type'][$code]['amount']);
        }
        array_push($line, $t['by_bank']['muamalat']['amount'], $t['by_bank']['bsi']['amount'], $t['by_bank']['other']['amount'], $t['count'], $t['amount']);

        return $line;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function rows(Worksheet $sheet, string $title, array $rows, bool $withUnit): void
    {
        $sheet->setTitle($title);
        $headers = self::ROW_HEADERS;
        if ($withUnit) {
            array_splice($headers, 4, 0, ['Unit']);
        }
        $sheet->fromArray($headers, null, 'A1');

        $r = 2;
        foreach ($rows as $i => $row) {
            $values = [$i + 1, $row['paid_at'], $row['nis'], $row['nama']];
            if ($withUnit) {
                $values[] = $row['unit'];
            }
            array_push($values, $row['tagihan'], $row['bank_label'], $row['va_number'], $row['reference'], $row['payment_number'], $row['amount']);

            foreach (array_values($values) as $c => $value) {
                $cell = $sheet->getCell([$c + 1, $r]);
                is_string($value) ? $cell->setValueExplicit($value, DataType::TYPE_STRING) : $cell->setValue($value);
            }
            $r++;
        }

        $amountColumn = count($headers);
        $sheet->setCellValue([$amountColumn - 1, $r], 'Subtotal ('.count($rows).' trx)');
        $sheet->setCellValue([$amountColumn, $r], (float) array_sum(array_column($rows, 'amount')));

        $last = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
        $amountLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($amountColumn);
        $sheet->getStyle("{$amountLetter}2:{$amountLetter}{$r}")->getNumberFormat()->setFormatCode('#,##0');
        $this->autoSize($sheet);
    }

    private function autoSize(Worksheet $sheet): void
    {
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }
    }

    /** Excel caps a sheet name at 31 characters and forbids : \ / ? * [ ]. */
    private function uniqueTitle(string $name, array &$used): string
    {
        $base = mb_substr(trim(preg_replace('#[:\\\\/?*\[\]]#', ' ', $name)) ?: 'Unit', 0, 28);
        $title = $base;

        for ($i = 2; isset($used[$title]); $i++) {
            $title = "{$base} {$i}";
        }

        $used[$title] = true;

        return $title;
    }
}
