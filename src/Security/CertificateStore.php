<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Security;

use DateTimeImmutable;
use Shakil\AadhaarOffline\Exceptions\ConfigurationException;

/**
 * The UIDAI public certificates trusted to sign Offline e-KYC payloads.
 *
 * Certificates are loaded from PEM or DER (.cer) files, or given as PEM strings.
 */
final class CertificateStore
{
    /** @var list<array{source: string, pem: string}>|null */
    private ?array $loaded = null;

    /**
     * @param list<string> $paths PEM or DER certificate files
     * @param list<string> $pems  PEM-encoded certificates
     */
    public function __construct(
        private readonly array $paths = [],
        private readonly array $pems = [],
    ) {}

    /**
     * @param list<string> $paths
     */
    public static function fromPaths(array $paths): self
    {
        return new self(paths: array_values(array_filter($paths, static fn (string $path): bool => trim($path) !== '')));
    }

    /**
     * @param list<string> $pems
     */
    public static function fromPem(array $pems): self
    {
        return new self(pems: $pems);
    }

    /**
     * Convert DER bytes or a PEM string into a normalized PEM certificate.
     *
     * @throws ConfigurationException
     */
    public static function toPem(string $contents, string $source = 'certificate'): string
    {
        // Only PEM is text. DER is binary and may legitimately end in bytes trim() would strip.
        if (str_contains($contents, '-----BEGIN CERTIFICATE-----')) {
            $contents = trim($contents);
        } else {
            $contents = "-----BEGIN CERTIFICATE-----\n"
                . chunk_split(base64_encode($contents), 64, "\n")
                . '-----END CERTIFICATE-----';
        }

        // Suppress the PHP warning; an unreadable certificate is reported as a ConfigurationException below.
        $certificate = @openssl_x509_read($contents);

        if ($certificate === false || ! openssl_x509_export($certificate, $pem) || ! is_string($pem)) {
            throw new ConfigurationException("The UIDAI certificate [{$source}] could not be read.");
        }

        return $pem;
    }

    /**
     * Details of a single PEM certificate, for diagnostics.
     *
     * @return array{source: string, subject: string, fingerprint: string, valid_from: ?DateTimeImmutable, valid_to: ?DateTimeImmutable, expired: bool}
     */
    public static function describePem(string $pem, string $source = 'certificate', ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $parsed = openssl_x509_parse($pem);
        $parsed = is_array($parsed) ? $parsed : [];
        $validFrom = self::timestamp($parsed['validFrom_time_t'] ?? null);
        $validTo = self::timestamp($parsed['validTo_time_t'] ?? null);
        $subjectFields = $parsed['subject'] ?? null;
        // CN is an array when a certificate has several; there is no single name to show then.
        $subject = is_array($subjectFields) && is_string($subjectFields['CN'] ?? null) ? $subjectFields['CN'] : 'unknown';
        $fingerprint = (string) openssl_x509_fingerprint($pem, 'sha256');

        return [
            'source' => $source,
            'subject' => $subject,
            'fingerprint' => mb_strtoupper(implode(':', mb_str_split($fingerprint, 2))),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'expired' => $validTo instanceof DateTimeImmutable && $validTo < $now,
        ];
    }

    /**
     * Whether any certificate source has been configured.
     */
    public function isConfigured(): bool
    {
        return $this->paths !== [] || $this->pems !== [];
    }

    /**
     * Every trusted certificate as a PEM string.
     *
     * @return list<string>
     *
     * @throws ConfigurationException
     */
    public function all(): array
    {
        return array_column($this->load(), 'pem');
    }

    /**
     * Details of each trusted certificate, for diagnostics.
     *
     * @return list<array{source: string, subject: string, fingerprint: string, valid_from: ?DateTimeImmutable, valid_to: ?DateTimeImmutable, expired: bool}>
     *
     * @throws ConfigurationException
     */
    public function describe(?DateTimeImmutable $now = null): array
    {
        return array_map(
            static fn (array $certificate): array => self::describePem($certificate['pem'], $certificate['source'], $now),
            $this->load(),
        );
    }

    private static function timestamp(mixed $value): ?DateTimeImmutable
    {
        return is_int($value) ? new DateTimeImmutable('@' . $value) : null;
    }

    /**
     * @return list<array{source: string, pem: string}>
     *
     * @throws ConfigurationException
     */
    private function load(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        if (! $this->isConfigured()) {
            throw new ConfigurationException(
                'No UIDAI certificate is configured. Download the Offline e-KYC public certificate from UIDAI and set AADHAAR_OFFLINE_CERTIFICATES.',
            );
        }

        $loaded = [];

        foreach ($this->paths as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                throw new ConfigurationException("The UIDAI certificate file [{$path}] does not exist or is not readable.");
            }

            $loaded[] = ['source' => $path, 'pem' => self::toPem((string) file_get_contents($path), $path)];
        }

        foreach ($this->pems as $index => $pem) {
            $source = 'pem#' . $index;
            $loaded[] = ['source' => $source, 'pem' => self::toPem($pem, $source)];
        }

        return $this->loaded = $loaded;
    }
}
