<?php

namespace App\Services\Notification;

class PhoneNumberFormatter
{
    /**
     * Normalize an Indonesian phone number to local 08xxxxxxxxxx format -
     * matches Sendago's documented request example ("to": "081234567890"),
     * not the 62-prefixed international format.
     * Returns null for empty input.
     */
    public static function toWhatsAppFormat(?string $phoneNumber): ?string
    {
        if (empty($phoneNumber)) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phoneNumber);

        if (empty($digits)) {
            return null;
        }

        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        } elseif (! str_starts_with($digits, '0')) {
            $digits = '0'.$digits;
        }

        return $digits;
    }
}
