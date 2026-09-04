<?php

namespace App\Services\Payment;

use App\Models\Bill;
use App\Models\Guardian;
use App\Models\Payment;
use App\Services\Billing\BillingApiClient;
use App\Services\Billing\BillingApiException;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Multi-Bank Virtual Account Payment Gateway Integration (Bank Muamalat & BSI) via e-SPP webservice.
 */
class BillingApiGateway implements PaymentGateway
{
    public function __construct(
        private BillingApiClient $client,
    ) {}

    public function createInvoice(Payment $payment, Collection $bills, Guardian $payer): Payment
    {
        $primaryBill = $bills->first();
        $student = $primaryBill?->student;

        if (! $student) {
            throw new RuntimeException('Data siswa tidak ditemukan pada tagihan yang dipilih.');
        }

        $feeTypeCode = $primaryBill->feeType?->code ?? 'spp';
        $selectedBank = strtolower((string) ($payment->metadata['bank_channel'] ?? 'muamalat'));
        if (! in_array($selectedBank, ['muamalat', 'bsi'], true)) {
            $selectedBank = 'muamalat';
        }

        // One bill belongs to exactly one bank_id at e-SPP (main_form.bank_id
        // is singular) - generate only the chosen bank's VA. Generating both
        // and stuffing the unchosen bank's VA into bsm was the root cause of
        // "VA tidak dikenal" at m-banking: bsm is payment info for THIS SAME
        // bill (docs section 5.3), not a second bank's registration, so the
        // bank that wasn't chosen was never actually registered under it.
        $primaryVa = BillingApiClient::generateVaNumber($student, $primaryBill, $selectedBank);

        $bankConfig = config("services.billing_api.banks.{$selectedBank}") ?? [
            'bank_name' => $selectedBank === 'bsi' ? 'Bank Syariah Indonesia (BSI)' : 'Bank Muamalat',
            'bank_code' => $selectedBank === 'bsi' ? '451' : '147',
        ];
        // Unconfirmed with e-SPP as of 2026-09 - both banks default to '1'
        // until real values are given. See services.billing_api.banks.*.bank_id.
        $bankId = (string) ($bankConfig['bank_id'] ?? '1');

        $dueDays = (int) config('services.billing_api.va_due_days', 3);
        $dueDate = now()->addDays($dueDays);

        // Synchronize payment_number with fee type and student code if not already formatted
        $studentCode = BillingApiClient::formatStudentCode($student);
        $prefixRef = match (true) {
            str_contains($feeTypeCode, 'ekskul') => 'YAPI-EKS',
            str_contains($feeTypeCode, 'jamiyyah') => 'YAPI-JAM',
            str_contains($feeTypeCode, 'spp') => 'YAPI-SPP',
            default => 'YAPI-PAY',
        };
        $year = date('Y');
        $customPaymentNumber = sprintf('%s-%s-%s', $prefixRef, $year, $studentCode);

        // Ensure unique payment_number
        if ($payment->payment_number !== $customPaymentNumber) {
            if (Payment::where('payment_number', $customPaymentNumber)->where('id', '!=', $payment->id)->exists()) {
                $suffix = 2;
                while (Payment::where('payment_number', "{$customPaymentNumber}-{$suffix}")->where('id', '!=', $payment->id)->exists()) {
                    $suffix++;
                }
                $customPaymentNumber = "{$customPaymentNumber}-{$suffix}";
            }
            $payment->payment_number = $customPaymentNumber;
        }

        $description = $this->describe($bills, $student);
        $customerName = BillingApiClient::sanitizeCustomerName($student->nama_lengkap);

        try {
            $response = $this->client->createBilling(
                [
                    'customer_name' => $customerName,
                    'va_desc' => BillingApiClient::sanitizeDescription($description),
                    'va_desc1' => BillingApiClient::sanitizeDescription($student->schoolUnit?->label ?? '', 255),
                    'jumlah_tagihan' => (int) $payment->amount,
                    'date_start' => now()->toDateString(),
                    'date_end' => $dueDate->toDateString(),
                    'priority' => '1',
                    'pay_type' => 'full',
                    'sekolah' => $student->schoolUnit?->label ?? '',
                    'kelas' => $student->currentEnrollment()?->classroom?->name ?? '',
                    'bank_id' => $bankId,
                ],
                ['va_number' => $primaryVa, 'ref_number' => $payment->payment_number],
                // bsm is payment info for THIS SAME bill (docs section 5.3), not
                // a second bank - reuses the chosen bank's own VA plus the
                // payment's own reference, never the unchosen bank's VA.
                ['nomor_pembayaran' => $primaryVa, 'id_tagihan' => $payment->payment_number]
            );

            $rawUuid = $response['uuid'] ?? ($response['data']['uuid'] ?? null);
            $billingUuid = is_array($rawUuid) ? ($rawUuid['string'] ?? null) : $rawUuid;

            $gatewayResponse = [
                'provider' => 'bank_' . $selectedBank,
                'bank_key' => $selectedBank,
                'bank_id' => $bankId,
                'va_number' => $primaryVa,
                'bank_name' => (string) ($bankConfig['bank_name'] ?? ($selectedBank === 'bsi' ? 'Bank Syariah Indonesia (BSI)' : 'Bank Muamalat')),
                'bank_code' => (string) ($bankConfig['bank_code'] ?? ($selectedBank === 'bsi' ? '451' : '147')),
                'amount' => (float) $payment->amount,
                'due_date' => $dueDate->toDateString(),
                'billing_uuid' => $billingUuid,
                'customer_name' => $customerName,
                'student_name' => $student->nama_lengkap,
                'unit' => $student->schoolUnit?->label,
                'fee_type' => $primaryBill->feeType?->name,
                'raw' => $response,
            ];

            $payment->forceFill([
                'status' => 'processing',
                'external_transaction_id' => $billingUuid ?: $primaryVa,
                'invoice_id' => $billingUuid ?: $primaryVa,
                'invoice_url' => null,
                'expires_at' => $dueDate,
                'gateway_response' => $gatewayResponse,
            ])->save();

            return $payment;
        } catch (BillingApiException $e) {
            Log::error('[BillingApiGateway] Create billing failed', [
                'payment' => $payment->payment_number,
                'va_number' => $primaryVa,
                'error' => $e->getMessage(),
                'status' => $e->statusCode(),
            ]);

            // If API key/connection not provisioned yet in local development, provide fallback VA info
            if (! app()->isProduction()) {
                $gatewayResponse = [
                    'provider' => 'bank_' . $selectedBank,
                    'bank_key' => $selectedBank,
                    'bank_id' => $bankId,
                    'va_number' => $primaryVa,
                    'bank_name' => (string) ($bankConfig['bank_name'] ?? ($selectedBank === 'bsi' ? 'Bank Syariah Indonesia (BSI)' : 'Bank Muamalat')),
                    'bank_code' => (string) ($bankConfig['bank_code'] ?? ($selectedBank === 'bsi' ? '451' : '147')),
                    'amount' => (float) $payment->amount,
                    'due_date' => $dueDate->toDateString(),
                    'billing_uuid' => 'sim_'.uniqid(),
                    'customer_name' => $customerName,
                    'student_name' => $student->nama_lengkap,
                    'unit' => $student->schoolUnit?->label,
                    'fee_type' => $primaryBill->feeType?->name,
                    'simulated' => true,
                ];

                $payment->forceFill([
                    'status' => 'processing',
                    'external_transaction_id' => $primaryVa,
                    'invoice_id' => $primaryVa,
                    'invoice_url' => null,
                    'expires_at' => $dueDate,
                    'gateway_response' => $gatewayResponse,
                ])->save();

                return $payment;
            }

            throw new RuntimeException('Gagal membuat tagihan Virtual Account: '.$e->getMessage());
        }
    }

    /**
     * Shrinks a superseded VA payment's e-SPP bill to today, so it stops
     * accepting money at the bank counter.
     *
     * CheckoutService::supersedePendingPaymentsFor() and
     * supersedeOtherVaPaymentsForSameGroup() (bank switch, or simply
     * re-checking out) only ever flip the old Payment to 'failed' locally -
     * e-SPP's own bill for that VA kept its original date_end and stayed
     * fully payable there. Since PollBillingVaPayments only ever polls
     * pending/processing payments, a guardian who paid the abandoned VA
     * anyway after switching would have had that money land at e-SPP against
     * a bill our side had already stopped watching, with nothing to notice
     * it. Best-effort: e-SPP being unreachable must not block the local
     * supersession, since that already stops OUR system from double-issuing
     * against this bill - it just means the old VA stays open a little
     * longer than it should.
     *
     * CONFIRMED BROKEN on e-SPP's side as of 2026-09-04 (found live in PMB,
     * the sibling app against the same e-SPP account): PUT /api/billing/{uuid}
     * returns a 500 "Undefined index: main_form" for every payload shape
     * tried (wrapped main_form/bmi/bsm, flat fields, a POST+_method=PUT
     * override) - their route handler cannot read a PUT body at all, so no
     * client-side fix here can make this call succeed. Left in place because
     * it is harmless and will start working the moment that's fixed on their
     * end, but it is NOT a real safety net today - see
     * PollBillingVaPayments::handle()'s cancelled-payment check for the
     * actual one (watching failed/cancelled VAs for a surprise late payment
     * instead of relying on this ever closing them).
     */
    public function expireVa(Payment $payment): void
    {
        $rawUuid = $payment->gateway_response['billing_uuid'] ?? null;
        $billingUuid = is_array($rawUuid) ? ($rawUuid['string'] ?? null) : $rawUuid;

        // 'sim_' uuids are the non-production fallback minted when e-SPP was
        // unreachable at create time (see the catch block above) - nothing
        // real was ever registered for them.
        if (! $billingUuid || ! is_string($billingUuid) || str_starts_with($billingUuid, 'sim_')) {
            return;
        }

        try {
            $this->client->updateBilling($billingUuid, ['date_end' => now()->toDateString()]);
        } catch (\Throwable $e) {
            Log::warning('[BillingApiGateway] Failed to expire a superseded VA at e-SPP', [
                'payment' => $payment->payment_number,
                'va_number' => $payment->gateway_response['va_number'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The actual safety net for a superseded VA: since expireVa() cannot
     * reliably close it at e-SPP (its update endpoint is confirmed broken
     * there - see expireVa()'s docblock), the old VA stays genuinely payable
     * until its original due date. Nothing else in this app ever looks at a
     * failed/cancelled Payment again - PollBillingVaPayments only queries
     * pending/processing - so a late payment on it would otherwise vanish:
     * money at the bank, no record of it here.
     *
     * Does not auto-apply the money - crediting a bill that may already be
     * settled via the replacement VA needs a human to reconcile which
     * payment is real, not a guess. Logs loudly so that reconciliation
     * actually happens instead of the payment being silently lost.
     */
    public function checkForSurpriseLatePayment(Payment $payment): void
    {
        if (! in_array($payment->status, ['failed', 'cancelled'], true)) {
            return;
        }

        $vaNumber = $payment->gateway_response['va_number'] ?? null;

        if (! $vaNumber) {
            return;
        }

        try {
            $data = $this->client->getByVaNumber($vaNumber);
        } catch (BillingApiException $e) {
            return;
        }

        $sisa = $data['sisa'] ?? null;

        if ($sisa === null || (float) $sisa > 0) {
            return;
        }

        Log::critical('[BillingApiGateway] Money landed on a VA this app had already superseded and stopped watching - needs manual reconciliation', [
            'payment' => $payment->payment_number,
            'va_number' => $vaNumber,
            'amount' => (float) $payment->amount,
            'failed_at' => $payment->failed_at,
        ]);
    }

    private function describe(Collection $bills, ?\App\Models\Student $student): string
    {
        if ($bills->count() === 1) {
            $desc = (string) $bills->first()->description;
        } else {
            $desc = $bills->count().' tagihan ('.$bills->pluck('description')->take(2)->join(', ').'…)';
        }

        return $student ? "{$desc} - {$student->nama_lengkap}" : $desc;
    }
}
