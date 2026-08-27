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

        // e-SPP's "Callback Keluar" carries no signature or shared secret to
        // check - there is nothing here playing the role Xendit's callback
        // token or SendagoPay's HMAC secret play elsewhere in this codebase.
        // This live lookup against e-SPP's own record of the VA is the only
        // thing standing between "a POST arrived claiming this paid" and
        // actually settling money, which is why it must fail closed: on any
        // failure to confirm - can't reach e-SPP, no va_number to check, a
        // remaining balance still shown - the payment is left exactly as it
        // was. payments:poll-billing-va runs on a schedule and does this same
        // live check independently, so a payment that's genuinely settled but
        // couldn't be verified here isn't lost, only delayed until the next
        // poll - a real family paying is not blocked by a transient e-SPP
        // outage; a forged callback with nothing to confirm it is.
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
                Log::warning('[BillingApiWebhook] Could not verify against e-SPP, leaving unsettled for the poller to pick up: '.$e->getMessage(), [
                    'va_number' => $vaNumber,
                    'payment' => $payment->payment_number,
                ]);
            }
        } else {
            Log::warning('[BillingApiWebhook] Callback carried no va_number to verify against', [
                'payment' => $payment->payment_number,
            ]);
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
