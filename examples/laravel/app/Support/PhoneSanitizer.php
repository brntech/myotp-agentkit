<?php

namespace App\Support;

/**
 * MyOTP requires phone numbers in international format with no leading "+"
 * or "0" -- digits only. Example: "14155551234". The API rejects anything
 * else with a 400.
 */
class PhoneSanitizer
{
    public static function sanitize(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        return ltrim($digits, '0');
    }
}
