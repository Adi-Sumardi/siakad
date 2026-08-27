<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Billing\BillingApiClient;
use App\Services\Billing\BillingApiException;
use App\Services\Billing\PaymentAllocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodically polls the e-SPP Billing API for settled Bank Muamalat Virtual Accounts.
 */
class PollBillingVaPayments extends Command
{
    protected $signature = 'payments:poll-billing-va {--limit=50 : Maximum number of payments to check}';

    protected $description = 'Poll the e-SPP Billing API for settled Bank Muamalat VA payments';

    public function handle(BillingApiClient $client, PaymentAllocator $allocator): int
    {
        $payments = Payment::query()
            ->whereIn('status', ['processing', 'pending'])
            ->where(function ($q) {
                $q->where('gateway_response->provider', 'bank_muamalat')
                    ->orWhereNotNull('gateway_response->va_number');
            })
            ->latest('updated_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($payments->isEmpty()) {
            $this->info('No pending Bank Muamalat VA payments to poll.');

            return self::SUCCESS;
        }

        $this->info("Polling {$payments->count()} pending VA payment(s)...");
        $settledCount = 0;

        foreach ($payments as $payment) {
            $vaNumber = $payment->gateway_response['va_number'] ?? null;
            if (! $vaNumber) {
                continue;
            }

            try {
                $response = $client->getByVaNumber($vaNumber);
                $remaining = (float) ($response['sisa'] ?? $response['data']['sisa'] ?? -1);

                if ($remaining === 0.0) {
                    $allocator->settle($payment, $response['uuid'] ?? $vaNumber, array_merge($response, [
                        'settled_via' => 'billing_api_poller',
                        'polled_at' => now()->toIso8601String(),
                    ]));

                    $settledCount++;
                    $this->info("Payment {$payment->payment_number} (VA: {$vaNumber}) settled!");
                } elseif ($payment->expires_at && $payment->expires_at->isPast()) {
                    $allocator->fail($payment, 'expired', 'Virtual Account telah kedaluwarsa.');
                    $this->warn("Payment {$payment->payment_number} (VA: {$vaNumber}) marked as expired.");
                }
            } catch (BillingApiException $e) {
                Log::warning('[PollBillingVaPayments] Failed to check VA: '.$vaNumber.' - '.$e->getMessage());
            } catch (\Throwable $e) {
                Log::error('[PollBillingVaPayments] Unexpected error polling VA: '.$vaNumber.' - '.$e->getMessage());
            }
        }

        $this->info("Done. {$settledCount} payment(s) settled.");

        return self::SUCCESS;
    }
}
