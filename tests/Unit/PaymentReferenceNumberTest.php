<?php

namespace Tests\Unit;

use App\Models\Payment;
use PHPUnit\Framework\TestCase;

/**
 * "No. Referensi" on the invoice, the receipt and the wali screens is the
 * Virtual Account - the column e-SPP's billing list shows - so staff can
 * match a receipt to e-SPP by eye (requested 2026-09-23).
 */
class PaymentReferenceNumberTest extends TestCase
{
    public function test_it_is_the_va_number_when_the_payment_has_one(): void
    {
        $payment = new Payment([
            'payment_number' => 'YAPI-SPP-2026-000001-2',
            'gateway_response' => ['va_number' => '3656012728000001'],
        ]);

        $this->assertSame('3656012728000001', $payment->referenceNumber());
    }

    public function test_it_falls_back_to_the_internal_number_without_a_va(): void
    {
        $payment = new Payment(['payment_number' => 'PAY/20260923/ABCDEF', 'gateway_response' => null]);

        $this->assertSame('PAY/20260923/ABCDEF', $payment->referenceNumber());
    }
}
