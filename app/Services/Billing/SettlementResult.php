<?php

namespace App\Services\Billing;

/**
 * What a settle() attempt actually did, so each lane (webhook, poller,
 * checkout-stale, simulate) can react without re-deriving anything.
 *
 * claimed=false means the locked conditional UPDATE found the payment no
 * longer pending/processing: nothing was written and nothing was sent - the
 * caller treats the money as booked exactly once, by whoever won the claim.
 *
 * overpaid=true means at least one of the payment's allocations was flagged
 * applies_to_bill=false inside the claim transaction: the bill(s) it touched
 * were already covered by another completed payment, so this payment's money
 * is real but settles nothing - excess is the amount awaiting a TU refund,
 * and overpayment carries the per-bill detail also stored on
 * payment.metadata.overpayment.
 */
class SettlementResult
{
    public function __construct(
        public readonly bool $claimed,
        public readonly bool $overpaid = false,
        public readonly float $excess = 0.0,
        public readonly array $overpayment = [],
    ) {
    }

    public static function notClaimed(): self
    {
        return new self(false);
    }

    /** @param array{bills?: array<string, array{allocation_amount: float, headroom: float}>} $overpayment */
    public static function settled(array $overpayment, float $excess): self
    {
        return new self(true, $excess > 0, $excess, $overpayment);
    }
}
