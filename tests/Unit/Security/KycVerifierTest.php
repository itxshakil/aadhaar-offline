<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Security;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Data\OfflineKyc;
use Shakil\AadhaarOffline\Exceptions\AadhaarOfflineException;
use Shakil\AadhaarOffline\Exceptions\InvalidSignatureException;
use Shakil\AadhaarOffline\Exceptions\StaleKycException;
use Shakil\AadhaarOffline\Parsers\XmlParser;
use Shakil\AadhaarOffline\Security\CertificateStore;
use Shakil\AadhaarOffline\Security\KycVerifier;
use Shakil\AadhaarOffline\Security\SignatureVerifier;
use Shakil\AadhaarOffline\Testing\Fixtures;

class KycVerifierTest extends TestCase
{
    public function test_it_verifies_signature_and_freshness_and_calls_on_verified(): void
    {
        $verified = [];
        $verifier = $this->verifier(onVerified: function (OfflineKyc $kyc) use (&$verified): void {
            $verified[] = $kyc->referenceId();
        });

        $kyc = (new XmlParser())->parse(Fixtures::signedXml(), verifier: $verifier)->verify();

        $this->assertTrue($kyc->isVerified());
        $this->assertSame([$kyc->referenceId()], $verified);
    }

    public function test_it_rejects_stale_payloads(): void
    {
        $generated = new DateTimeImmutable('-4 days', new DateTimeZone('Asia/Kolkata'));
        $kyc = (new XmlParser())->parse(Fixtures::signedXml(['generated_at' => $generated]), verifier: $this->verifier());

        $this->expectException(StaleKycException::class);

        $kyc->verify();
    }

    public function test_freshness_can_be_disabled(): void
    {
        $generated = new DateTimeImmutable('-400 days', new DateTimeZone('Asia/Kolkata'));
        $kyc = (new XmlParser())->parse(Fixtures::signedXml(['generated_at' => $generated]), verifier: $this->verifier(maxAgeDays: null));

        $this->assertTrue($kyc->verify()->isVerified());
    }

    public function test_it_uses_the_injected_clock(): void
    {
        $generated = new DateTimeImmutable('2026-01-01 09:00:00', new DateTimeZone('Asia/Kolkata'));
        $verifier = $this->verifier(clock: static fn (): DateTimeImmutable => $generated->modify('+1 day'));

        $kyc = (new XmlParser())->parse(Fixtures::signedXml(['generated_at' => $generated]), verifier: $verifier);

        $this->assertTrue($kyc->verify()->isVerified());
    }

    public function test_it_calls_on_rejected_and_rethrows(): void
    {
        $rejections = [];
        $verifier = $this->verifier(onRejected: function (OfflineKyc $kyc, AadhaarOfflineException $exception) use (&$rejections): void {
            $rejections[] = $exception::class;
        });

        $kyc = (new XmlParser())->parse(str_replace('Asha', 'Usha', Fixtures::signedXml()), verifier: $verifier);

        try {
            $kyc->verify();
            $this->fail('Expected verification to fail.');
        } catch (InvalidSignatureException) {
            $this->assertSame([InvalidSignatureException::class], $rejections);
            $this->assertFalse($kyc->isVerified());
        }
    }

    public function test_payloads_without_xml_fail_signature_verification(): void
    {
        $kyc = new OfflineKyc('123420261008153045123', verifier: $this->verifier());

        $this->expectException(InvalidSignatureException::class);

        $kyc->verify();
    }

    public function test_signature_check_can_be_disabled(): void
    {
        $kyc = (new XmlParser())->parse(Fixtures::xml(), verifier: $this->verifier(verifySignature: false));

        $this->assertTrue($kyc->verify()->isVerified());
    }

    private function verifier(
        bool $verifySignature = true,
        ?int $maxAgeDays = 3,
        ?Closure $clock = null,
        ?Closure $onVerified = null,
        ?Closure $onRejected = null,
    ): KycVerifier {
        return new KycVerifier(
            new SignatureVerifier(CertificateStore::fromPem([Fixtures::identity()['cert']])),
            $verifySignature,
            $maxAgeDays,
            $clock,
            $onVerified,
            $onRejected,
        );
    }
}
