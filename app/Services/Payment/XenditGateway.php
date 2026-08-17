<?php

namespace App\Services\Payment;

use App\Models\Guardian;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Hosted invoices from Xendit, the same provider PMB bills through.
 *
 * With no key configured the payment stays pending and carries no invoice URL,
 * so a developer machine still exercises checkout end to end without reaching
 * the network - and, importantly, without a half-configured environment
 * silently producing invoices nobody can pay.
 */
class XenditGateway implements PaymentGateway
{
    public function createInvoice(Payment $payment, Collection $bills, Guardian $payer): Payment
    {
        $secretKey = config('services.xendit.secret_key');

        if (! $secretKey) {
            Log::info('[Xendit] No secret key configured; leaving payment pending without an invoice.', [
                'payment' => $payment->payment_number,
            ]);

            return $payment;
        }

        $response = Http::withBasicAuth($secretKey, '')
            ->timeout(30)
            ->post(rtrim((string) config('services.xendit.base_url'), '/').'/v2/invoices', [
                // Our own number, so a callback can be matched back even if the
                // gateway's id never reaches us.
                'external_id' => $payment->payment_number,
                'amount' => (float) $payment->amount,
                'currency' => 'IDR',
                'payer_email' => $payer->email,
                'description' => $this->describe($bills),
                'invoice_duration' => (int) config('services.xendit.invoice_duration', 86400),
                'success_redirect_url' => rtrim((string) config('app.frontend_url'), '/').'/pembayaran',
                'failure_redirect_url' => rtrim((string) config('app.frontend_url'), '/').'/tagihan',
                'items' => $bills->map(fn ($bill) => [
                    'name' => $bill->description,
                    'quantity' => 1,
                    'price' => (float) $bill->remaining_amount,
                ])->values()->all(),
            ]);

        if ($response->failed()) {
            Log::error('[Xendit] Invoice creation failed', [
                'payment' => $payment->payment_number,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Gagal membuat tagihan pembayaran. Coba lagi beberapa saat.');
        }

        $body = $response->json();

        $payment->forceFill([
            'status' => 'processing',
            'invoice_id' => $body['id'] ?? null,
            'invoice_url' => $body['invoice_url'] ?? null,
            'expires_at' => isset($body['expiry_date']) ? \Carbon\Carbon::parse($body['expiry_date']) : null,
            'gateway_response' => $body,
        ])->save();

        return $payment;
    }

    private function describe(Collection $bills): string
    {
        if ($bills->count() === 1) {
            return (string) $bills->first()->description;
        }

        return $bills->count().' tagihan sekolah - '.$bills->pluck('description')->take(2)->join(', ').'…';
    }
}
