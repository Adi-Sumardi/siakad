<?php

namespace Tests\Unit;

use App\Services\Billing\BillingApiClient;
use Tests\TestCase;

class BillingApiClientSanitizationTest extends TestCase
{
    public function test_it_never_lets_a_malformed_byte_sequence_through_va_desc_sanitization(): void
    {
        // A lone UTF-8 continuation byte with no lead byte - the shape that
        // crashed e-SPP's own server with "iconv(): Detected an incomplete
        // multibyte character in input string" against PMB's production
        // traffic (2026-09-02) when it reached them unsanitized.
        $malformed = "SPP Agustus - Budi\x80Santoso";

        $clean = BillingApiClient::sanitizeDescription($malformed);

        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
        $this->assertStringContainsString('SPP Agustus', $clean);
        $this->assertStringContainsString('Budi', $clean);
    }

    public function test_it_keeps_a_normal_description_intact_aside_from_disallowed_characters(): void
    {
        $clean = BillingApiClient::sanitizeDescription('2 tagihan (SPP Agustus, Uang Pangkal) - Test Siswa');

        $this->assertSame('2 tagihan (SPP Agustus, Uang Pangkal) - Test Siswa', $clean);
    }

    public function test_it_falls_back_to_a_placeholder_for_an_empty_description(): void
    {
        $this->assertSame('-', BillingApiClient::sanitizeDescription(null));
        $this->assertSame('-', BillingApiClient::sanitizeDescription(''));
    }

    public function test_it_truncates_an_overlong_description_without_splitting_a_multibyte_character(): void
    {
        $clean = BillingApiClient::sanitizeDescription(str_repeat('é', 300));

        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
        $this->assertLessThanOrEqual(255, mb_strlen($clean));
    }
}
