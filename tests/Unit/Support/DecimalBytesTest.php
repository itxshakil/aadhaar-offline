<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Support\DecimalBytes;

class DecimalBytesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function knownValues(): iterable
    {
        yield 'zero' => ['0', ''];
        yield 'one byte' => ['255', "\xFF"];
        yield 'two bytes' => ['256', "\x01\x00"];
        yield 'word boundary' => ['4294967296', "\x01\x00\x00\x00\x00"];
        yield 'limb boundary' => ['1000000000', "\x3B\x9A\xCA\x00"];
        yield 'leading zeros ignored' => ['000255', "\xFF"];
    }

    #[DataProvider('knownValues')]
    public function test_it_converts_known_values(string $decimal, string $bytes): void
    {
        $this->assertSame($bytes, DecimalBytes::toBytes($decimal));
        $expected = ltrim($decimal, '0');

        $this->assertSame($expected === '' ? '0' : $expected, DecimalBytes::fromBytes($bytes));
    }

    public function test_it_round_trips_large_random_values(): void
    {
        foreach ([1, 3, 4, 5, 31, 256, 3000] as $length) {
            $bytes = "\x01" . random_bytes($length);

            $this->assertSame($bytes, DecimalBytes::toBytes(DecimalBytes::fromBytes($bytes)));
        }
    }

    public function test_it_rejects_non_digits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DecimalBytes::toBytes('12a4');
    }
}
