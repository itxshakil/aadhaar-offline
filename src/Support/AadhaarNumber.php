<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Support;

/**
 * Format checks and masking for Aadhaar numbers.
 *
 * A valid Aadhaar number has 12 digits, does not start with 0 or 1,
 * and ends with a Verhoeff check digit.
 */
final class AadhaarNumber
{
    /**
     * Strip spaces and hyphens, e.g. "2345 6789 0123" -> "234567890123".
     */
    public static function normalize(string $value): string
    {
        return (string) preg_replace('/[\s-]+/', '', trim($value));
    }

    /**
     * Whether the value is a structurally valid Aadhaar number.
     */
    public static function isValid(string $value): bool
    {
        $number = self::normalize($value);

        if (preg_match('/^[2-9]\d{11}$/', $number) !== 1) {
            return false;
        }

        return Verhoeff::validate($number);
    }

    /**
     * Mask an Aadhaar number (or its last four digits) as "XXXX XXXX 1234".
     *
     * Accepts a full 12-digit number, an already-masked value, or the last four digits.
     * Returns null when no last-four digits can be found.
     */
    public static function mask(string $value, string $maskCharacter = 'X', bool $grouped = true): ?string
    {
        $lastFour = self::lastFour($value);

        if ($lastFour === null) {
            return null;
        }

        $masked = str_repeat($maskCharacter, 8) . $lastFour;

        return $grouped ? implode(' ', mb_str_split($masked, 4)) : $masked;
    }

    /**
     * Extract the last four digits from a full, masked, or last-four value.
     */
    public static function lastFour(string $value): ?string
    {
        $normalized = self::normalize($value);

        if (preg_match('/(\d{4})$/', $normalized, $matches) !== 1) {
            return null;
        }

        // A full number or a masked value ("XXXXXXXX1234") must be 12 characters; a bare suffix must be 4.
        $length = mb_strlen($normalized);

        return $length === 12 || $length === 4 ? $matches[1] : null;
    }

    /**
     * Whether the value looks like an unmasked 12-digit Aadhaar number.
     */
    public static function looksUnmasked(string $value): bool
    {
        return preg_match('/^\d{12}$/', self::normalize($value)) === 1;
    }
}
