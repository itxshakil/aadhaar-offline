<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Data;

/**
 * The signed bytes and RSA signature of an Aadhaar Secure QR payload.
 *
 * The data contains personal information, so it is hidden from dumps.
 */
final readonly class SignedBytes
{
    public function __construct(
        public string $data,
        public string $signature,
    ) {}

    /**
     * @return array<string, int>
     */
    public function __debugInfo(): array
    {
        return ['data_bytes' => mb_strlen($this->data, '8bit'), 'signature_bytes' => mb_strlen($this->signature, '8bit')];
    }
}
