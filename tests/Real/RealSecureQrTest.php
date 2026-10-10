<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Real;

use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Data\Photo;
use Shakil\AadhaarOffline\Enums\PayloadSource;
use Shakil\AadhaarOffline\Exceptions\InvalidSignatureException;
use Shakil\AadhaarOffline\OfflineKycReader;
use Shakil\AadhaarOffline\Support\DecimalBytes;

/**
 * Runs the package against a real Aadhaar Secure QR code.
 *
 * Not part of the default suite. Run with `composer test:real` after setting:
 *   AADHAAR_OFFLINE_REAL_QR_FILE       text file holding the scanned QR digits (keep it gitignored)
 *   AADHAAR_OFFLINE_REAL_CERTIFICATES  comma-separated UIDAI certificate path(s); include older
 *                                      ones if the card was printed before the current certificate
 *
 * Assertions only check shapes, never values, so failures never print personal data.
 */
class RealSecureQrTest extends TestCase
{
    private OfflineKycReader $reader;

    protected function setUp(): void
    {
        foreach (['AADHAAR_OFFLINE_REAL_QR_FILE', 'AADHAAR_OFFLINE_REAL_CERTIFICATES'] as $variable) {
            if ($this->env($variable) === null) {
                $this->markTestSkipped("Set {$variable} to run the real Secure QR tests.");
            }
        }

        parent::setUp();

        // A downloaded file goes stale within days; these tests check the format, not freshness.
        $this->reader = OfflineKycReader::create(
            array_map(trim(...), explode(',', (string) $this->env('AADHAAR_OFFLINE_REAL_CERTIFICATES'))),
            maxAgeDays: null,
        );
    }

    public function test_a_real_qr_code_parses_and_verifies(): void
    {
        $kyc = $this->reader->verifySecureQr($this->qr());

        $this->assertTrue($kyc->isVerified());
        $this->assertSame(PayloadSource::SecureQr, $kyc->source());
        $this->assertMatchesRegularExpression('/^\d{4}$/', $kyc->lastFourDigits());
        $this->assertNotNull($kyc->name());
        $this->assertNotNull($kyc->birthYear());
        $this->assertNotNull($kyc->gender());
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $kyc->address()->pincode);
        $this->assertInstanceOf(Photo::class, $kyc->photo());
        $this->assertContains($kyc->photo()->mimeType(), ['image/jpeg', 'image/jp2', 'image/png']);
    }

    public function test_a_changed_byte_in_a_real_qr_code_fails_the_signature_check(): void
    {
        $compressed = DecimalBytes::toBytes($this->qr());
        $bytes = (string) gzdecode($compressed);
        // Flip one bit in the middle of the signed data, then re-encode it as a QR number.
        $middle = intdiv(mb_strlen($bytes, '8bit') - 256, 2);
        $bytes[$middle] = chr(ord($bytes[$middle]) ^ 0x01);

        $this->expectException(InvalidSignatureException::class);

        $this->reader->verifySecureQr(DecimalBytes::fromBytes((string) gzencode($bytes)));
    }

    private function qr(): string
    {
        return trim((string) file_get_contents((string) $this->env('AADHAAR_OFFLINE_REAL_QR_FILE')));
    }

    private function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
