<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Support;

use InvalidArgumentException;

/**
 * Converts between a big unsigned decimal number and its big-endian bytes.
 *
 * The Aadhaar Secure QR code stores its compressed payload as one very large
 * base-10 number. This avoids requiring ext-gmp or ext-bcmath for that.
 */
final class DecimalBytes
{
    /** Decimal digits per limb; 10^9 * 2^32 still fits in a 64-bit integer. */
    private const int LIMB_DIGITS = 9;

    private const int LIMB_BASE = 1_000_000_000;

    private const int WORD_BASE = 4_294_967_296;

    /**
     * Big-endian bytes of a decimal number, without leading zero bytes.
     *
     * @throws InvalidArgumentException When the input is not a string of digits
     */
    public static function toBytes(string $decimal): string
    {
        if (preg_match('/^\d+$/', $decimal) !== 1) {
            throw new InvalidArgumentException('The value must contain only decimal digits.');
        }

        $limbs = array_map(intval(...), mb_str_split(
            mb_str_pad($decimal, (int) ceil(mb_strlen($decimal) / self::LIMB_DIGITS) * self::LIMB_DIGITS, '0', STR_PAD_LEFT),
            self::LIMB_DIGITS,
        ));

        $words = [];

        // Long division by 2^32: each pass yields the next least significant 4 bytes.
        while ($limbs !== []) {
            $remainder = 0;
            $quotient = [];

            foreach ($limbs as $limb) {
                $current = $remainder * self::LIMB_BASE + $limb;
                $digit = intdiv($current, self::WORD_BASE);
                $remainder = $current % self::WORD_BASE;

                if ($digit !== 0 || $quotient !== []) {
                    $quotient[] = $digit;
                }
            }

            $words[] = $remainder;
            $limbs = $quotient;
        }

        return ltrim(pack('N*', ...array_reverse($words)), "\x00");
    }

    /**
     * Decimal representation of big-endian bytes.
     */
    public static function fromBytes(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '') {
            return '0';
        }

        $unpacked = unpack('N*', str_repeat("\x00", (4 - mb_strlen($bytes, '8bit') % 4) % 4) . $bytes);
        $words = is_array($unpacked) ? array_values(array_filter($unpacked, is_int(...))) : [];
        $limbs = [];

        // Long division by 10^9: each pass yields the next least significant 9 digits.
        while ($words !== []) {
            $remainder = 0;
            $quotient = [];

            foreach ($words as $word) {
                $current = $remainder * self::WORD_BASE + $word;
                $digit = intdiv($current, self::LIMB_BASE);
                $remainder = $current % self::LIMB_BASE;

                if ($digit !== 0 || $quotient !== []) {
                    $quotient[] = $digit;
                }
            }

            $limbs[] = $remainder;
            $words = $quotient;
        }

        $limbs = array_reverse($limbs);
        $first = (string) array_shift($limbs);

        return $first . implode('', array_map(static fn (int $limb): string => mb_str_pad((string) $limb, self::LIMB_DIGITS, '0', STR_PAD_LEFT), $limbs));
    }
}
