<?php

namespace App\Services\Billing;

use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The per-payment receipt PDF (feature batch Poin 11B): what a FAMILY
 * actually paid in one transaction - the amount, the bills it settled,
 * the reference number - as opposed to BillPdfService's per-bill view,
 * which prints a basket's remaining balance. One payment can settle
 * several bills at a custom amount; this renders exactly that.
 */
class PaymentReceiptPdfService
{
    public function render(Payment $payment): \Barryvdh\DomPDF\PDF
    {
        $payment->loadMissing(['bills.feeType', 'bills.student.schoolUnit', 'payer']);

        $students = $payment->bills
            ->map(fn ($bill) => $bill->student)
            ->unique('id')
            ->values();

        $logoBase64 = $this->logoDataUri(public_path('images/logo-yapi.png'));
        $ypiaLogoBase64 = $this->logoDataUri(public_path('images/Logo-YPIA.png'));

        return Pdf::loadView('pdf.receipt', [
            'payment' => $payment,
            'students' => $students,
            'bankName' => (string) ($payment->gateway_response['bank_name'] ?? 'Virtual Account'),
            'reference' => $payment->referenceNumber(),
            'logoBase64' => $logoBase64,
            'logoYpiaBase64' => $ypiaLogoBase64,
            'money' => fn (float $amount) => 'Rp '.number_format($amount, 0, ',', '.'),
        ])->setPaper('a5', 'portrait');
    }

    /** Same downscaled, alpha-preserving kop treatment as BillPdfService. */
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
            imagedestroy($image);

            return 'data:image/png;base64,'.base64_encode(file_get_contents($path));
        }

        $resized = imagecreatetruecolor((int) round($width * $scale), (int) round($height * $scale));
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
