<?php

namespace App\Services\Billing;

use App\Models\Bill;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Payment\BillingApiGateway;
use App\Services\Payment\PaymentGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a guardian's checked bills into one payable invoice.
 */
class CheckoutService
{
    public function __construct(
        private PaymentGateway $gateway,
        private PaymentAllocator $allocator,
        private BillingApiClient $client,
    ) {}

    /**
     * Creates a pending Payment row and its bill allocations, then hands off
     * to the payment gateway (e-SPP Virtual Account).
     *
     * @param  array<int, string>  $billUlids
     * @param  array<string, float|int|numeric-string>  $customAmounts  Bill ULID => custom partial amount
     */
    public function start(
        User $user,
        array $billUlids,
        string $method,
        array $customAmounts = [],
        string $bank = 'muamalat',
    ): Payment {
        $guardian = $user->guardian;

        if (! $guardian) {
            throw new RuntimeException('Akun ini tidak terdaftar sebagai wali murid.');
        }

        return $this->checkout($user, $guardian, $billUlids, $method, $customAmounts, $bank);
    }

    /**
     * The admin's door into the same lane the wali walks: same basket rules,
     * same prefix assertion, same supersede guards, same allocator - only the
     * payer differs, resolved by the caller (the student's billing contact)
     * so the payment stays visible to that family under /api/wali/payments.
     */
    public function startForGuardian(
        User $collector,
        Guardian $guardian,
        array $billUlids,
        string $method = 'virtual_account',
        array $customAmounts = [],
        string $bank = 'muamalat',
    ): Payment {
        return $this->checkout($collector, $guardian, $billUlids, $method, $customAmounts, $bank);
    }

    private function checkout(
        User $collector,
        Guardian $guardian,
        array $billUlids,
        string $method,
        array $customAmounts,
        string $bank,
    ): Payment {
        $bills = $this->collectPayable($collector, $billUlids);

        if ($bills->isEmpty()) {
            throw new RuntimeException('Tidak ada tagihan yang dapat dibayar.');
        }

        // VAs in this school are per-(student, fee type, academic year).
        // Mixing across either in one checkout produces a single VA that
        // quiet-covers bills it cannot represent.
        $this->assertSingleVaGroupInBasket($bills);

        $selectedBank = in_array(strtolower($bank), ['muamalat', 'bsi'], true) ? strtolower($bank) : 'muamalat';

        // Refuse before any payment row exists - a fee type e-SPP has no VA
        // prefix for cannot be paid by VA at all, and the old behavior (reusing
        // the SPP prefix) silently collided with the student's SPP VA.
        $this->assertVaPrefixAvailable($bills, $selectedBank);

        // Compute per-bill charge amount (either custom amount or remaining balance).
        // Whole rupiah end to end (audit T38-d): Indonesian VA rails carry no
        // cents, and the webhook compares e-SPP's registered amount against
        // ours with a 0.01 tolerance - a cents-bearing charge (percentage
        // discounts can produce one) permanently tripped "Amount mismatch"
        // and deferred every such settlement to the poller. The charge is
        // rounded, not just the registration, so all three sides agree.
        $allocations = [];
        $amount = 0.0;

        foreach ($bills as $bill) {
            $remaining = round((float) $bill->remaining_amount);
            $charge = $remaining;

            if (isset($customAmounts[$bill->ulid])) {
                $custom = round((float) $customAmounts[$bill->ulid]);
                // The portal's own form floor, enforced server-side too
                // (audit T67-d): the browser min is only a hint, and a
                // Rp 1 checkout mints a real e-SPP registration for noise.
                if ($custom < 10000) {
                    throw new RuntimeException("Nominal kustom untuk tagihan '{$bill->description}' minimal Rp 10.000.");
                }
                if ($custom > $remaining) {
                    throw new RuntimeException(
                        "Nominal kustom untuk tagihan '{$bill->description}' (Rp ".number_format($custom, 0, ',', '.').') melebihi sisa tagihan (Rp '.number_format($remaining, 0, ',', '.').').'
                    );
                }
                $charge = $custom;
            }

            $allocations[$bill->id] = $charge;
            $amount += $charge;
        }

        $amount = round($amount, 2);

        // Outside the transaction on purpose: supersedeOtherVaPaymentsForSameGroup()
        // may call the bank, and one of its outcomes settles money (which opens
        // its own transaction and notifies the family) - neither belongs inside
        // the checkout's lock window.
        $this->supersedePendingPaymentsFor($bills);
        $this->supersedeOtherVaPaymentsForSameGroup($bills);

        $payment = DB::transaction(function () use ($guardian, $bills, $amount, $method, $allocations, $selectedBank) {
            $payment = Payment::create([
                'payment_number' => Payment::generateNumber(),
                'payer_guardian_id' => $guardian->id,
                'amount' => $amount,
                'method' => $method,
                'status' => 'pending',
                'metadata' => [
                    'bill_ulids' => $bills->pluck('ulid')->all(),
                    'bank_channel' => $selectedBank,
                ],
            ]);

            $this->allocator->allocate(
                $payment,
                $allocations,
            );

            return $payment;
        });

        // Outside the transaction: the gateway is a network call
        try {
            return $this->gateway->createInvoice($payment, $bills, $guardian);
        } catch (\Throwable $e) {
            // The Payment row (and its allocations) are already committed;
            // a production registration failure used to leave the row
            // 'pending' forever - no VA for the poller to ask about, no
            // expires_at to age it out, invisible to every sweep (audit
            // T55-a). Failing it releases the basket and shows in the
            // family's feed as a checkout that did not go through, which
            // is exactly what happened. (Local dev's simulated-VA fallback
            // returns instead of throwing and never lands here.)
            $this->allocator->fail($payment, 'failed', 'Registrasi Virtual Account gagal: '.$e->getMessage());

            throw $e;
        }
    }

    /**
     * The bills a guardian may actually pay, in one pass.
     *
     * @return Collection<int, Bill>
     */
    private function collectPayable(User $user, array $billUlids): Collection
    {
        $bills = Bill::query()
            ->visibleTo($user)
            ->whereIn('ulid', $billUlids)
            ->open()
            ->get();

        if ($bills->count() !== count(array_unique($billUlids))) {
            throw new RuntimeException(
                'Sebagian tagihan tidak ditemukan, bukan milik Anda, atau sudah lunas. Muat ulang halaman.'
            );
        }

        return $bills;
    }

    /**
     * A VA number is generated once per (student, fee type, academic year).
     *
     * @param  Collection<int, Bill>  $bills
     */
    private function assertSingleVaGroupInBasket(Collection $bills): void
    {
        if (! $this->gateway instanceof BillingApiGateway) {
            return;
        }

        if ($bills->pluck('student_id')->unique()->count() > 1) {
            throw new RuntimeException(
                'Virtual Account bersifat khusus per anak. Mohon bayar tagihan tiap anak dalam transaksi terpisah.'
            );
        }

        if ($bills->pluck('fee_type_id')->unique()->count() > 1) {
            throw new RuntimeException(
                'Virtual Account bersifat khusus per jenis biaya (mis. SPP). Mohon bayar tiap jenis biaya dalam transaksi terpisah.'
            );
        }
    }

    /**
     * A fee type with no registered VA prefix must not reach the gateway.
     *
     * @param  Collection<int, Bill>  $bills
     */
    private function assertVaPrefixAvailable(Collection $bills, string $bank): void
    {
        if (! $this->gateway instanceof BillingApiGateway) {
            return;
        }

        $primary = $bills->first();

        if (! $primary) {
            return;
        }

        $feeTypeCode = $primary->feeType?->code ?? 'spp';

        if (BillingApiClient::resolvePrefix($feeTypeCode, $primary->student->schoolUnit, $bank) === null) {
            $name = $primary->feeType?->name ?? $feeTypeCode;

            throw new RuntimeException(
                "Jenis biaya '{$name}' belum punya nomor Virtual Account di bank. Silakan bayar tunai di Tata Usaha sementara."
            );
        }
    }

    /**
     * Supersedes older pending VA payments for the same student & fee type
     * group - but only after asking the bank about each one. A VA the parent
     * already paid must be SETTLED (the money exists), never silently failed;
     * the old behavior buried it and the money vanished from the system.
     *
     * @param  Collection<int, Bill>  $bills
     */
    private function supersedeOtherVaPaymentsForSameGroup(Collection $bills): void
    {
        if (! $this->gateway instanceof BillingApiGateway) {
            return;
        }

        $first = $bills->first();

        if (! $first) {
            return;
        }

        $siblingBillIds = Bill::query()
            ->where('student_id', $first->student_id)
            ->where('fee_type_id', $first->fee_type_id)
            ->pluck('id');

        $paymentIds = PaymentAllocation::whereIn('bill_id', $siblingBillIds)
            ->pluck('payment_id')
            ->unique();

        Payment::whereIn('id', $paymentIds)
            ->whereIn('status', ['pending', 'processing'])
            ->where(function ($q) {
                $q->whereIn('gateway_response->provider', ['bank_muamalat', 'bank_bsi'])
                    ->orWhereNotNull('gateway_response->va_number');
            })
            ->get()
            ->each(function (Payment $stale) {
                $this->settleStaleIfAlreadyPaid($stale);

                $this->failAndExpire(
                    $stale,
                    'Digantikan oleh checkout baru untuk anak dan jenis biaya yang sama.',
                );
            });
    }

    /**
     * Asks e-SPP whether this pending VA has in fact been paid. Fail-closed
     * on the response, same as the webhook: a missing "sisa" is "cannot
     * tell" (and then superseding proceeds only when the bank is reachable -
     * an unreachable bank aborts the whole checkout rather than risking it).
     */
    private function settleStaleIfAlreadyPaid(Payment $stale): void
    {
        $vaNumber = $stale->gateway_response['va_number'] ?? null;

        if (! $vaNumber) {
            return;
        }

        try {
            $statusRes = $this->client->getByVaNumber($vaNumber);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Tidak bisa menghubungi bank untuk memeriksa pembayaran Virtual Account sebelumnya ('.$e->getMessage().'). Mohon coba beberapa saat lagi.'
            );
        }

        $rawRemaining = $statusRes['sisa'] ?? $statusRes['data']['sisa'] ?? null;

        if ($rawRemaining !== null && is_numeric($rawRemaining) && (float) $rawRemaining <= 0) {
            // The money exists - book it under the payment that earned it.
            // settle() keeps the payment's own external id and gateway
            // response when handed empties.
            $this->allocator->settle($stale);

            throw new RuntimeException(
                'Pembayaran Virtual Account sebelumnya terdeteksi sudah dibayar dan baru saja dibukukan. Silakan muat ulang halaman tagihan.'
            );
        }
    }

    /**
     * Fails a superseded payment and, if it was a VA payment, also shrinks
     * its e-SPP bill to today so the abandoned VA stops accepting money at
     * the bank counter - see BillingApiGateway::expireVa() for why this
     * matters. Resolved from the container rather than $this->gateway: the
     * NEW checkout replacing this one may be on a different gateway, but the
     * STALE payment being failed here can still be the one still-open VA
     * that needs closing.
     */
    private function failAndExpire(Payment $stale, string $reason): void
    {
        $this->allocator->fail($stale, 'failed', $reason);

        $provider = $stale->gateway_response['provider'] ?? null;
        if (in_array($provider, ['bank_muamalat', 'bank_bsi'], true)) {
            app(BillingApiGateway::class)->expireVa($stale);
        }
    }

    /**
     * Fails every still-pending or still-processing payment that touches any
     * of these bills, so at most one live invoice ever exists per bill.
     *
     * @param  Collection<int, Bill>  $bills
     */
    private function supersedePendingPaymentsFor(Collection $bills): void
    {
        $paymentIds = PaymentAllocation::whereIn('bill_id', $bills->pluck('id'))
            ->pluck('payment_id')
            ->unique();

        Payment::whereIn('id', $paymentIds)
            ->whereIn('status', ['pending', 'processing'])
            ->get()
            ->each(fn (Payment $stale) => $this->failAndExpire(
                $stale,
                'Digantikan oleh checkout baru untuk tagihan yang sama.',
            ));
    }

    /**
     * Fails every still-pending online payment that touches this bill - the
     * shared guard for every lane that takes a bill out of the payable world
     * by other means (today: waived and cancelled; payment itself is VA-only
     * per the school's 2026-09-21 decision, so the VA IS the lane that must
     * never outlive the decision). A lingering bank invoice that completes
     * AFTER one of those decisions is a payment the system no longer has a
     * place for (double charge / money against a closed bill).
     *
     * VA payments are asked about at the bank first (settleStaleIfAlreadyPaid):
     * one that turns out to have been paid is settled - the money exists and
     * must be booked - and the RuntimeException it then throws aborts the
     * caller, because the bill is no longer in the state the caller assumed.
     * An unreachable bank also aborts (fail-closed): voiding an invoice the
     * bank may already have collected is how money disappears.
     */
    public function voidPendingPaymentsFor(Bill $bill, string $reason): void
    {
        $paymentIds = PaymentAllocation::where('bill_id', $bill->id)
            ->pluck('payment_id')
            ->unique();

        Payment::whereIn('id', $paymentIds)
            ->whereIn('status', ['pending', 'processing'])
            ->get()
            ->each(function (Payment $pending) use ($reason) {
                if ($this->gateway instanceof BillingApiGateway) {
                    $this->settleStaleIfAlreadyPaid($pending);
                }

                $this->failAndExpire($pending, $reason);
            });
    }
}
