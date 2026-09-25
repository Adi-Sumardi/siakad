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
        $bill->loadMissing(['student.schoolUnit', 'lines', 'academicYear', 'feeType', 'allocations.payment']);

        $payments = $bill->allocations
            ->pluck('payment')
            ->filter(fn ($p) => $p && $p->status === 'completed')
            ->unique('id')
            ->values();

        $logoBase64 = $this->logoDataUri(public_path('images/logo-yapi.png'));
        // The kop's second logo (YPIA), printed beside logo-yapi in the header.
        $ypiaLogoBase64 = $this->logoDataUri(public_path('images/Logo-YPIA.png'));

        return Pdf::loadView('pdf.bill', [
            'bill' => $bill,
            'isPaid' => $bill->status === 'paid',
            'payments' => $payments,
            'kelas' => $bill->student->currentEnrollment()?->classroom?->name,
            'schoolName' => config('app.name'),
            'logoBase64' => $logoBase64,
            'logoYpiaBase64' => $ypiaLogoBase64,
            // Only a VA the bank actually knows about (audit T40-c): the
            // number printed is the live checkout's own registration, never
            // a deterministically-computed one for a bill that was never
            // checked out - that number was never registered at e-SPP, and
            // printing it sent parents to a counter that could reject it
            // (or take money for a different basket's registration).
            'vaNumber' => $this->vaFor($bill)['number'] ?? null,
            'vaBankName' => $this->vaFor($bill)['bank_name'] ?? null,
            // The amount e-SPP actually registered for that VA (audit
            // T62-a): a custom-partial checkout leaves a live VA whose
            // registered amount is BELOW the bill's remaining balance, and
            // printing the number next to "Sisa Kewajiban" without this
            // sent parents to transfer the paper's bigger figure into a
            // smaller registration - rejected at the bank.
            'vaAmount' => $this->vaFor($bill)['amount'] ?? null,
            'money' => fn (float $amount) => 'Rp '.number_format($amount, 0, ',', '.'),
        ])->setPaper('a4');
    }

    public function filename(Bill $bill): string
    {
        $prefix = $bill->status === 'paid' ? 'Kuitansi' : 'Tagihan';

        return $prefix.'-'.str_replace('/', '-', $bill->bill_number).'.pdf';
    }

    /**
     * The VA block to print on an unpaid bill, or null when there is none to
     * print (paid bills, bills with no live checkout, or a checkout whose
     * registration carried no VA - the blade skips the whole block either
     * way). Comes from the live payment's own gateway_response so the paper
     * can never advertise a number the bank doesn't know (audit T40-c).
     *
     * @return array{number: string, bank_name: string, amount: float|null}|null
     */
    public function vaFor(Bill $bill): ?array
    {
        if ($bill->status === 'paid') {
            return null;
        }

        $payment = $bill->allocations
            ->pluck('payment')
            ->filter(fn ($p) => $p && in_array($p->status, ['pending', 'processing'], true))
            ->sortByDesc('id')
            ->first();

        $va = $payment->gateway_response['va_number'] ?? null;

        if (! $va) {
            return null;
        }

        return [
            'number' => $va,
            'bank_name' => (string) ($payment->gateway_response['bank_name'] ?? 'Bank Muamalat'),
            'amount' => isset($payment->gateway_response['amount'])
                ? (float) $payment->gateway_response['amount']
                : null,
        ];
    }

    /**
     * Which bank's VA this bill's paper should show: the channel the family
     * picked at their latest checkout (payment metadata bank_channel), so a
     * parent who chose BSI is not sent to a Muamalat counter with a
     * Muamalat-only printout. Defaults to Muamalat only when no checkout ever
     * recorded a choice. Callers must have loaded $bill->allocations.payment.
     */
    public function bankChannelFor(Bill $bill): string
    {
        $bank = $bill->allocations
            ->pluck('payment')
            ->filter(fn ($p) => $p && in_array($p->status, ['pending', 'processing', 'completed'], true))
            ->sortByDesc('id')
            ->map(fn ($p) => $p->metadata['bank_channel'] ?? null)
            ->first(fn ($b) => $b !== null);

        return in_array($bank, ['muamalat', 'bsi'], true) ? $bank : 'muamalat';
    }

    /**
     * A kop logo as a data URI, downscaled first when the source is huge.
     *
     * Logo-YPIA.png arrives at 2481x2481: dompdf's Cpdf buffers the decoded
     * bitmap and that one image alone eats ~100MB - a hard OOM against the
     * default 128MB limit. The print slot is 64px CSS (~17mm), so a 512px
     * cap still renders sharper than the printer can show. Falls back to the
     * raw bytes if GD cannot decode the file.
     */
    private function logoDataUri(string $path): string
    {
        if (! file_exists($path)) {
            return '';
        }

        $image = @imagecreatefrompng($path);

        if ($image === false) {
            return 'data:image/png;base64,'.base64_encode(file_get_contents($path));
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, 512 / max($width, $height));

        if ($scale >= 1) {
            // Already small enough - pass the file's own bytes through. A GD
            // round-trip here would DROP the alpha channel (imagesavealpha is
            // off by default on a loaded image) and every transparent pixel
            // comes back opaque black: the "black box behind each logo" on
            // the printed kop.
            imagedestroy($image);

            return 'data:image/png;base64,'.base64_encode(file_get_contents($path));
        }

        $resized = imagecreatetruecolor((int) round($width * $scale), (int) round($height * $scale));
        // Blending OFF so both the transparent fill and the resampled pixels
        // REPLACE the canvas instead of compositing onto its default opaque
        // black - the other way a resize used to bake a black background in.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
        imagedestroy($image);

        ob_start();
        imagepng($resized, null, 6);
        imagedestroy($resized);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
