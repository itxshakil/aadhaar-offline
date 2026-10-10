<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Security;

/**
 * Matches a claimed mobile number or email against the hashes in an Offline e-KYC payload.
 *
 * UIDAI scheme: SHA-256 of (value + share code), hashed repeatedly N times,
 * where N is the last digit of the Aadhaar number. When that digit is 0 or 1
 * the value is hashed exactly once.
 */
final class HashMatcher
{
    /**
     * Compute the UIDAI hash for a value.
     */
    public static function hash(string $value, string $shareCode, int $lastDigit): string
    {
        $rounds = max(1, $lastDigit);
        $hash = $value . $shareCode;

        for ($i = 0; $i < $rounds; $i++) {
            $hash = hash('sha256', $hash);
        }

        return $hash;
    }

    /**
     * Whether a mobile number matches the given UIDAI hash.
     *
     * Accepts "9876543210", "+91 98765 43210", "09876543210" and similar.
     */
    public static function matchesMobile(string $mobile, string $expectedHash, string $shareCode, int $lastDigit): bool
    {
        $normalized = self::normalizeMobile($mobile);

        if ($normalized === null || $expectedHash === '') {
            return false;
        }

        return hash_equals(mb_strtolower($expectedHash), self::hash($normalized, $shareCode, $lastDigit));
    }

    /**
     * Whether an email address matches the given UIDAI hash.
     *
     * The address is trimmed and tried as given, then lower-cased, because
     * the case UIDAI hashes is not documented.
     */
    public static function matchesEmail(string $email, string $expectedHash, string $shareCode, int $lastDigit): bool
    {
        $email = trim($email);

        if ($email === '' || $expectedHash === '') {
            return false;
        }

        $expected = mb_strtolower($expectedHash);

        foreach (array_unique([$email, mb_strtolower($email)]) as $candidate) {
            if (hash_equals($expected, self::hash($candidate, $shareCode, $lastDigit))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce a mobile number to its 10 digits, or null if it is not an Indian mobile number.
     */
    public static function normalizeMobile(string $mobile): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $mobile);

        if (mb_strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = mb_substr($digits, 2);
        } elseif (mb_strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = mb_substr($digits, 1);
        }

        return preg_match('/^\d{10}$/', $digits) === 1 ? $digits : null;
    }
}
