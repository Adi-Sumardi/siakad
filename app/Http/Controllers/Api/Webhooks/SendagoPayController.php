<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\IntegrationEvent;
use App\Models\Payment;
use App\Services\Billing\PaymentAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SendagoPay webhook handler for payment notifications.
 *
 * Endpoint: POST /api/webhooks/sendagopay
 * Header: X-Sendago-Signature (HMAC-SHA256 with webhook_secret)
 * Header: X-Sendago-Event (e.g. payment.success, payment.expired, payment.failed)
 */
class SendagoPayController extends Controller
{
    public function handle(Request $request, PaymentAllocator $allocator): JsonResponse
    {
        $secret = config('services.sendagopay.webhook_secret');
        $signature = $request->header('X-Sendago-Signature') ?? $request->header('x-sendago-signature');

        // Same posture as the Xendit callback: an unverifiable request is
        // refused, never accepted "for now" because the secret happens to be
        // unset. This endpoint settles money on nothing but payment_number,
        // which isn't a secret - it's shown to the family and printed as the
        // bank-transfer reference. Skipping verification whenever the secret
        // is merely absent (the previous behaviour) would let anyone forge a
        // payment.success for a payment_number they've simply seen.
        $computed = $secret ? hash_hmac('sha256', $request->getContent(), $secret) : null;
        if (! $secret || ! $signature || ! hash_equals($computed, (string) $signature)) {
            Log::warning('[SendagoPay] Rejected webhook with invalid or missing signature', [
                'ip' => $request->ip(),
                'secret_configured' => (bool) $secret,
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->all();
        $txId = (string) ($payload['transaction_id'] ?? $payload['id'] ?? '');
        $orderId = (string) ($payload['order_id'] ?? '');
        $event = (string) ($payload['event'] ?? $request->header('X-Sendago-Event', 'payment.success'));
        $status = mb_strtoupper((string) ($payload['status'] ?? ''));

        // Idempotent event inbox tracking
        $integrationEvent = IntegrationEvent::firstOrCreate(
            ['event_id' => 'sendagopay:'.($txId ?: $orderId).':'.$event.':'.$status],
            [
                'source' => 'sendagopay',
                'event_type' => $event,
                'payload' => $payload,
                'status' => 'received',
            ]
        );

        if (! $integrationEvent->wasRecentlyCreated) {
            return response()->json(['status' => 'duplicate', 'message' => 'Event already processed.'], 200);
        }

        // Find the payment record (Postgres type-safe: only check id if numeric)
        $payment = Payment::query()
            ->where(function ($q) use ($orderId, $txId) {
                if ($orderId !== '') {
                    $q->where('payment_number', $orderId)
                        ->orWhere('ulid', $orderId);

                    if (ctype_digit($orderId)) {
                        $q->orWhere('id', (int) $orderId);
                    }
                }

                if ($txId !== '') {
                    $q->orWhere('external_transaction_id', $txId)
                        ->orWhere('invoice_id', $txId);
                }
            })
            ->first();

        if (! $payment) {
            $integrationEvent->markFailed("Payment not found for order_id: {$orderId}, transaction_id: {$txId}");
            Log::warning('[SendagoPay] Webhook received for unknown payment', [
                'order_id' => $orderId,
                'transaction_id' => $txId,
                'event' => $event,
            ]);

            return response()->json(['status' => 'ignored', 'message' => 'Payment record not found.'], 200);
        }

        // Update channel/method if provided
        if (! empty($payload['channel'])) {
            $channel = (string) $payload['channel'];
            $channelLower = mb_strtolower($channel);
            $method = match (true) {
                str_contains($channelLower, 'qris') => 'qris',
                str_contains($channelLower, 'va') || str_contains($channelLower, 'virtual') => 'virtual_account',
                str_contains($channelLower, 'wallet') || in_array($channelLower, ['gopay', 'ovo', 'dana', 'shopeepay'], true) => 'e_wallet',
                str_contains($channelLower, 'transfer') => 'bank_transfer',
                str_contains($channelLower, 'card') || str_contains($channelLower, 'cc') => 'credit_card',
                default => 'other',
            };

            $payment->update([
                'channel' => $channel,
                'method' => $method,
            ]);
        }

        // Process based on event and status
        if ($event === 'payment.success' || in_array($status, ['PAID', 'SETTLED', 'SUCCESS'], true)) {
            $allocator->settle($payment, $txId ?: $orderId, $payload);
        } elseif ($event === 'payment.expired' || $status === 'EXPIRED') {
            $allocator->fail($payment, 'expired', 'Pembayaran kedaluwarsa');
        } elseif ($event === 'payment.failed' || $status === 'FAILED') {
            $allocator->fail($payment, 'failed', $payload['message'] ?? 'Pembayaran gagal');
        } else {
            Log::info('[SendagoPay] Unhandled webhook event', ['event' => $event, 'status' => $status]);
        }

        $integrationEvent->markProcessed();

        return response()->json(['status' => 'ok', 'message' => 'Webhook processed successfully.'], 200);
    }
}
