<?php

namespace App\Services\Payment;

use App\Models\Guardian;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * SendagoPay Payment Gateway Integration.
 *
 * Calls POST /v1/payments on SendagoPay backend to create a dynamic QRIS / checkout invoice.
 * Attaches checkout_url to the payment model so the parent is redirected to the payment page.
 */
class SendagoPayGateway implements PaymentGateway
{
    public function createInvoice(Payment $payment, Collection $bills, Guardian $payer): Payment
    {
        $secretKey = config('services.sendagopay.secret_key') ?: config('services.sendagopay.public_key');
        $baseUrl = rtrim((string) config('services.sendagopay.base_url', 'https://api-sendagopay.adilabs.id'), '/');

        if (! $secretKey) {
            Log::info('[SendagoPay] No API key configured; leaving payment pending without an invoice.', [
                'payment' => $payment->payment_number,
            ]);

            return $payment;
        }

        // Collect student names and details from the bills
        $students = $bills->map(fn ($bill) => $bill->student)->filter()->unique('id');
        $studentNames = $students->pluck('nama_lengkap')->join(', ');

        $customerName = $studentNames
            ? "{$studentNames} (Wali: {$payer->nama})"
            : $payer->nama;

        $itemsSummary = $bills->map(fn ($b) => [
            'bill_number' => $b->bill_number,
            'description' => $b->description,
            'amount' => (float) $b->remaining_amount,
            'student' => $b->student?->nama_lengkap,
            'unit' => $b->student?->schoolUnit?->label,
        ])->values()->all();

        $payload = [
            'order_id' => $payment->payment_number,
            'amount' => (float) $payment->amount,
            'customer_name' => $customerName,
            'customer_email' => $payer->email ?: $payer->user?->email,
            'customer_phone' => $payer->no_hp ?: $payer->user?->phone,
            'notes' => $this->describe($bills, $studentNames),
            'metadata' => [
                'payment_number' => $payment->payment_number,
                'payment_ulid' => $payment->ulid,
                'payer_name' => $payer->nama,
                'student_names' => $studentNames,
                'bills' => $itemsSummary,
            ],
            'redirect_url' => rtrim((string) config('app.frontend_url'), '/').'/pembayaran',
            'expiry_minutes' => 120, // 2 hours
        ];

        $response = Http::withHeaders([
            'X-API-Key' => $secretKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->post("{$baseUrl}/v1/payments", $payload);

        if ($response->failed()) {
            Log::error('[SendagoPay] Invoice creation failed', [
                'payment' => $payment->payment_number,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Gagal membuat tagihan pembayaran SendagoPay. Coba lagi beberapa saat.');
        }

        $body = $response->json();
        $checkoutUrl = $body['checkout_url'] ?? null;
        $txId = $body['id'] ?? null;

        $payment->forceFill([
            'status' => 'processing',
            'external_transaction_id' => $txId,
            'invoice_id' => $txId,
            'invoice_url' => $checkoutUrl,
            'expires_at' => isset($body['expired_at']) ? Carbon::parse($body['expired_at']) : now()->addMinutes(120),
            'gateway_response' => $body,
        ])->save();

        return $payment;
    }

    private function describe(Collection $bills, string $studentNames): string
    {
        $desc = $bills->count() === 1
            ? (string) $bills->first()->description
            : $bills->count().' tagihan sekolah ('.$bills->pluck('description')->take(2)->join(', ').'…)';

        return $studentNames ? "{$desc} - Siswa: {$studentNames}" : $desc;
    }
}
