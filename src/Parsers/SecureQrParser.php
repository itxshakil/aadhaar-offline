<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Parsers;

use Shakil\AadhaarOffline\Contracts\Verifier;
use Shakil\AadhaarOffline\Data\Address;
use Shakil\AadhaarOffline\Data\OfflineKyc;
use Shakil\AadhaarOffline\Data\Photo;
use Shakil\AadhaarOffline\Data\SignedBytes;
use Shakil\AadhaarOffline\Enums\Gender;
use Shakil\AadhaarOffline\Exceptions\MalformedPayloadException;
use Shakil\AadhaarOffline\Security\QrSignatureVerifier;
use Shakil\AadhaarOffline\Support\DecimalBytes;

/**
 * Parses the Aadhaar Secure QR code (the large number printed on Aadhaar letters,
 * PVC cards and e-Aadhaar PDFs) into an OfflineKyc object.
 *
 * Layout: the decimal number is the big-endian form of GZIP-compressed bytes. After
 * decompression, text fields are separated by 0xFF and decoded as ISO-8859-1, the
 * photo (usually JPEG 2000) follows, and the last 256 bytes are the RSA signature over
 * everything before them.
 *
 * - Legacy layout (no version marker): 16 fields, then the photo, then 32-byte email
 *   and/or mobile hashes depending on the first field (1 email, 2 mobile, 3 both).
 * - Versioned layout ("V2" and later): the version, the same 16 fields, the last four
 *   digits of the mobile number, then the photo. No hashes.
 */
final readonly class SecureQrParser
{
    /** Versions known to use the versioned layout. Anything else is rejected rather than misread. */
    public const array SUPPORTED_VERSIONS = ['V2', 'V3', 'V5'];

    /** QR codes hold at most 7089 numeric characters. */
    public const int MAX_DIGITS = 8_000;

    private const string DELIMITER = "\xFF";

    private const int HASH_BYTES = 32;

    private const array FIELDS = [
        'email_mobile', 'reference_id', 'name', 'dob', 'gender',
        'careof', 'dist', 'landmark', 'house', 'loc', 'pc', 'po', 'state', 'street', 'subdist', 'vtc',
    ];

    public function __construct(
        private int $maxDecompressedBytes = 64 * 1024,
    ) {}

    /**
     * @throws MalformedPayloadException
     */
    public function parse(string $qr, ?Verifier $verifier = null): OfflineKyc
    {
        $bytes = $this->decompress($this->toBytes($qr));
        $length = mb_strlen($bytes, '8bit');

        if ($length <= QrSignatureVerifier::SIGNATURE_BYTES) {
            throw new MalformedPayloadException('The Secure QR payload is too short.');
        }

        $signed = mb_substr($bytes, 0, $length - QrSignatureVerifier::SIGNATURE_BYTES, '8bit');
        $signature = mb_substr($bytes, -QrSignatureVerifier::SIGNATURE_BYTES, null, '8bit');

        $version = $this->version($signed);
        $names = $version === null ? self::FIELDS : ['version', ...self::FIELDS, 'mobile_last_four'];
        [$fields, $offset] = $this->fields($signed, $names);

        $indicator = $fields['email_mobile'];

        if (! in_array($indicator, ['0', '1', '2', '3'], true)) {
            throw new MalformedPayloadException('The Secure QR email/mobile indicator is not recognised.');
        }

        $hasEmail = $version === null && in_array($indicator, ['1', '3'], true);
        $hasMobile = $version === null && in_array($indicator, ['2', '3'], true);
        $hashBytes = ((int) $hasEmail + (int) $hasMobile) * self::HASH_BYTES;
        $photoLength = mb_strlen($signed, '8bit') - $offset - $hashBytes;

        if ($photoLength < 0) {
            throw new MalformedPayloadException('The Secure QR payload is truncated.');
        }

        $photo = mb_substr($signed, $offset, $photoLength, '8bit');
        $hashes = mb_substr($signed, $offset + $photoLength, null, '8bit');
        $mobileLastFour = $fields['mobile_last_four'] ?? null;

        return new OfflineKyc(
            referenceId: (string) $fields['reference_id'],
            name: $fields['name'],
            dateOfBirth: $fields['dob'],
            gender: Gender::tryFrom(mb_strtoupper((string) $fields['gender'])),
            address: Address::fromUidaiAttributes(array_map(strval(...), array_intersect_key($fields, array_flip(['careof', 'dist', 'landmark', 'house', 'loc', 'pc', 'po', 'state', 'street', 'subdist', 'vtc'])))),
            photo: $photo === '' ? null : new Photo($photo),
            // Email hash first, mobile hash last (directly before the signature).
            mobileHash: $hasMobile ? bin2hex(mb_substr($hashes, -self::HASH_BYTES, null, '8bit')) : null,
            emailHash: $hasEmail ? bin2hex(mb_substr($hashes, 0, self::HASH_BYTES, '8bit')) : null,
            verifier: $verifier,
            signedBytes: new SignedBytes($signed, $signature),
            mobileLastFour: $mobileLastFour !== null && preg_match('/^\d{4}$/', $mobileLastFour) === 1 ? $mobileLastFour : null,
        );
    }

    /**
     * @throws MalformedPayloadException
     */
    private function toBytes(string $qr): string
    {
        $qr = trim($qr);

        if ($qr === '' || mb_strlen($qr) > self::MAX_DIGITS || preg_match('/^\d+$/', $qr) !== 1) {
            throw new MalformedPayloadException('The value is not an Aadhaar Secure QR code.');
        }

        return DecimalBytes::toBytes($qr);
    }

    /**
     * @throws MalformedPayloadException
     */
    private function decompress(string $bytes): string
    {
        if (! str_starts_with($bytes, "\x1F\x8B")) {
            throw new MalformedPayloadException('The value is not an Aadhaar Secure QR code.');
        }

        // Suppress the PHP warning; a corrupt or oversized payload is reported below.
        $decompressed = @gzdecode($bytes, $this->maxDecompressedBytes);

        if ($decompressed === false || $decompressed === '') {
            throw new MalformedPayloadException('The Secure QR payload could not be decompressed or is larger than allowed.');
        }

        return $decompressed;
    }

    /**
     * The version marker ("V2", ...), or null for the legacy layout.
     *
     * @throws MalformedPayloadException
     */
    private function version(string $signed): ?string
    {
        if (preg_match('/^V(\d+)\xFF/', $signed, $matches) !== 1) {
            return null;
        }

        $version = 'V' . $matches[1];

        if (! in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw new MalformedPayloadException("Secure QR version {$version} is not supported yet.");
        }

        return $version;
    }

    /**
     * Read the delimited text fields; returns them with the offset where the photo starts.
     *
     * Only the first count($names) delimiters are used: the photo itself may contain 0xFF.
     *
     * @param  list<string>                                 $names
     * @return array{0: array<string, string|null>, 1: int}
     *
     * @throws MalformedPayloadException
     */
    private function fields(string $signed, array $names): array
    {
        $fields = [];
        $offset = 0;

        foreach ($names as $name) {
            $end = mb_strpos($signed, self::DELIMITER, $offset, '8bit');

            if ($end === false) {
                throw new MalformedPayloadException('The Secure QR payload has fewer fields than expected.');
            }

            $value = trim(mb_convert_encoding(mb_substr($signed, $offset, $end - $offset, '8bit'), 'UTF-8', 'ISO-8859-1'));
            $fields[$name] = $value === '' ? null : $value;
            $offset = $end + 1;
        }

        return [$fields, $offset];
    }
}
