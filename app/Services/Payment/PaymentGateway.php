<?php

namespace App\Services\Payment;

use App\Models\Guardian;
use App\Models\Payment;
use Illuminate\Support\Collection;

interface PaymentGateway
{
    /**
     * Attaches a hosted invoice to a pending payment and returns it with
     * invoice_id / invoice_url filled in.
     *
     * @param  Collection<int, \App\Models\Bill>  $bills  what the invoice itemises
     */
    public function createInvoice(Payment $payment, Collection $bills, Guardian $payer): Payment;
}
