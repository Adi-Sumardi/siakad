<?php

namespace App\Services\Reporting;

use Barryvdh\DomPDF\Facade\Pdf;

/** Recon PDF: page 1 the per-unit summary, then one page per unit. */
class CollectionReconPdf
{
    /** @param  array<string, mixed>  $report  CollectionReconService::build() */
    public function render(array $report): string
    {
        return Pdf::loadView('pdf.collections-recon', $report)
            ->setPaper('a4', 'landscape')
            ->output();
    }
}
