<?php

namespace Tests\Unit;

use App\Services\Notification\QontakWhatsAppGateway;
use PHPUnit\Framework\TestCase;

/**
 * Mekari's to_number contract is the 62-prefixed form; the app stores the
 * local 08xx form. One place converts (audit T42-b) - these are its edges.
 */
class QontakNumberTest extends TestCase
{
    public function test_the_stored_local_form_becomes_international(): void
    {
        $this->assertSame('6281234567890', QontakWhatsAppGateway::toQontakNumber('081234567890'));
    }

    public function test_already_international_forms_pass_through(): void
    {
        $this->assertSame('6281234567890', QontakWhatsAppGateway::toQontakNumber('6281234567890'));
        $this->assertSame('6281234567890', QontakWhatsAppGateway::toQontakNumber('+62 812-3456-7890'));
    }

    public function test_bare_digits_get_the_country_code(): void
    {
        $this->assertSame('6281234567890', QontakWhatsAppGateway::toQontakNumber('81234567890'));
    }
}
