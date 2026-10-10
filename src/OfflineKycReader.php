<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline;

use Closure;
use SensitiveParameter;
use Shakil\AadhaarOffline\Contracts\Verifier;
use Shakil\AadhaarOffline\Data\OfflineKyc;
use Shakil\AadhaarOffline\Exceptions\AadhaarOfflineException;
use Shakil\AadhaarOffline\Exceptions\MalformedPayloadException;
use Shakil\AadhaarOffline\Parsers\SecureQrParser;
use Shakil\AadhaarOffline\Parsers\XmlParser;
use Shakil\AadhaarOffline\Parsers\ZipReader;
use Shakil\AadhaarOffline\Security\CertificateStore;
use Shakil\AadhaarOffline\Security\KycVerifier;
use Shakil\AadhaarOffline\Security\QrSignatureVerifier;
use Shakil\AadhaarOffline\Security\SignatureVerifier;
use SplFileInfo;

/**
 * Entry point for reading and verifying Aadhaar Offline e-KYC payloads and Secure QR codes.
 *
 * Every reader returns an unverified OfflineKyc. Call verify() on it (or use the
 * verify* shortcuts) before trusting any of its data.
 *
 *     $reader = OfflineKycReader::create(certificates: ['/path/to/uidai_offline_publickey_2026.cer']);
 *     $kyc = $reader->verifyZip('/path/to/offlineaadhaar.zip', '1234');
 */
class OfflineKycReader
{
    public function __construct(
        protected readonly ZipReader $zipReader,
        protected readonly XmlParser $parser,
        protected readonly Verifier $verifier,
        protected readonly CertificateStore $certificates,
        protected readonly SecureQrParser $qrParser = new SecureQrParser(),
    ) {}

    /**
     * Build a reader with the standard verification rules.
     *
     * @param CertificateStore|list<string>                             $certificates    Trusted UIDAI certificate files (PEM or DER), or a store
     * @param bool                                                      $verifySignature Only disable in local development
     * @param int|null                                                  $maxAgeDays      Reject Offline e-KYC XML older than this; null disables
     * @param int|null                                                  $qrMaxAgeDays    Reject Secure QR codes older than this; null disables
     * @param list<string>                                              $algorithms      Accepted XML SignatureMethod algorithms
     * @param (Closure(OfflineKyc): void)|null                          $onVerified      Called after a successful verification
     * @param (Closure(OfflineKyc, AadhaarOfflineException): void)|null $onRejected      Called before a failure is thrown
     */
    public static function create(
        CertificateStore|array $certificates,
        bool $verifySignature = true,
        ?int $maxAgeDays = 3,
        ?int $qrMaxAgeDays = null,
        array $algorithms = SignatureVerifier::DEFAULT_ALGORITHMS,
        int $maxZipBytes = 2 * 1024 * 1024,
        int $maxXmlBytes = 2 * 1024 * 1024,
        ?Closure $onVerified = null,
        ?Closure $onRejected = null,
    ): self {
        $store = $certificates instanceof CertificateStore ? $certificates : CertificateStore::fromPaths($certificates);

        $verifier = new KycVerifier(
            signatures: new SignatureVerifier($store, $algorithms, $maxXmlBytes),
            verifySignature: $verifySignature,
            maxAgeDays: $maxAgeDays,
            onVerified: $onVerified,
            onRejected: $onRejected,
            qrSignatures: new QrSignatureVerifier($store),
            qrMaxAgeDays: $qrMaxAgeDays,
        );

        return new self(new ZipReader($maxZipBytes, $maxXmlBytes), new XmlParser($maxXmlBytes), $verifier, $store);
    }

    /**
     * Read the share-code-protected ZIP downloaded from UIDAI.
     *
     * @param SplFileInfo|string $file An uploaded file, SplFileInfo or a path
     *
     * @throws AadhaarOfflineException
     */
    public function fromZip(SplFileInfo|string $file, #[SensitiveParameter] string $shareCode): OfflineKyc
    {
        $xml = $this->zipReader->read($this->path($file), $shareCode);

        return $this->parser->parse($xml, $shareCode, $this->verifier);
    }

    /**
     * Read a signed Offline e-KYC XML string, e.g. one returned by a KYC vendor API.
     *
     * The share code is only needed for mobile/email matching.
     *
     * @throws AadhaarOfflineException
     */
    public function fromXml(string $xml, #[SensitiveParameter] ?string $shareCode = null): OfflineKyc
    {
        if ($shareCode !== null) {
            ZipReader::assertShareCode($shareCode);
        }

        return $this->parser->parse($xml, $shareCode, $this->verifier);
    }

    /**
     * Read a signed Offline e-KYC XML file.
     *
     * @throws AadhaarOfflineException
     */
    public function fromXmlFile(SplFileInfo|string $file, #[SensitiveParameter] ?string $shareCode = null): OfflineKyc
    {
        $path = $this->path($file);

        if (! is_file($path) || ! is_readable($path)) {
            throw new MalformedPayloadException('The Offline e-KYC XML file could not be read.');
        }

        return $this->fromXml((string) file_get_contents($path), $shareCode);
    }

    /**
     * Read the Secure QR code printed on an Aadhaar letter, PVC card or e-Aadhaar PDF.
     *
     * Pass the scanned QR text: a long string of digits.
     *
     * @throws AadhaarOfflineException
     */
    public function fromSecureQr(string $qr): OfflineKyc
    {
        return $this->qrParser->parse($qr, $this->verifier);
    }

    /**
     * Read and verify a ZIP in one step.
     *
     * @throws AadhaarOfflineException
     */
    public function verifyZip(SplFileInfo|string $file, #[SensitiveParameter] string $shareCode): OfflineKyc
    {
        return $this->fromZip($file, $shareCode)->verify();
    }

    /**
     * Read and verify an XML string in one step.
     *
     * @throws AadhaarOfflineException
     */
    public function verifyXml(string $xml, #[SensitiveParameter] ?string $shareCode = null): OfflineKyc
    {
        return $this->fromXml($xml, $shareCode)->verify();
    }

    /**
     * Read and verify a Secure QR code in one step.
     *
     * @throws AadhaarOfflineException
     */
    public function verifySecureQr(string $qr): OfflineKyc
    {
        return $this->fromSecureQr($qr)->verify();
    }

    /**
     * The trusted UIDAI certificates.
     */
    public function certificates(): CertificateStore
    {
        return $this->certificates;
    }

    protected function path(SplFileInfo|string $file): string
    {
        if ($file instanceof SplFileInfo) {
            $realPath = $file->getRealPath();

            return $realPath !== false ? $realPath : $file->getPathname();
        }

        return $file;
    }
}
