<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Support\AadhaarNumber;
use Shakil\AadhaarOffline\Support\Verhoeff;

class AadhaarNumberTest extends TestCase
{
    public function test_it_accepts_a_valid_number_with_or_without_spaces(): void
    {
        $number = $this->validNumber();

        $this->assertTrue(AadhaarNumber::isValid($number));
        $this->assertTrue(AadhaarNumber::isValid(implode(' ', mb_str_split($number, 4))));
        $this->assertTrue(AadhaarNumber::isValid(implode('-', mb_str_split($number, 4))));
    }

    public function test_it_rejects_numbers_starting_with_zero_or_one(): void
    {
        foreach (['0', '1'] as $prefix) {
            $base = $prefix . '3456789012';
            $this->assertFalse(AadhaarNumber::isValid($base . Verhoeff::generate($base)));
        }
    }

    public function test_it_rejects_a_bad_check_digit_or_length(): void
    {
        $number = $this->validNumber();
        $wrong = mb_substr($number, 0, 11) . (((int) mb_substr($number, -1) + 1) % 10);

        $this->assertFalse(AadhaarNumber::isValid($wrong));
        $this->assertFalse(AadhaarNumber::isValid(mb_substr($number, 0, 11)));
        $this->assertFalse(AadhaarNumber::isValid($number . '1'));
    }

    public function test_it_masks_full_masked_and_last_four_values(): void
    {
        $this->assertSame('XXXX XXXX 9012', AadhaarNumber::mask('2345 6789 9012'));
        $this->assertSame('XXXX XXXX 9012', AadhaarNumber::mask('XXXXXXXX9012'));
        $this->assertSame('XXXX XXXX 9012', AadhaarNumber::mask('9012'));
        $this->assertSame('********9012', AadhaarNumber::mask('234567899012', '*', false));
    }

    public function test_mask_returns_null_when_no_last_four_can_be_found(): void
    {
        $this->assertNull(AadhaarNumber::mask('12345'));
        $this->assertNull(AadhaarNumber::mask('abcd'));
        $this->assertNull(AadhaarNumber::mask(''));
    }

    public function test_it_detects_unmasked_numbers(): void
    {
        $this->assertTrue(AadhaarNumber::looksUnmasked('2345 6789 9012'));
        $this->assertFalse(AadhaarNumber::looksUnmasked('XXXX XXXX 9012'));
    }

    private function validNumber(): string
    {
        $base = '23456789012';

        return $base . Verhoeff::generate($base);
    }
}
