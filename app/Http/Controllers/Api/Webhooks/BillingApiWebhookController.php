<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\IntegrationEvent;
use App\Models\Payment;
use App\Services\Billing\BillingApiClient;
use App\Services\Billing\PaymentAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles inbound payment callback webhooks from e-SPP (Bank Muamalat & BSI Virtual Account).
 * Path: POST /api/payment-webhook/{uuid}
 *
 * This endpoint settles money and has no signature to check (e-SPP sends
 * none), so the only guard is a live lookup back into e-SPP's own record of
 * the VA - and that guard is strictly fail-closed: a response without a
 * recognizable "sisa" field means "cannot verify", never "paid". Every
 * callback lands in integration_events (the same idempotent inbox the other
 * integrations use), so a payment that could not be settled is visible on the
 * monitoring screen instead of only in laravel.log.
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

        // Idempotent inbox, keyed on e-SPP's own event id: a redelivered
        // callback is one event, not two, no matter how often it arrives.
        $event = IntegrationEvent::firstOrCreate(
            ['event_id' => 'billing_api:'.(string) $transactionId],
            [
                'source' => 'billing_api',
                'event_type' => 'payment.callback',
                'payload' => $payload,
                'status' => 'received',
            ]
        );

        if (! $event->wasRecentlyCreated) {
            return response()->json(['success' => true, 'message' => 'Duplicate callback, already handled.'], 200);
        }

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
            $event->markFailed("Callback e-SPP tidak menemukan pembayaran (billing_uuid {$billingUuid}, reference {$referenceNo}).");

            return response()->json(['success' => true, 'message' => 'Payment not found, skipped.'], 200);
        }

        if ($payment->isSettled()) {
            $event->markProcessed();

            return response()->json(['success' => true, 'message' => 'Payment already settled.'], 200);
        }

        // Money arrived against a payment that was superseded or failed - the
        // bank has it but this system deliberately refuses to settle it. That
        // is exactly the case nobody may learn about from a log file only.
        if (! in_array($payment->status, ['pending', 'processing'], true)) {
            Log::error('[BillingApiWebhook] VA paid for a payment this system already closed', [
                'payment' => $payment->payment_number,
                'status' => $payment->status,
                'rejection_reason' => $payment->rejection_reason,
            ]);
            $event->markFailed(
                "VA untuk pembayaran {$payment->payment_number} (status: {$payment->status}) terbayar di e-SPP - uang masuk untuk pembayaran yang sudah ditutup, perlu rekonsiliasi manual."
            );

            return response()->json(['success' => true, 'message' => 'Payment closed; flagged for reconciliation.'], 200);
        }

        // Live lookup against e-SPP's own record of the VA. gateway_response.va_number
        // is already the chosen bank's VA (BillingApiGateway generates only one
        // bank's VA per payment) - this used to prefer all_va.muamalat first
        // regardless of which bank the parent actually selected, so a BSI
        // payment's callback verified against a Muamalat VA that was never
        // registered as this bill.
        $vaLookup = $payment->gateway_response['va_number'] ?? $referenceNo;

        if (! $vaLookup) {
            $event->markFailed("Callback untuk {$payment->payment_number} tanpa nomor VA untuk diverifikasi.");
            Log::warning('[BillingApiWebhook] Callback carried no va_number to verify against', [
                'payment' => $payment->payment_number,
            ]);

            return response()->json(['success' => true, 'message' => 'No VA to verify against.'], 200);
        }

        try {
            $statusRes = $this->client->getByVaNumber($vaLookup);
        } catch (\Throwable $e) {
            Log::warning('[BillingApiWebhook] Could not verify against e-SPP, leaving unsettled for the poller to pick up: '.$e->getMessage(), [
                'va_number' => $vaLookup,
                'payment' => $payment->payment_number,
            ]);
            $event->markFailed('Verifikasi ke e-SPP gagal (jaringan/gateway): '.$e->getMessage().'. Poller VA akan mencoba lagi.');

            return response()->json(['success' => true, 'message' => 'Verification unavailable; poller will retry.'], 200);
        }

        // Fail-closed "sisa": a missing or unrecognizable field is "cannot
        // verify", never "paid". The old `?? 0` default settled the bill in
        // full on any malformed response.
        $rawRemaining = $statusRes['sisa'] ?? $statusRes['data']['sisa'] ?? null;

        if ($rawRemaining === null || ! is_numeric($rawRemaining)) {
            $event->markFailed("Respons e-SPP untuk VA {$vaLookup} tidak memuat field 'sisa' yang bisa dibaca - pelunasan tidak bisa diverifikasi.");
            Log::warning('[BillingApiWebhook] e-SPP response had no readable sisa field, refusing to settle', [
                'va_number' => $vaLookup,
                'payment' => $payment->payment_number,
            ]);

            return response()->json(['success' => true, 'message' => 'Could not verify settlement.'], 200);
        }

        $remaining = (float) $rawRemaining;

        if ($remaining > 0) {
            $event->markFailed("VA {$vaLookup} masih memiliki sisa Rp ".number_format($remaining, 0, ',', '.').' - belum lunas di e-SPP.');
            Log::warning('[BillingApiWebhook] e-SPP VA still shows outstanding balance', [
                'va_number' => $vaLookup,
                'sisa' => $remaining,
            ]);

            return response()->json(['success' => true, 'message' => 'VA still outstanding.'], 200);
        }

        // When e-SPP states the registered amount, it must be the amount this
        // system expects to receive for this payment.
        $rawAmount = $statusRes['jumlah_tagihan'] ?? $statusRes['data']['jumlah_tagihan'] ?? null;

        if ($rawAmount !== null && is_numeric($rawAmount) && abs((float) $rawAmount - (float) $payment->amount) > 0.01) {
            $event->markFailed(
                "Nominal VA {$vaLookup} di e-SPP (Rp ".number_format((float) $rawAmount, 0, ',', '.')
                .") berbeda dengan pembayaran {$payment->payment_number} (Rp ".number_format((float) $payment->amount, 0, ',', '.').').'
            );
            Log::warning('[BillingApiWebhook] e-SPP amount does not match payment', [
                'va_number' => $vaLookup,
                'espp_amount' => (float) $rawAmount,
                'payment_amount' => (float) $payment->amount,
            ]);

            return response()->json(['success' => true, 'message' => 'Amount mismatch.'], 200);
        }

        $this->allocator->settle($payment, $transactionId, array_merge($payload, [
            'settled_via' => 'billing_api_webhook',
            'settled_at' => now()->toIso8601String(),
        ]));
        $event->markProcessed();

        Log::info('[BillingApiWebhook] Payment successfully settled', [
            'payment_number' => $payment->payment_number,
            'amount' => $payment->amount,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Webhook processed successfully',
        ], 200);
    }
}
