<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Real;

use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Data\Photo;
use Shakil\AadhaarOffline\Exceptions\InvalidShareCodeException;
use Shakil\AadhaarOffline\Exceptions\InvalidSignatureException;
use Shakil\AadhaarOffline\OfflineKycReader;

/**
 * Runs the package against a real Offline e-KYC file downloaded from UIDAI.
 *
 * Not part of the default suite. Run with `composer test:real` after setting:
 *   AADHAAR_OFFLINE_REAL_FILE          path to the ZIP (keep it outside the repo or gitignored)
 *   AADHAAR_OFFLINE_REAL_SHARE_CODE    its share code
 *   AADHAAR_OFFLINE_REAL_CERTIFICATES  comma-separated UIDAI certificate path(s)
 *   AADHAAR_OFFLINE_REAL_MOBILE        optional: the linked mobile number, to test hash matching
 *
 * Assertions only check shapes, never values, so failures never print personal data.
 */
class RealPayloadTest extends TestCase
{
    private OfflineKycReader $reader;

    protected function setUp(): void
    {
        foreach (['AADHAAR_OFFLINE_REAL_FILE', 'AADHAAR_OFFLINE_REAL_SHARE_CODE', 'AADHAAR_OFFLINE_REAL_CERTIFICATES'] as $variable) {
            if ($this->env($variable) === null) {
                $this->markTestSkipped("Set {$variable} to run the real-file tests.");
            }
        }

        parent::setUp();

        // A downloaded file goes stale within days; these tests check the format, not freshness.
        $this->reader = OfflineKycReader::create(
            array_map(trim(...), explode(',', (string) $this->env('AADHAAR_OFFLINE_REAL_CERTIFICATES'))),
            maxAgeDays: null,
        );
    }

    public function test_a_real_file_opens_parses_and_verifies(): void
    {
        $kyc = $this->reader->verifyZip($this->file(), $this->shareCode());

        $this->assertTrue($kyc->isVerified());
        $this->assertMatchesRegularExpression('/^\d{4}$/', $kyc->lastFourDigits());
        $this->assertNotNull($kyc->name());
        $this->assertNotNull($kyc->birthYear());
        $this->assertNotNull($kyc->gender());
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $kyc->address()->pincode);
        $this->assertInstanceOf(Photo::class, $kyc->photo());
        $this->assertContains($kyc->photo()->mimeType(), ['image/jpeg', 'image/jp2', 'image/png']);
    }

    public function test_a_wrong_share_code_is_rejected(): void
    {
        $wrong = mb_str_pad((string) (((int) $this->shareCode() + 1) % 10_000), 4, '0', STR_PAD_LEFT);

        $this->expectException(InvalidShareCodeException::class);

        $this->reader->fromZip($this->file(), $wrong);
    }

    public function test_an_edited_real_file_fails_the_signature_check(): void
    {
        $xml = (string) $this->reader->fromZip($this->file(), $this->shareCode())->rawXml();
        $edited = (string) preg_replace('/(<Poi\b[^>]*\bname=")(.)/u', '${1}X', $xml, 1);

        $this->assertNotSame($xml, $edited);
        $this->expectException(InvalidSignatureException::class);

        $this->reader->verifyXml($edited);
    }

    public function test_the_linked_mobile_matches(): void
    {
        $mobile = $this->env('AADHAAR_OFFLINE_REAL_MOBILE');

        if ($mobile === null) {
            $this->markTestSkipped('Set AADHAAR_OFFLINE_REAL_MOBILE to test mobile hash matching.');
        }

        $kyc = $this->reader->verifyZip($this->file(), $this->shareCode());

        $digits = (string) preg_replace('/\D+/', '', $mobile);
        $other = mb_substr($digits, 0, -1) . (((int) mb_substr($digits, -1) + 1) % 10);

        $this->assertTrue($kyc->matchesMobile($mobile));
        $this->assertFalse($kyc->matchesMobile($other));
    }

    private function file(): string
    {
        return (string) $this->env('AADHAAR_OFFLINE_REAL_FILE');
    }

    private function shareCode(): string
    {
        return (string) $this->env('AADHAAR_OFFLINE_REAL_SHARE_CODE');
    }

    private function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
