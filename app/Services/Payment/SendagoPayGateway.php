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

        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $fallbackCheckoutUrl = "{$frontendUrl}/pembayaran?payment={$payment->ulid}";

        // Calculate unique 3-digit code for bank transfer reconciliation
        $uniqueCode = ($payment->id % 900) + 100;
        $totalWithUnique = $payment->amount + ($payment->method === 'bank_transfer' ? $uniqueCode : 0);

        try {
            $response = Http::withHeaders([
                'X-API-Key' => $secretKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout(10)
                ->post("{$baseUrl}/v1/payments", $payload);

            if ($response->successful()) {
                $body = $response->json();
                $checkoutUrl = $body['checkout_url'] ?? $body['invoice_url'] ?? $fallbackCheckoutUrl;
                $txId = $body['id'] ?? $body['transaction_id'] ?? 'sg_'.uniqid();

                $payment->forceFill([
                    'status' => 'processing',
                    'external_transaction_id' => $txId,
                    'invoice_id' => $txId,
                    'invoice_url' => $checkoutUrl,
                    'expires_at' => isset($body['expired_at']) ? Carbon::parse($body['expired_at']) : now()->addMinutes(120),
                    'gateway_response' => array_merge($body, [
                        'unique_code' => $uniqueCode,
                        'total_with_code' => $totalWithUnique,
                    ]),
                ])->save();

                return $payment;
            }

            Log::warning('[SendagoPay] API returned unsuccessful status, activating internal checkout handler', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SendagoPay] API unreachable ('.$e->getMessage().'), activating internal checkout handler');
        }

        // Internal Fallback Invoice / Checkout
        $txId = 'tx_sg_'.strtolower($payment->ulid);

        $payment->forceFill([
            'status' => 'processing',
            'external_transaction_id' => $txId,
            'invoice_id' => $txId,
            'invoice_url' => $fallbackCheckoutUrl,
            'expires_at' => now()->addMinutes(120),
            'gateway_response' => [
                'provider' => 'sendagopay',
                'order_id' => $payment->payment_number,
                'amount' => (float) $payment->amount,
                'unique_code' => $uniqueCode,
                'total_with_code' => $totalWithUnique,
                'customer_name' => $customerName,
                'student_names' => $studentNames,
                'bills' => $itemsSummary,
                'bank_accounts' => [
                    [
                        'bank' => 'Bank Syariah Indonesia (BSI)',
                        'account_number' => '7001234567',
                        'account_name' => 'YAYASAN ASRAMA PELAJAR ISLAM',
                    ],
                    [
                        'bank' => 'Bank Mandiri',
                        'account_number' => '1230009876543',
                        'account_name' => 'YAYASAN ASRAMA PELAJAR ISLAM',
                    ],
                    [
                        'bank' => 'BCA',
                        'account_number' => '0089123456',
                        'account_name' => 'YAYASAN ASRAMA PELAJAR ISLAM',
                    ],
                ],
            ],
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
