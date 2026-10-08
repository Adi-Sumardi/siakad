<?php

namespace App\Services\Billing;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Notes, for the recon, which bank a paid VA reached, the VA number that
 * was paid and the bank's own reference (2026-10-07, same as PMB's
 * BillingApiPaymentSync::recordPaidBank()).
 *
 * A Siakad VA is single-bank - the bank the family picked is in
 * metadata.bank_channel. The reference comes from e-SPP's transaction for
 * this billing: a VA number carries a new billing every month, so only the
 * transaction with this payment's own billing uuid counts. Best effort -
 * payments:record-paid-bank retries a miss.
 */
class PaidBankRecorder
{
    public const LABELS = ['muamalat' => 'Bank Muamalat', 'bsi' => 'BSI'];

    public function __construct(private BillingApiClient $client) {}

    /**
     * @param  array<string, mixed>|null  $opened  gateway_response as it was when
     *                                             the VA was opened - settlement replaces it
     */
    public function record(Payment $payment, ?array $opened = null): void
    {
        if ($payment->status !== 'completed' || $payment->method !== 'virtual_account'
            || ($payment->paid_bank && $payment->paid_reference)) {
            return;
        }

        $opened ??= $payment->gateway_response ?? [];
        $va = $payment->paid_va
            ?? ($opened['va_number'] ?? null)
            ?? data_get($payment->gateway_response, 'bmi_billing.va_number')
            ?? data_get($payment->gateway_response, 'bsm_billing.nomor_pembayaran');
        $uuid = $opened['billing_uuid'] ?? null;

        if (! $uuid && is_string($payment->external_transaction_id) && preg_match('/^[0-9a-f-]{36}$/i', $payment->external_transaction_id)) {
            $uuid = $payment->external_transaction_id;
        }

        $channel = $payment->metadata['bank_channel'] ?? $opened['bank_key'] ?? null;
        $bank = $payment->paid_bank
            ?? (in_array($channel, ['muamalat', 'bsi'], true) ? $channel : self::bankOfVa((string) $va));

        $reference = $payment->paid_reference
            ?? ($va && $uuid && ! str_starts_with($uuid, 'sim_') ? $this->referenceFor((string) $va, $uuid, $payment) : null);

        $payment->forceFill([
            'paid_bank' => $bank,
            'paid_va' => $va ? (string) $va : null,
            'paid_reference' => $reference,
        ])->saveQuietly();
    }

    private function referenceFor(string $va, string $uuid, Payment $payment): ?string
    {
        try {
            $transactions = $this->client->getTransactionsByVa($va)['data'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('[PaidBankRecorder] Could not read the bank reference from e-SPP', [
                'payment' => $payment->payment_number, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        foreach ($transactions as $transaction) {
            if (($transaction['billing_uuid'] ?? null) === $uuid && ! empty($transaction['reference_no'])) {
                return (string) $transaction['reference_no'];
            }
        }

        return null;
    }

    public static function bankOfVa(string $va): ?string
    {
        return match (true) {
            str_starts_with($va, '8020') => 'muamalat',
            str_starts_with($va, '3656'), str_starts_with($va, '7895') => 'bsi',
            default => null,
        };
    }
}
