<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Data;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Data\OfflineKyc;
use Shakil\AadhaarOffline\Enums\Gender;
use Shakil\AadhaarOffline\Exceptions\ConfigurationException;
use Shakil\AadhaarOffline\Exceptions\MalformedPayloadException;
use Shakil\AadhaarOffline\Testing\OfflineKycFactory;

class OfflineKycTest extends TestCase
{
    public function test_it_exposes_last_four_and_masked_aadhaar_from_the_reference_id(): void
    {
        $kyc = OfflineKycFactory::make(['last_four' => '9012']);

        $this->assertSame('9012', $kyc->lastFourDigits());
        $this->assertSame('XXXX XXXX 9012', $kyc->maskedAadhaar());
        $this->assertSame('XXXXXXXX9012', $kyc->maskedAadhaar(grouped: false));
    }

    public function test_it_parses_the_generation_time_from_the_reference_id(): void
    {
        $kyc = new OfflineKyc('1234' . '20261008153045123');

        $this->assertSame('2026-10-08 15:30:45.123 +05:30', $kyc->generatedAt()->format('Y-m-d H:i:s.v P'));
    }

    public function test_it_rejects_a_malformed_reference_id(): void
    {
        $this->expectException(MalformedPayloadException::class);

        new OfflineKyc('not-a-reference');
    }

    public function test_freshness_boundary(): void
    {
        $generated = new DateTimeImmutable('2026-10-01 10:00:00', new DateTimeZone('Asia/Kolkata'));
        $kyc = OfflineKycFactory::make(['generated_at' => $generated]);

        $this->assertTrue($kyc->isFresh(3, $generated->modify('+3 days')));
        $this->assertFalse($kyc->isFresh(3, $generated->modify('+3 days +1 second')));
        $this->assertSame(3, $kyc->ageInDays($generated->modify('+3 days +5 hours')));
    }

    public function test_it_parses_full_and_year_only_dates_of_birth(): void
    {
        $full = OfflineKycFactory::make(['dob' => '15-08-1990']);
        $yearOnly = OfflineKycFactory::make(['dob' => '1990']);

        $this->assertSame('1990-08-15', $full->dob()?->format('Y-m-d'));
        $this->assertSame(1990, $full->birthYear());
        $this->assertSame(36, $full->age(new DateTimeImmutable('2026-10-08')));

        $this->assertNull($yearOnly->dob());
        $this->assertSame(1990, $yearOnly->birthYear());
        $this->assertNull($yearOnly->age());
    }

    public function test_it_rejects_impossible_dates(): void
    {
        $this->assertNull(OfflineKycFactory::make(['dob' => '31-02-1990'])->dob());
    }

    public function test_it_matches_mobile_and_email(): void
    {
        $kyc = OfflineKycFactory::make(['mobile' => '9123456789', 'email' => 'a@example.com', 'last_four' => '4447']);

        $this->assertTrue($kyc->hasMobile());
        $this->assertTrue($kyc->matchesMobile('+91 91234 56789'));
        $this->assertFalse($kyc->matchesMobile('9123456780'));
        $this->assertTrue($kyc->matchesEmail('A@example.com'));
    }

    public function test_has_mobile_and_email_are_false_without_hashes(): void
    {
        $kyc = OfflineKycFactory::make(['mobile' => null]);

        $this->assertFalse($kyc->hasMobile());
        $this->assertFalse($kyc->hasEmail());
        $this->assertFalse($kyc->matchesMobile('9876543210'));
    }

    public function test_matching_requires_the_share_code(): void
    {
        $kyc = new OfflineKyc('123420261008153045123', mobileHash: 'abc');

        $this->expectException(ConfigurationException::class);

        $kyc->matchesMobile('9876543210');
    }

    public function test_verify_requires_a_verifier(): void
    {
        $this->expectException(ConfigurationException::class);

        OfflineKycFactory::make()->verify();
    }

    public function test_to_array_never_contains_sensitive_values(): void
    {
        $kyc = OfflineKycFactory::make(['share_code' => '8642', 'mobile' => '9876543210']);
        $outputs = [
            json_encode($kyc->toArray(), JSON_THROW_ON_ERROR),
            json_encode($kyc, JSON_THROW_ON_ERROR),
            print_r($kyc, true),
            var_export($kyc->toArray(withPhoto: true), true),
        ];

        foreach ($outputs as $output) {
            $this->assertStringNotContainsString('8642', $output);
            $this->assertStringNotContainsString('9876543210', $output);
            $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $output, 'A mobile/email hash leaked.');
        }

        $this->assertArrayNotHasKey('photo', $kyc->toArray());
        $photo = $kyc->toArray(withPhoto: true)['photo'];
        $this->assertIsString($photo);
        $this->assertStringStartsWith('data:image/jpeg', $photo);
    }

    public function test_it_exposes_demographics(): void
    {
        $kyc = OfflineKycFactory::make(['name' => 'Asha Verma', 'gender' => 'F']);

        $this->assertSame('Asha Verma', $kyc->name());
        $this->assertSame(Gender::Female, $kyc->gender());
        $this->assertSame('Female', Gender::Female->label());
        $this->assertSame('110001', $kyc->address()->pincode);
        $this->assertFalse($kyc->isVerified());
    }
}
