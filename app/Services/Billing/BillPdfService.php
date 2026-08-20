<?php

namespace App\Services\Billing;

use App\Models\Bill;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders a bill as PDF - an invoice while owed, a receipt once settled.
 *
 * One template serves both (see resources/views/pdf/bill.blade.php): the rows
 * and totals never differ between them, only the heading and whether a paid
 * stamp appears, so there is nothing to keep in sync between an "invoice" view
 * and a "receipt" view.
 */
class BillPdfService
{
    public function render(Bill $bill): \Barryvdh\DomPDF\PDF
    {
        $bill->loadMissing(['student.schoolUnit', 'lines', 'academicYear', 'allocations.payment']);

        $payments = $bill->allocations
            ->pluck('payment')
            ->filter(fn ($p) => $p && $p->status === 'completed')
            ->unique('id')
            ->values();

        $logoPath = public_path('images/logo-yapi.png');
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $logoBase64 = 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath));
        }

        return Pdf::loadView('pdf.bill', [
            'bill' => $bill,
            'isPaid' => $bill->status === 'paid',
            'payments' => $payments,
            'kelas' => $bill->student->currentEnrollment()?->classroom?->name,
            'schoolName' => config('app.name'),
            'logoBase64' => $logoBase64,
            'money' => fn (float $amount) => 'Rp '.number_format($amount, 0, ',', '.'),
        ])->setPaper('a4');
    }

    public function filename(Bill $bill): string
    {
        $prefix = $bill->status === 'paid' ? 'Kuitansi' : 'Tagihan';

        return $prefix.'-'.str_replace('/', '-', $bill->bill_number).'.pdf';
    }
}
