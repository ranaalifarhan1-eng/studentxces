<?php

namespace App\Support;

use InvalidArgumentException;

class Money
{
    /**
     * Convert an arbitrary decimal or integer money representation to exact integer cents
     * without EVER converting the source decimal string to a float.
     *
     * Examples:
     *   "5000.25" -> 500025
     *   "0.10"    -> 10
     *   "5000"    -> 500000
     *   "5000.5"  -> 500050
     *   "5000.50" -> 500050
     *   "0.01"    -> 1
     *   0         -> 0
     *   5000      -> 500000
     *
     * Rejects malformed strings (e.g. "abc", "12.345", "--5", "12.3.4", "").
     */
    public static function toCents(string|int|null $amount): int
    {
        if ($amount === null) {
            return 0;
        }

        if (is_int($amount)) {
            return $amount * 100;
        }

        $trimmed = trim((string) $amount);
        if ($trimmed === '') {
            throw new InvalidArgumentException("Financial amount cannot be empty.");
        }

        $isNegative = false;
        if (str_starts_with($trimmed, '+')) {
            $trimmed = substr($trimmed, 1);
        } elseif (str_starts_with($trimmed, '-')) {
            $isNegative = true;
            $trimmed = substr($trimmed, 1);
        }

        // Validate format: must contain digits, and at most one decimal point with 1 or 2 digits
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $trimmed)) {
            throw new InvalidArgumentException("Malformed financial amount: '{$amount}'");
        }

        $parts = explode('.', $trimmed);
        $units = (int) $parts[0];
        $cents = 0;

        if (isset($parts[1])) {
            $fraction = $parts[1];
            if (strlen($fraction) === 1) {
                $cents = ((int) $fraction) * 10;
            } elseif (strlen($fraction) === 2) {
                $cents = (int) $fraction;
            }
        }

        $totalCents = ($units * 100) + $cents;
        return $isNegative ? -$totalCents : $totalCents;
    }

    /**
     * Convert integer cents to a standard 2-decimal string (e.g. 500025 -> "5000.25")
     * without using float arithmetic.
     */
    public static function toDecimal(int $cents): string
    {
        $isNegative = $cents < 0;
        $absCents = abs($cents);

        $units = intdiv($absCents, 100);
        $rem   = $absCents % 100;

        $formatted = sprintf('%d.%02d', $units, $rem);
        return $isNegative ? '-' . $formatted : $formatted;
    }

    /**
     * Sum an iterable collection or array of decimal amounts into integer cents.
     */
    public static function sumToCents(iterable $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += self::toCents($amount);
        }
        return $total;
    }

    /**
     * Add two money representations and return exact formatted decimal string.
     */
    public static function add(string|int|null $a, string|int|null $b): string
    {
        return self::toDecimal(self::toCents($a) + self::toCents($b));
    }

    /**
     * Subtract b from a and return exact formatted decimal string.
     */
    public static function sub(string|int|null $a, string|int|null $b): string
    {
        return self::toDecimal(self::toCents($a) - self::toCents($b));
    }
}
