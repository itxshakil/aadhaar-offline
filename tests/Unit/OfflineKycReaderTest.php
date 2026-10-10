<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Data\OfflineKyc;
use Shakil\AadhaarOffline\Exceptions\AadhaarOfflineException;
use Shakil\AadhaarOffline\Exceptions\ConfigurationException;
use Shakil\AadhaarOffline\Exceptions\InvalidShareCodeException;
use Shakil\AadhaarOffline\Exceptions\InvalidSignatureException;
use Shakil\AadhaarOffline\Exceptions\MalformedPayloadException;
use Shakil\AadhaarOffline\Exceptions\StaleKycException;
use Shakil\AadhaarOffline\OfflineKycReader;
use Shakil\AadhaarOffline\Security\CertificateStore;
use Shakil\AadhaarOffline\Testing\Fixtures;
use SplFileInfo;

class OfflineKycReaderTest extends TestCase
{
    public function test_it_reads_and_verifies_a_zip(): void
    {
        $kyc = $this->reader()->fromZip(Fixtures::zip(Fixtures::signedXml()), Fixtures::SHARE_CODE)->verify();

        $this->assertTrue($kyc->isVerified());
        $this->assertSame('Asha Verma', $kyc->name());
        $this->assertTrue($kyc->matchesMobile(Fixtures::MOBILE));
    }

    public function test_it_accepts_a_file_object(): void
    {
        $kyc = $this->reader()->verifyZip(new SplFileInfo(Fixtures::zip(Fixtures::signedXml())), Fixtures::SHARE_CODE);

        $this->assertTrue($kyc->isVerified());
    }

    public function test_verify_shortcuts(): void
    {
        $reader = $this->reader();

        $this->assertTrue($reader->verifyXml(Fixtures::signedXml(), Fixtures::SHARE_CODE)->isVerified());
        $this->assertTrue($reader->verifySecureQr(Fixtures::secureQr())->isVerified());
    }

    public function test_it_reads_an_xml_file(): void
    {
        $kyc = $this->reader()->fromXmlFile(Fixtures::file(Fixtures::signedXml(), 'xml'), Fixtures::SHARE_CODE);

        $this->assertTrue($kyc->verify()->matchesEmail(Fixtures::EMAIL));
    }

    public function test_a_missing_xml_file_is_reported(): void
    {
        $this->expectException(MalformedPayloadException::class);

        $this->reader()->fromXmlFile('/nonexistent/offline.xml');
    }

    public function test_from_xml_validates_the_share_code_format(): void
    {
        $this->expectException(InvalidShareCodeException::class);

        $this->reader()->fromXml(Fixtures::signedXml(), 'abcd');
    }

    public function test_it_rejects_a_document_signed_by_an_untrusted_key(): void
    {
        $this->expectException(InvalidSignatureException::class);

        $this->reader()->verifyXml(Fixtures::signedXml(identity: 'attacker'));
    }

    public function test_it_applies_the_xml_age_limit(): void
    {
        $this->expectException(StaleKycException::class);

        OfflineKycReader::create([Fixtures::certificateFile()], maxAgeDays: 1)
            ->verifyXml(Fixtures::signedXml(['generated_at' => new DateTimeImmutable('-2 days', new DateTimeZone('Asia/Kolkata'))]));
    }

    public function test_qr_codes_have_their_own_age_limit_off_by_default(): void
    {
        $oldCard = Fixtures::secureQr(['generated_at' => new DateTimeImmutable('-3 years')]);

        $this->assertTrue($this->reader()->verifySecureQr($oldCard)->isVerified());

        $this->expectException(StaleKycException::class);

        OfflineKycReader::create([Fixtures::certificateFile()], qrMaxAgeDays: 30)->verifySecureQr($oldCard);
    }

    public function test_signature_checks_can_be_disabled_for_local_development(): void
    {
        $reader = OfflineKycReader::create([], verifySignature: false);

        $this->assertTrue($reader->verifyXml(Fixtures::xml())->isVerified());
    }

    public function test_it_needs_a_certificate_to_verify_signatures(): void
    {
        $this->expectException(ConfigurationException::class);

        OfflineKycReader::create([])->verifyXml(Fixtures::signedXml());
    }

    public function test_it_accepts_a_certificate_store(): void
    {
        $reader = OfflineKycReader::create(CertificateStore::fromPem([Fixtures::identity()['cert']]));

        $this->assertTrue($reader->verifyXml(Fixtures::signedXml())->isVerified());
        $this->assertCount(1, $reader->certificates()->all());
    }

    public function test_it_reports_verification_results_through_callbacks(): void
    {
        $verified = [];
        $rejected = [];

        $reader = OfflineKycReader::create(
            [Fixtures::certificateFile()],
            onVerified: static function (OfflineKyc $kyc) use (&$verified): void {
                $verified[] = $kyc->maskedAadhaar();
            },
            onRejected: static function (OfflineKyc $kyc, AadhaarOfflineException $exception) use (&$rejected): void {
                $rejected[] = $exception::class;
            },
        );

        $reader->verifyXml(Fixtures::signedXml());

        try {
            $reader->verifyXml(Fixtures::signedXml(identity: 'attacker'));
        } catch (InvalidSignatureException) {
            // Expected; the callback has recorded it.
        }

        $this->assertSame(['XXXX XXXX 5678'], $verified);
        $this->assertSame([InvalidSignatureException::class], $rejected);
    }

    public function test_it_enforces_the_configured_size_limits(): void
    {
        $this->expectException(MalformedPayloadException::class);

        OfflineKycReader::create([Fixtures::certificateFile()], maxZipBytes: 100)
            ->fromZip(Fixtures::zip(Fixtures::signedXml()), Fixtures::SHARE_CODE);
    }

    private function reader(): OfflineKycReader
    {
        return OfflineKycReader::create([Fixtures::certificateFile()]);
    }
}
