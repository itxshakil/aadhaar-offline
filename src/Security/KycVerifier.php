<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Security;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Shakil\AadhaarOffline\Contracts\Verifier;
use Shakil\AadhaarOffline\Data\OfflineKyc;
use Shakil\AadhaarOffline\Data\SignedBytes;
use Shakil\AadhaarOffline\Enums\PayloadSource;
use Shakil\AadhaarOffline\Exceptions\AadhaarOfflineException;
use Shakil\AadhaarOffline\Exceptions\ConfigurationException;
use Shakil\AadhaarOffline\Exceptions\InvalidSignatureException;
use Shakil\AadhaarOffline\Exceptions\StaleKycException;

/**
 * Runs every trust check on an Offline e-KYC payload: UIDAI signature, then freshness.
 *
 * Offline e-KYC XML and Secure QR codes have separate age limits: a printed QR code is
 * legitimately years old, while an XML download is expected to be fresh.
 */
final readonly class KycVerifier implements Verifier
{
    /**
     * @param int|null                                                  $maxAgeDays   Reject XML payloads older than this; null disables the check
     * @param int|null                                                  $qrMaxAgeDays Reject Secure QR payloads older than this; null disables the check
     * @param (Closure(): DateTimeImmutable)|null                       $clock        Current time provider (for tests)
     * @param (Closure(OfflineKyc): void)|null                          $onVerified   Called after a successful verification
     * @param (Closure(OfflineKyc, AadhaarOfflineException): void)|null $onRejected   Called before a failure is thrown
     */
    public function __construct(
        private SignatureVerifier $signatures,
        private bool $verifySignature = true,
        private ?int $maxAgeDays = 3,
        private ?Closure $clock = null,
        private ?Closure $onVerified = null,
        private ?Closure $onRejected = null,
        private ?QrSignatureVerifier $qrSignatures = null,
        private ?int $qrMaxAgeDays = null,
    ) {}

    /**
     * Normalize a configured maximum age: null, "" and "null" disable the check.
     */
    public static function normalizeMaxAgeDays(mixed $value, int $default = 3): ?int
    {
        if (in_array($value, [null, '', 'null'], true)) {
            return null;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    public function verify(OfflineKyc $kyc): void
    {
        try {
            if ($this->verifySignature) {
                $this->verifySignature($kyc);
            }

            $maxAgeDays = $kyc->source() === PayloadSource::SecureQr ? $this->qrMaxAgeDays : $this->maxAgeDays;

            if ($maxAgeDays !== null && ! $kyc->isFresh($maxAgeDays, $this->now())) {
                throw new StaleKycException("The Aadhaar payload is older than {$maxAgeDays} days.");
            }
        } catch (AadhaarOfflineException $exception) {
            if ($this->onRejected instanceof Closure) {
                ($this->onRejected)($kyc, $exception);
            }

            throw $exception;
        }

        if ($this->onVerified instanceof Closure) {
            ($this->onVerified)($kyc);
        }
    }

    /**
     * @throws AadhaarOfflineException
     */
    private function verifySignature(OfflineKyc $kyc): void
    {
        $signedBytes = $kyc->signedBytes();

        if ($signedBytes instanceof SignedBytes) {
            if (! $this->qrSignatures instanceof QrSignatureVerifier) {
                throw new ConfigurationException('No Secure QR signature verifier is configured.');
            }

            $this->qrSignatures->verify($signedBytes->data, $signedBytes->signature);

            return;
        }

        $xml = $kyc->rawXml();

        if ($xml === null || $xml === '') {
            throw new InvalidSignatureException('The payload has no signed XML to verify.');
        }

        $this->signatures->verify($xml);
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock instanceof Closure) {
            return ($this->clock)();
        }

        return new DateTimeImmutable('now', new DateTimeZone(OfflineKyc::TIMEZONE));
    }
}
