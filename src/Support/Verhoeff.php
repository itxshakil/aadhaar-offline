<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Support;

use InvalidArgumentException;

/**
 * Verhoeff checksum, used as the last digit of every Aadhaar number.
 */
final class Verhoeff
{
    /** @var list<list<int>> */
    private const array MULTIPLICATION = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
        [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
        [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
        [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
        [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
        [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
        [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
        [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    ];

    /** @var list<list<int>> */
    private const array PERMUTATION = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
        [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
        [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
        [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
        [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
    ];

    /** @var list<int> */
    private const array INVERSE = [0, 4, 3, 2, 1, 5, 6, 7, 8, 9];

    /**
     * Check that a numeric string ends in a valid Verhoeff check digit.
     */
    public static function validate(string $number): bool
    {
        if ($number === '' || ! ctype_digit($number)) {
            return false;
        }

        return self::checksum($number) === 0;
    }

    /**
     * Compute the check digit to append to a numeric string.
     */
    public static function generate(string $number): int
    {
        if ($number === '' || ! ctype_digit($number)) {
            throw new InvalidArgumentException('Verhoeff input must be a non-empty numeric string.');
        }

        return self::INVERSE[self::checksum($number . '0')];
    }

    private static function checksum(string $number): int
    {
        $check = 0;
        $digits = array_reverse(mb_str_split($number));

        foreach ($digits as $position => $digit) {
            $check = self::MULTIPLICATION[$check][self::PERMUTATION[$position % 8][(int) $digit]];
        }

        return $check;
    }
}
