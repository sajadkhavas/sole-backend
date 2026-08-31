<?php

namespace App\Services\Auth;

use InvalidArgumentException;

class IranMobileNormalizer
{
    public function normalize(string $value): string
    {
        $digits = preg_replace('/\D+/', '', trim($value));

        if ($digits === null) {
            throw new InvalidArgumentException('Invalid mobile number.');
        }

        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '98')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (! preg_match('/^9\d{9}$/', $digits)) {
            throw new InvalidArgumentException('A valid Iranian mobile number is required.');
        }

        return '+98'.$digits;
    }
}
