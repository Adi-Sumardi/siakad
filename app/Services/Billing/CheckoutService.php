<?php

namespace App\Services\Billing;

use App\Models\Bill;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\User;
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
     */
    public function start(User $user, array $billUlids, string $method): Payment
    {
        $bills = $this->collectPayable($user, $billUlids);
        $guardian = $user->guardian;

        if (! $guardian) {
            throw new RuntimeException('Akun ini tidak terhubung ke data wali murid.');
        }

        $amount = round((float) $bills->sum('remaining_amount'), 2);

        if ($amount <= 0) {
            throw new RuntimeException('Tidak ada tagihan yang perlu dibayar.');
        }

        $payment = DB::transaction(function () use ($guardian, $amount, $method, $bills) {
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
                $bills->mapWithKeys(fn (Bill $bill) => [$bill->id => (float) $bill->remaining_amount])->all(),
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
