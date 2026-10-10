<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Security;

use Shakil\AadhaarOffline\Exceptions\ConfigurationException;
use Shakil\AadhaarOffline\Exceptions\InvalidSignatureException;

/**
 * Verifies the RSA signature on an Aadhaar Secure QR payload.
 *
 * UIDAI signs the decompressed QR bytes (everything except the trailing
 * 256-byte signature) with SHA256withRSA, using the same key as Offline e-KYC XML.
 * The key always comes from the trusted CertificateStore.
 */
final readonly class QrSignatureVerifier
{
    public const int SIGNATURE_BYTES = 256;

    public function __construct(
        private CertificateStore $certificates,
    ) {}

    /**
     * @throws InvalidSignatureException
     * @throws ConfigurationException
     */
    public function verify(string $signedData, string $signature): void
    {
        $trusted = $this->certificates->all();

        if (mb_strlen($signature, '8bit') !== self::SIGNATURE_BYTES) {
            throw new InvalidSignatureException('The Secure QR signature has an unexpected length.');
        }

        foreach ($trusted as $pem) {
            $key = openssl_pkey_get_public($pem);

            if ($key === false) {
                throw new ConfigurationException('A configured UIDAI certificate does not contain a usable public key.');
            }

            if (openssl_verify($signedData, $signature, $key, OPENSSL_ALGO_SHA256) === 1) {
                return;
            }

            // 0 means "not this key". -1 or false here can only come from the key (the signature
            // length was checked above), e.g. a non-RSA certificate; the next certificate may still match.
            while (openssl_error_string() !== false);
        }

        throw new InvalidSignatureException('The Secure QR signature does not verify against any trusted certificate.');
    }
}
