<?php

namespace App\Services\Payment;

use App\Models\Bill;
use App\Models\Guardian;
use App\Models\IntegrationEvent;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Billing\BillingApiClient;
use App\Services\Billing\BillingApiException;
use App\Services\Billing\PaymentAllocator;
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
        private PaymentAllocator $allocator,
    ) {}

    /**
     * Optional $expiresAt overrides the config va_due_days window - used by
     * ensureReminderVa() so a reminder's VA stays payable through the
     * bill's own due date instead of dying mid-beat (audit T62-b). Optional
     * extra parameters on an interface implementation are signature-
     * compatible, so PaymentGateway itself stays unchanged.
     */
    public function createInvoice(Payment $payment, Collection $bills, Guardian $payer, ?Carbon $expiresAt = null): Payment
    {
        $primaryBill = $bills->first();
        $student = $primaryBill?->student;

        if (! $student) {
            throw new RuntimeException('Data siswa tidak ditemukan pada tagihan yang dipilih.');
        }

        // Cheap guard before anything leaves the building (audit 2026-10-05):
        // a checkout's supersede pass can fail this row between its Payment
        // insert and this call - registering a VA for a payment this system
        // has already closed would resurrect it, so refuse up front.
        if (! in_array($payment->fresh()->status, ['pending', 'processing'], true)) {
            throw new RuntimeException(
                'Pembayaran sudah ditutup (status: '.$payment->fresh()->status.') sebelum Virtual Account terbit - registrasi dibatalkan.'
            );
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
        $dueDate = $expiresAt ?? now()->addDays($dueDays);

        // Synchronize payment_number with fee type and student code if not already formatted
        $studentCode = BillingApiClient::formatStudentCode($student);
        $prefixRef = match (true) {
            str_contains($feeTypeCode, 'ekskul') => 'YAPI-EKS',
            str_contains($feeTypeCode, 'cambridge') => 'YAPI-CAM',
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
                    // Whole rupiah, properly rounded (audit T38-d): (int)
                    // truncated 500.99 down to 500, which itself disagrees
                    // with payment->amount; the checkout now rounds charges,
                    // this keeps registrations honest for payments created
                    // before that or by other lanes.
                    'jumlah_tagihan' => (int) round((float) $payment->amount),
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

            return $this->claimVaRegistration($payment, [
                'status' => 'processing',
                'external_transaction_id' => $billingUuid ?: $primaryVa,
                'invoice_id' => $billingUuid ?: $primaryVa,
                'invoice_url' => null,
                'expires_at' => $dueDate,
                'gateway_response' => $gatewayResponse,
            ], $gatewayResponse['billing_uuid'] ?? null);
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

                return $this->claimVaRegistration($payment, [
                    'status' => 'processing',
                    'external_transaction_id' => $primaryVa,
                    'invoice_id' => $primaryVa,
                    'invoice_url' => null,
                    'expires_at' => $dueDate,
                    'gateway_response' => $gatewayResponse,
                ]);
            }

            throw new RuntimeException('Gagal membuat tagihan Virtual Account: '.$e->getMessage());
        }
    }

    /**
     * Registers ONE bank's Virtual Account for a reminder - or reuses
     * whatever VA payment is already live for the bill, whichever bank
     * issued it.
     *
     * Replaces the deliberate two-bank pair (removed 2026-10-05): the pair
     * was the only lane that kept two banks' VAs live for one bill at once,
     * and a family that paid both within seconds produced two completed
     * payments with the overpayment buried by max(0, ...). The reminder now
     * carries a single VA - the bank a still-live checkout already chose
     * when one exists, otherwise services.billing_api.reminder_bank
     * (Muamalat by default). BSI stays one tap away in the app's checkout
     * (its own bank selector), whose supersede guards keep the one-live-VA-
     * per-bill rule; and any cross-lane pair that still slips through is
     * caught by the overpayment guard in PaymentAllocator::settle() itself.
     *
     * Still idempotent: a re-beat (h7/h1/overdue) reuses the live payment
     * instead of minting a fresh Payment row every few days.
     *
     * @return array{bank: string, va_number: string, bank_name: string}
     */
    public function ensureReminderVa(Bill $bill, Guardian $payer): array
    {
        $payment = $this->liveVaPaymentFor($bill);

        if ($payment) {
            // The message quotes the bill's CURRENT remaining; the bank
            // charges whatever the live VA was REGISTERED for (audit
            // 2026-10-05). Reusing a stale-amount VA - a partial checkout
            // remainder, a basket whose other bills were since paid - hands
            // the family a number that disagrees with the message and
            // quietly settles sibling bills the message never named. Void
            // every pending payment on the bill (money-safe: the void asks
            // the bank first and settles anything already paid) and mint a
            // fresh, honest VA instead.
            $registeredForThisBill = (float) (PaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->where('bill_id', $bill->id)
                ->value('amount') ?? 0.0);

            if (abs($registeredForThisBill - round((float) $bill->remaining_amount)) > 0.01) {
                app(\App\Services\Billing\CheckoutService::class)->voidPendingPaymentsFor(
                    $bill,
                    'Nominal Virtual Account tidak sesuai sisa tagihan saat pengingat terkirim - diterbitkan VA baru.',
                );

                $payment = null;
            }
        }

        if (! $payment) {
            $bank = strtolower((string) config('services.billing_api.reminder_bank', 'muamalat'));
            $bank = in_array($bank, ['muamalat', 'bsi'], true) ? $bank : 'muamalat';

            // Whole rupiah, exactly like the checkout lane (audit
            // 2026-09-28): rounding to 2dp here re-opened the T38-d
            // class - a percent-discount remainder like ...001,50 made
            // the VA amount and payment.amount disagree with the
            // webhook's (int) comparison, failing every such settlement
            // into integration_events noise even though the poller
            // still landed the money.
            $remaining = (int) round((float) $bill->remaining_amount);

            $payment = Payment::create([
                'payment_number' => Payment::generateNumber(),
                'payer_guardian_id' => $payer->id,
                'amount' => $remaining,
                'method' => 'virtual_account',
                'status' => 'pending',
                'metadata' => [
                    'bill_ulids' => [$bill->ulid],
                    'bank_channel' => $bank,
                    // Marks this row as reminder-created, not a normal
                    // checkout - not load-bearing for any logic today,
                    // but distinguishes the two if a future admin screen
                    // or report ever needs to tell them apart.
                    'source' => 'spp_reminder',
                ],
            ]);

            $this->allocator->allocate($payment, [$bill->id => $remaining]);

            // Reminder VAs must outlive the beat that minted them (audit
            // T62-b): a 3-day va_due_days window dies mid-flight for an
            // H-7 beat, leaving days 4-5 holding a number the bank
            // already refuses. The window stretches to the bill's own
            // due date when that is further out; nearer due dates (H-1,
            // overdue) keep the plain va_due_days floor.
            $window = now()->addDays((int) config('services.billing_api.va_due_days', 3));
            $expiresAt = $bill->due_date->endOfDay()->gt($window) ? $bill->due_date->endOfDay() : $window;

            $payment = $this->createInvoice($payment, collect([$bill]), $payer, $expiresAt);
        }

        return [
            'bank' => (string) ($payment->gateway_response['bank_key'] ?? ''),
            'va_number' => (string) ($payment->gateway_response['va_number'] ?? ''),
            'bank_name' => (string) ($payment->gateway_response['bank_name'] ?? ''),
        ];
    }

    /**
     * An already-registered, still-payable VA payment for this bill, on
     * whichever bank issued it. Bank-agnostic on purpose: the reminder must
     * reuse the bank a live checkout chose, not blindly re-register the
     * default bank - that would mint the second simultaneous VA the
     * single-VA reminder exists to avoid.
     */
    private function liveVaPaymentFor(Bill $bill): ?Payment
    {
        $paymentIds = PaymentAllocation::where('bill_id', $bill->id)->pluck('payment_id');

        return Payment::whereIn('id', $paymentIds)
            ->whereIn('status', ['pending', 'processing'])
            ->where(function ($q) {
                $q->whereIn('gateway_response->provider', ['bank_muamalat', 'bank_bsi'])
                    ->orWhereNotNull('gateway_response->va_number');
            })
            ->latest('id')
            ->first();
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
        // 'expired' included (audit T67-e) - same reasoning as the poller's
        // superseded query: this app stamped the expiry itself, and the bank
        // has proven it may still take money on it.
        if (! in_array($payment->status, ['failed', 'cancelled', 'expired'], true)) {
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

        // Same dual-shape read as the webhook and the poller: e-SPP has been
        // seen answering both flat and nested under 'data', and this net is
        // the one lane that used to read only the flat shape - a nested
        // answer kept it permanently blind to money it existed to catch.
        $sisa = $data['sisa'] ?? $data['data']['sisa'] ?? null;

        if ($sisa === null || (float) $sisa > 0) {
            return;
        }

        Log::critical('[BillingApiGateway] Money landed on a VA this app had already superseded and stopped watching - needs manual reconciliation', [
            'payment' => $payment->payment_number,
            'va_number' => $vaNumber,
            'amount' => (float) $payment->amount,
            'failed_at' => $payment->failed_at,
        ]);

        // And a row a human can actually find (audit 2026-10-05 P0): the
        // critical log line rotates away with laravel.log, and finance has
        // no screen that reads it. A failed billing_api integration event
        // shows on the ruang kontrol next to the other "needs a human"
        // rows - stable event id per payment+VA so repeated poller beats
        // update one row instead of piling up one per day.
        $event = IntegrationEvent::firstOrCreate(
            ['event_id' => 'billing_api:surprise:'.$payment->ulid.':'.$vaNumber],
            [
                'source' => 'billing_api',
                'event_type' => 'payment.surprise_late',
                'payload' => [
                    'payment_ulid' => $payment->ulid,
                    'payment_number' => $payment->payment_number,
                    'va_number' => $vaNumber,
                    'amount' => (float) $payment->amount,
                    'status' => $payment->status,
                    'failed_at' => $payment->failed_at?->toIso8601String(),
                ],
                'status' => 'received',
            ],
        );

        if ($event->wasRecentlyCreated || $event->status !== 'failed') {
            $event->markFailed(
                "Uang mendarat di VA {$vaNumber} ({$payment->payment_number}, status {$payment->status}) yang sudah tidak diawasi - perlu rekonsiliasi manual oleh TU."
            );
        }
    }

    /**
     * Flips a freshly-registered payment to 'processing' only if it is
     * still pending/processing, under a row lock (audit 2026-10-05).
     *
     * createInvoice() used to forceFill unconditionally: a supersede
     * landing between the Payment insert and this save turned a 'failed'
     * row back into a live VA registration - resurrecting exactly the
     * second-live-VA this app's whole billing model refuses. When the
     * claim is lost, the e-SPP registration that just succeeded is closed
     * best-effort (expireVa semantics: harmless if their endpoint is
     * still broken) and the exception lets the caller surface the abort.
     *
     * @param  array<string, mixed>  $forceFill
     */
    private function claimVaRegistration(Payment $payment, array $forceFill, ?string $billingUuid = null): Payment
    {
        $claimed = \Illuminate\Support\Facades\DB::transaction(function () use ($payment, $forceFill) {
            $fresh = Payment::query()
                ->whereKey($payment->id)
                ->whereIn('status', ['pending', 'processing'])
                ->lockForUpdate()
                ->first();

            if (! $fresh) {
                return false;
            }

            $fresh->forceFill($forceFill)->save();
            $payment->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });

        if ($claimed) {
            return $payment;
        }

        // The row was closed while we were registering - close what we just
        // created at e-SPP too, so the bank cannot take money against a
        // payment this system has already abandoned.
        if ($billingUuid && is_string($billingUuid) && ! str_starts_with($billingUuid, 'sim_')) {
            try {
                $this->client->updateBilling($billingUuid, ['date_end' => now()->toDateString()]);
            } catch (\Throwable $e) {
                Log::warning('[BillingApiGateway] Could not close an orphaned registration at e-SPP', [
                    'payment' => $payment->payment_number,
                    'billing_uuid' => $billingUuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new RuntimeException(
            'Pembayaran sudah ditutup sebelum Virtual Account terbit - registrasi dibatalkan.'
        );
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
