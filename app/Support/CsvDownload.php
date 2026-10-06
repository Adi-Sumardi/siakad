<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The report exports' one CSV shape (audit 6 Okt 2026 #5): a UTF-8 BOM and
 * ';' as separator, so a double-click opens the file in Excel with an
 * Indonesian locale (decimal comma, list separator ';') with names and
 * columns intact - a plain ',' file lands as one squashed column there.
 *
 * The Dapodik export deliberately stays on its own ',' format: its consumer
 * is Dapodik's importer, not a person in Excel.
 */
class CsvDownload
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, scalar|null>>  $rows
     */
    public static function make(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->stream(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers, ';', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($handle, $row, ';', '"', '\\');
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
