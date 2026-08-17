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
 * Xendit invoice callbacks.
 *
 * Recorded before acting, keyed on the gateway's own event id, because Xendit
 * retries on any non-2xx and will happily deliver the same settlement twice.
 * Between that and the unique external_transaction_id, a duplicate cannot
 * double-count money.
 */
class XenditController extends Controller
{
    public function handle(Request $request, PaymentAllocator $allocator): JsonResponse
    {
        $token = config('services.xendit.webhook_token');

        // Same posture as the PMB handoff: an unverifiable callback is refused,
        // never accepted "for now". This endpoint settles money.
        if (! $token || ! hash_equals($token, (string) $request->header('x-callback-token'))) {
            Log::warning('[Xendit] Rejected callback with bad or missing token', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid callback token.'], 401);
        }

        $payload = $request->all();
        $externalId = $payload['external_id'] ?? null;
        $status = mb_strtoupper((string) ($payload['status'] ?? ''));

        $event = IntegrationEvent::firstOrCreate(
            // Xendit does not always send a distinct event id, so the invoice id
            // plus its status is what identifies "this transition" - the same
            // invoice going PAID twice is one event, not two.
            ['event_id' => 'xendit:'.($payload['id'] ?? $externalId).':'.$status],
            [
                'source' => 'xendit',
                'event_type' => 'invoice.'.mb_strtolower($status),
                'payload' => $payload,
                'status' => 'received',
            ]
        );

        if (! $event->wasRecentlyCreated) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        $payment = Payment::where('payment_number', $externalId)
            ->orWhere('invoice_id', $payload['id'] ?? null)
            ->first();

        if (! $payment) {
            $event->markFailed("Pembayaran dengan external_id {$externalId} tidak ditemukan.");
            Log::warning('[Xendit] Callback for unknown payment', ['external_id' => $externalId]);

            // 200 on purpose: retrying will not conjure the payment, and a 4xx
            // just makes Xendit redeliver forever.
            return response()->json(['status' => 'ignored'], 200);
        }

        match ($status) {
            'PAID', 'SETTLED' => $allocator->settle($payment, $payload['payment_id'] ?? $payload['id'] ?? null, $payload),
            'EXPIRED' => $allocator->fail($payment, 'expired', 'Invoice kedaluwarsa'),
            'FAILED' => $allocator->fail($payment, 'failed', $payload['failure_reason'] ?? null),
            default => Log::info('[Xendit] Unhandled invoice status', ['status' => $status]),
        };

        $event->markProcessed();

        return response()->json(['status' => 'ok'], 200);
    }
}
