<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Support\Verhoeff;

class VerhoeffTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'wrong check digit' => ['2364'],
            'transposition' => ['3263'],
            'empty' => [''],
            'letters' => ['23a3'],
        ];
    }

    public function test_it_generates_the_known_check_digit(): void
    {
        $this->assertSame(3, Verhoeff::generate('236'));
        $this->assertSame(1, Verhoeff::generate('12345'));
    }

    public function test_it_validates_numbers_with_a_correct_check_digit(): void
    {
        $this->assertTrue(Verhoeff::validate('2363'));
        $this->assertTrue(Verhoeff::validate('123451'));
    }

    #[DataProvider('invalidProvider')]
    public function test_it_rejects_invalid_input(string $number): void
    {
        $this->assertFalse(Verhoeff::validate($number));
    }

    public function test_generate_round_trips_with_validate(): void
    {
        foreach (['23456789012', '98765432101', '50000000000'] as $base) {
            $this->assertTrue(Verhoeff::validate($base . Verhoeff::generate($base)));
        }
    }

    public function test_generate_rejects_non_numeric_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Verhoeff::generate('12ab');
    }
}
