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
 * Turns a basket of bills into one payment.
 *
 * The whole point is that a parent settles three months of SPP for two children
 * in a single transaction: one invoice, one bank admin fee, one thing to
 * remember. The bills stay separate in the ledger through payment_allocations,
 * so each one still knows exactly what it received.
 */
class CheckoutService
{
    public function __construct(
        private PaymentAllocator $allocator,
        private PaymentGateway $gateway,
    ) {}

    /**
     * @param  list<string>  $billUlids
     * @param  array<string, float>  $customAmounts
     */
    public function start(User $user, array $billUlids, string $method, array $customAmounts = []): Payment
    {
        $bills = $this->collectPayable($user, $billUlids);
        $guardian = $user->guardian;

        if (! $guardian) {
            throw new RuntimeException('Akun ini tidak terhubung ke data wali murid.');
        }

        $this->assertSingleStudentForVaGateway($bills);

        $allocations = [];
        foreach ($bills as $bill) {
            $max = (float) $bill->remaining_amount;
            if (isset($customAmounts[$bill->ulid])) {
                if (! $bill->allow_installment) {
                    throw new RuntimeException("Tagihan {$bill->description} tidak mengizinkan pembayaran cicilan/kustom. Pembayaran harus dilakukan lunas.");
                }
                $custom = round((float) $customAmounts[$bill->ulid], 2);
                if ($custom <= 0 || $custom > $max) {
                    throw new RuntimeException("Jumlah pembayaran untuk {$bill->description} tidak valid (Maksimal Rp ".number_format($max, 0, ',', '.').').');
                }
                $allocations[$bill->id] = $custom;
            } else {
                $allocations[$bill->id] = $max;
            }
        }

        $amount = round(array_sum($allocations), 2);

        if ($amount <= 0) {
            throw new RuntimeException('Tidak ada tagihan yang perlu dibayar.');
        }

        $payment = DB::transaction(function () use ($guardian, $amount, $method, $bills, $allocations) {
            // A bill may have at most one live invoice. Without this, a
            // double-click or two open tabs each mint their own pending
            // payment for the same bill, and if a parent somehow settles both
            // - two Xendit invoices, two transfers - the bill ends up marked
            // paid for more than it was ever owed, with nothing to flag it.
            // Superseding is safe: a pending payment has reserved nothing (see
            // PaymentAllocator's class docblock), so voiding the old one loses
            // no money that actually moved.
            $this->supersedePendingPaymentsFor($bills);

            $payment = Payment::create([
                'payment_number' => Payment::generateNumber(),
                'payer_guardian_id' => $guardian->id,
                'amount' => $amount,
                'method' => $method,
                'status' => 'pending',
                'metadata' => ['bill_ulids' => $bills->pluck('ulid')->all()],
            ]);

            // Allocated up front, while still 'pending'. PaymentAllocator only
            // counts allocations of completed payments, so this reserves nothing
            // and no bill looks paid until the money actually lands.
            $this->allocator->allocate(
                $payment,
                $allocations,
            );

            return $payment;
        });

        // Outside the transaction: the gateway is a network call, and holding a
        // database transaction open across one is how deadlocks are made.
        return $this->gateway->createInvoice($payment, $bills, $guardian);
    }

    /**
     * The bills a guardian may actually pay, in one pass.
     *
     * Ownership is re-checked here rather than trusted from the request. The
     * list arrives from the browser, and "pay these ulids" without this check
     * would let anyone settle - or inspect - another family's bills.
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
     * A Bank Muamalat VA number is generated once per (student, fee type,
     * academic year) - not once per checkout or per bill - because that is
     * how this school's real VA system works: a family transfers into the
     * same number every month for that child. See
     * BillingApiClient::generateVaNumber(). Nothing else about checkout
     * knows this - the wali basket happily mixes bills from more than one
     * child on purpose, to settle several months for several kids in one
     * transaction and one bank fee - and if it did here, the combined
     * amount would register under only the first child's VA while quietly
     * covering another child's bill too, with no way to tell whose transfer
     * a given VA number actually was for.
     *
     * @param  Collection<int, Bill>  $bills
     */
    private function assertSingleStudentForVaGateway(Collection $bills): void
    {
        if (! $this->gateway instanceof BillingApiGateway) {
            return;
        }

        if ($bills->pluck('student_id')->unique()->count() > 1) {
            throw new RuntimeException(
                'Virtual Account Bank Muamalat bersifat khusus per anak. Mohon bayar tagihan tiap anak dalam transaksi terpisah.'
            );
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
            ->each(fn (Payment $stale) => $this->allocator->fail(
                $stale,
                'failed',
                'Digantikan oleh checkout baru untuk tagihan yang sama.',
            ));
    }

    /**
     * A payment recorded by staff: cash at the front desk, or a verified
     * transfer. Settles immediately - the money is already in hand, there is no
     * gateway to wait for.
     */
    public function recordManual(
        Bill $bill,
        float $amount,
        string $method,
        User $actor,
        ?Guardian $payer = null,
        ?string $notes = null,
    ): Payment {
        if (! $bill->isOpen()) {
            throw new RuntimeException('Tagihan ini sudah lunas atau ditutup.');
        }

        if ($amount <= 0 || $amount > (float) $bill->remaining_amount) {
            throw new RuntimeException('Jumlah pembayaran melebihi sisa tagihan.');
        }

        $payment = Payment::create([
            'payment_number' => Payment::generateNumber(),
            'payer_guardian_id' => $payer?->id,
            'amount' => round($amount, 2),
            'method' => $method,
            'status' => 'pending',
            'recorded_by' => $actor->id,
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'verification_notes' => $notes,
        ]);

        $this->allocator->allocate($payment, [$bill->id => round($amount, 2)]);
        $this->allocator->settle($payment);

        return $payment->fresh();
    }
}
