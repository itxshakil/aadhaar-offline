<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Security\HashMatcher;

class HashMatcherTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function digitProvider(): array
    {
        $cases = [];

        foreach (range(0, 9) as $digit) {
            $cases["last digit {$digit}"] = [$digit, max(1, $digit)];
        }

        return $cases;
    }

    #[DataProvider('digitProvider')]
    public function test_it_hashes_the_number_of_times_given_by_the_last_digit(int $lastDigit, int $expectedRounds): void
    {
        $expected = '9876543210' . '1234';

        for ($i = 0; $i < $expectedRounds; $i++) {
            $expected = hash('sha256', $expected);
        }

        $this->assertSame($expected, HashMatcher::hash('9876543210', '1234', $lastDigit));
    }

    public function test_digits_zero_and_one_hash_exactly_once(): void
    {
        $once = hash('sha256', '98765432101234');

        $this->assertSame($once, HashMatcher::hash('9876543210', '1234', 0));
        $this->assertSame($once, HashMatcher::hash('9876543210', '1234', 1));
    }

    public function test_it_matches_mobile_numbers_in_common_formats(): void
    {
        $hash = HashMatcher::hash('9876543210', '1234', 7);

        foreach (['9876543210', '+91 98765 43210', '+919876543210', '09876543210', '98765-43210'] as $mobile) {
            $this->assertTrue(HashMatcher::matchesMobile($mobile, $hash, '1234', 7), $mobile);
        }

        $this->assertTrue(HashMatcher::matchesMobile('9876543210', mb_strtoupper($hash), '1234', 7));
    }

    public function test_it_rejects_the_wrong_mobile_share_code_or_digit(): void
    {
        $hash = HashMatcher::hash('9876543210', '1234', 7);

        $this->assertFalse(HashMatcher::matchesMobile('9876543211', $hash, '1234', 7));
        $this->assertFalse(HashMatcher::matchesMobile('9876543210', $hash, '1235', 7));
        $this->assertFalse(HashMatcher::matchesMobile('9876543210', $hash, '1234', 6));
        $this->assertFalse(HashMatcher::matchesMobile('12345', $hash, '1234', 7));
        $this->assertFalse(HashMatcher::matchesMobile('9876543210', '', '1234', 7));
    }

    public function test_it_matches_email_as_given_or_lower_cased(): void
    {
        $hash = HashMatcher::hash('resident@example.com', '1234', 3);

        $this->assertTrue(HashMatcher::matchesEmail('resident@example.com', $hash, '1234', 3));
        $this->assertTrue(HashMatcher::matchesEmail('  Resident@Example.COM ', $hash, '1234', 3));
        $this->assertFalse(HashMatcher::matchesEmail('other@example.com', $hash, '1234', 3));
        $this->assertFalse(HashMatcher::matchesEmail('', $hash, '1234', 3));
    }

    public function test_it_normalizes_mobile_numbers(): void
    {
        $this->assertSame('9876543210', HashMatcher::normalizeMobile('+91-98765-43210'));
        $this->assertNull(HashMatcher::normalizeMobile('98765'));
    }
}
