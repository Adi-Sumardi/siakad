<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Billing\BillingApiClient;
use App\Services\Billing\PaymentAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles inbound payment callback webhooks from e-SPP / Bank Muamalat (BMI).
 * Path: POST /api/payment-webhook/{uuid}
 */
class BillingApiWebhookController extends Controller
{
    public function __construct(
        private BillingApiClient $client,
        private PaymentAllocator $allocator,
    ) {}

    public function handle(Request $request, string $uuid): JsonResponse
    {
        $payload = $request->all();
        $billingUuid = $payload['billing_uuid'] ?? null;
        $referenceNo = $payload['reference_no'] ?? null;
        $paidAmount = (float) ($payload['jumlah_pembayaran'] ?? $payload['jumlah_tagihan'] ?? 0);
        $transactionId = $payload['uuid'] ?? $uuid;

        Log::info('[BillingApiWebhook] Received callback from e-SPP', [
            'route_uuid' => $uuid,
            'billing_uuid' => $billingUuid,
            'reference_no' => $referenceNo,
            'amount' => $paidAmount,
        ]);

        // Find the matching Payment
        $payment = null;

        if ($billingUuid) {
            $payment = Payment::where('external_transaction_id', $billingUuid)
                ->orWhere('invoice_id', $billingUuid)
                ->orWhere('gateway_response->billing_uuid', $billingUuid)
                ->first();
        }

        if (! $payment && $referenceNo) {
            $payment = Payment::where('payment_number', $referenceNo)
                ->orWhere('external_transaction_id', $referenceNo)
                ->orWhere('gateway_response->va_number', $referenceNo)
                ->first();
        }

        if (! $payment) {
            $payment = Payment::where('external_transaction_id', $uuid)
                ->orWhere('invoice_id', $uuid)
                ->first();
        }

        if (! $payment) {
            Log::warning('[BillingApiWebhook] Payment not found for callback', [
                'route_uuid' => $uuid,
                'billing_uuid' => $billingUuid,
                'reference_no' => $referenceNo,
            ]);

            return response()->json(['success' => true, 'message' => 'Payment not found, skipped.'], 200);
        }

        if ($payment->isSettled()) {
            return response()->json(['success' => true, 'message' => 'Payment already settled.'], 200);
        }

        // Verify status directly from e-SPP before settling
        $vaNumber = $payment->gateway_response['va_number'] ?? $referenceNo;
        $verified = false;

        if ($vaNumber) {
            try {
                $statusRes = $this->client->getByVaNumber($vaNumber);
                $remaining = (float) ($statusRes['sisa'] ?? $statusRes['data']['sisa'] ?? 0);

                if ($remaining <= 0) {
                    $verified = true;
                } else {
                    Log::warning('[BillingApiWebhook] e-SPP VA still shows outstanding balance', [
                        'va_number' => $vaNumber,
                        'sisa' => $remaining,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[BillingApiWebhook] Failed to query e-SPP by VA, trusting signed callback: '.$e->getMessage());
                $verified = true;
            }
        } else {
            $verified = true;
        }

        if ($verified) {
            $this->allocator->settle($payment, $transactionId, array_merge($payload, [
                'settled_via' => 'billing_api_webhook',
                'settled_at' => now()->toIso8601String(),
            ]));

            Log::info('[BillingApiWebhook] Payment successfully settled', [
                'payment_number' => $payment->payment_number,
                'amount' => $payment->amount,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook processed successfully',
        ], 200);
    }
}
