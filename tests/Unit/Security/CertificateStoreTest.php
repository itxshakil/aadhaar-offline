<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Security;

use DateTimeImmutable;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Exceptions\ConfigurationException;
use Shakil\AadhaarOffline\Security\CertificateStore;
use Shakil\AadhaarOffline\Testing\Fixtures;

class CertificateStoreTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function whitespaceBytes(): iterable
    {
        foreach ([' ', "\t", "\n", "\r", "\0", "\x0B"] as $byte) {
            yield 'ends in 0x' . bin2hex($byte) => [$byte];
        }
    }

    public function test_it_loads_pem_and_der_files(): void
    {
        $store = CertificateStore::fromPaths([Fixtures::certificateFile(), Fixtures::certificateFile(der: true)]);

        $this->assertTrue($store->isConfigured());
        $this->assertCount(2, $store->all());
        $this->assertSame($store->all()[0], $store->all()[1]);
    }

    public function test_it_describes_certificates(): void
    {
        $details = CertificateStore::fromPem([Fixtures::identity()['cert']])->describe();

        $this->assertSame('uidai-test', $details[0]['subject']);
        $this->assertMatchesRegularExpression('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $details[0]['fingerprint']);
        $this->assertFalse($details[0]['expired']);
        $this->assertTrue(CertificateStore::fromPem([Fixtures::identity()['cert']])->describe(new DateTimeImmutable('+2 years'))[0]['expired']);
    }

    public function test_from_paths_ignores_blank_entries(): void
    {
        $this->assertFalse(CertificateStore::fromPaths(['', '  '])->isConfigured());
    }

    public function test_it_reports_missing_configuration(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('AADHAAR_OFFLINE_CERTIFICATES');

        (new CertificateStore())->all();
    }

    public function test_it_reports_a_missing_file(): void
    {
        $this->expectException(ConfigurationException::class);

        CertificateStore::fromPaths(['/nonexistent/uidai.cer'])->all();
    }

    public function test_it_reports_an_unreadable_certificate(): void
    {
        $this->expectException(ConfigurationException::class);

        CertificateStore::fromPaths([Fixtures::file('not a certificate', 'cer')])->all();
    }

    /**
     * DER is binary: its last signature byte can be anything, including what trim() strips.
     */
    #[DataProvider('whitespaceBytes')]
    public function test_it_loads_a_der_certificate_whose_last_byte_looks_like_whitespace(string $byte): void
    {
        $der = $this->derEndingWith($byte);

        $this->assertSame($byte, mb_substr($der, -1, null, '8bit'));
        $this->assertStringContainsString('BEGIN CERTIFICATE', CertificateStore::toPem($der));
    }

    /**
     * Re-sign the same CSR with increasing serial numbers until the DER ends with the byte.
     */
    private function derEndingWith(string $byte): string
    {
        $config = ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($config);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $csr = openssl_csr_new(['commonName' => 'der-test'], $key, $config);
        $this->assertIsNotBool($csr);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key); // passed by reference above

        for ($serial = 1; $serial <= 20_000; $serial++) {
            $certificate = openssl_csr_sign($csr, null, $key, 1, $config, $serial);

            if ($certificate === false || ! openssl_x509_export($certificate, $pem) || ! is_string($pem)) {
                $this->fail('Could not sign the test certificate.');
            }

            $der = (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);

            if (str_ends_with($der, $byte)) {
                return $der;
            }
        }

        $this->fail('Could not produce a DER certificate ending in 0x' . bin2hex($byte) . '.');
    }
}
