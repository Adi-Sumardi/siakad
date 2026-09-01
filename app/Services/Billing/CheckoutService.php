<?php

namespace App\Services\Billing;

use App\Models\Bill;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Payment\BillingApiGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\SendagoPayGateway;
use App\Services\Payment\XenditGateway;
use Carbon\Carbon;
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
    ) {}

    /**
     * Creates a pending Payment row and its bill allocations, then hands off
     * to the active payment gateway (e-SPP Virtual Account, SendagoPay, or Xendit).
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

        $bills = $this->collectPayable($user, $billUlids);

        if ($bills->isEmpty()) {
            throw new RuntimeException('Tidak ada tagihan yang dapat dibayar.');
        }

        // VAs in this school are per-(student, fee type, academic year).
        // Mixing across either in one checkout produces a single VA that
        // quiet-covers bills it cannot represent.
        $this->assertSingleVaGroupInBasket($bills);

        $selectedBank = in_array(strtolower($bank), ['muamalat', 'bsi'], true) ? strtolower($bank) : 'muamalat';

        // Compute per-bill charge amount (either custom amount or remaining balance)
        $allocations = [];
        $amount = 0.0;

        foreach ($bills as $bill) {
            $remaining = (float) $bill->remaining_amount;
            $charge = $remaining;

            if (isset($customAmounts[$bill->ulid])) {
                $custom = round((float) $customAmounts[$bill->ulid], 2);
                if ($custom <= 0) {
                    throw new RuntimeException("Nominal kustom untuk tagihan '{$bill->description}' harus lebih dari 0.");
                }
                if ($custom > $remaining) {
                    throw new RuntimeException(
                        "Nominal kustom untuk tagihan '{$bill->description}' (Rp ".number_format($custom, 0, ',', '.').") melebihi sisa tagihan (Rp ".number_format($remaining, 0, ',', '.').').'
                    );
                }
                $charge = $custom;
            }

            $allocations[$bill->id] = $charge;
            $amount += $charge;
        }

        $amount = round($amount, 2);

        $payment = DB::transaction(function () use ($guardian, $bills, $amount, $method, $allocations, $selectedBank) {
            $this->supersedePendingPaymentsFor($bills);
            $this->supersedeOtherVaPaymentsForSameGroup($bills);

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
        return $this->gateway->createInvoice($payment, $bills, $guardian);
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
     * Supersedes older pending VA payments for the same student & fee type group.
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
            ->each(fn (Payment $stale) => $this->allocator->fail(
                $stale,
                'failed',
                'Digantikan oleh checkout baru untuk anak dan jenis biaya yang sama.',
            ));
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
     * A payment recorded by staff: cash at the front desk, or a verified transfer.
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
