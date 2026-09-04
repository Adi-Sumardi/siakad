<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Billing\BillingApiClient;
use App\Services\Billing\BillingApiException;
use App\Services\Billing\PaymentAllocator;
use App\Services\Payment\BillingApiGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodically polls the e-SPP Billing API for settled Virtual Account payments (Bank Muamalat & BSI).
 */
class PollBillingVaPayments extends Command
{
    protected $signature = 'payments:poll-billing-va {--limit=50 : Maximum number of payments to check}';

    protected $description = 'Poll the e-SPP Billing API for settled Virtual Account payments';

    public function handle(BillingApiClient $client, PaymentAllocator $allocator, BillingApiGateway $gateway): int
    {
        $payments = Payment::query()
            ->whereIn('status', ['processing', 'pending'])
            ->where(function ($q) {
                $q->whereIn('gateway_response->provider', ['bank_muamalat', 'bank_bsi'])
                    ->orWhereNotNull('gateway_response->va_number');
            })
            ->latest('updated_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($payments->isEmpty()) {
            $this->info('No pending VA payments to poll.');

            return self::SUCCESS;
        }

        $this->info("Polling {$payments->count()} pending VA payment(s)...");
        $settledCount = 0;

        foreach ($payments as $payment) {
            // gateway_response.va_number is already the chosen bank's VA
            // (BillingApiGateway generates only one bank's VA per payment) -
            // this used to prefer all_va.muamalat first regardless of which
            // bank the parent actually selected, so a BSI payment's poll
            // checked a Muamalat VA that was never registered as this bill.
            $vaLookup = $payment->gateway_response['va_number'] ?? null;

            if (! $vaLookup) {
                continue;
            }

            try {
                $response = $client->getByVaNumber($vaLookup);
                $remaining = (float) ($response['sisa'] ?? $response['data']['sisa'] ?? -1);

                if ($remaining === 0.0) {
                    $allocator->settle($payment, $response['uuid'] ?? $vaLookup, array_merge($response, [
                        'settled_via' => 'billing_api_poller',
                        'polled_at' => now()->toIso8601String(),
                    ]));

                    $settledCount++;
                    $this->info("Payment {$payment->payment_number} (VA: {$vaLookup}) settled!");
                } elseif ($payment->expires_at && $payment->expires_at->isPast()) {
                    $allocator->fail($payment, 'expired', 'Virtual Account telah kedaluwarsa.');
                    $this->warn("Payment {$payment->payment_number} (VA: {$vaLookup}) marked as expired.");
                }
            } catch (BillingApiException $e) {
                Log::warning('[PollBillingVaPayments] Failed to check VA: '.$vaLookup.' - '.$e->getMessage());
            } catch (\Throwable $e) {
                Log::error('[PollBillingVaPayments] Unexpected error polling VA: '.$vaLookup.' - '.$e->getMessage());
            }
        }

        // A superseded VA (a bank-switch's or a re-checkout's abandoned one)
        // stays payable at e-SPP until its own original due date, since
        // expireVa() cannot actually close it there (see its docblock) - the
        // query above only ever looks at pending/processing rows, so without
        // this a late payment on an abandoned VA would go completely
        // unnoticed. Bounded to the window the VA itself was still valid
        // for; past that e-SPP's own date_end should have closed it
        // regardless of our side.
        $superseded = Payment::query()
            ->whereIn('status', ['failed', 'cancelled'])
            ->where(function ($q) {
                $q->whereIn('gateway_response->provider', ['bank_muamalat', 'bank_bsi'])
                    ->orWhereNotNull('gateway_response->va_number');
            })
            ->where('expires_at', '>', now()->subDays(7))
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($superseded as $payment) {
            $gateway->checkForSurpriseLatePayment($payment);
        }

        $this->info("Done. {$settledCount} payment(s) settled, {$superseded->count()} superseded VA(s) checked for a surprise late payment.");

        return self::SUCCESS;
    }
}
