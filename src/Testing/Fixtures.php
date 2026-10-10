<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Testing;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use OpenSSLAsymmetricKey;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;
use Shakil\AadhaarOffline\Security\HashMatcher;
use Shakil\AadhaarOffline\Support\DecimalBytes;
use ZipArchive;

/**
 * Builds synthetic, self-signed payloads in the exact UIDAI shapes: Offline e-KYC XML,
 * share-code ZIPs and Secure QR codes, plus the matching test certificates.
 *
 * For tests only. Keys and certificates are generated at runtime and nothing here is
 * real Aadhaar data. Payloads only verify when the test certificate (identity()['cert'])
 * is trusted, so trust it in tests and never in production configuration.
 * Temporary files go to a per-process directory that is removed when the process exits.
 */
final class Fixtures
{
    public const string SHARE_CODE = '4321';

    public const string MOBILE = '9876543210';

    public const string EMAIL = 'resident@example.com';

    /**
     * A JPEG 2000 codestream stub that contains 0xFF bytes, like real Secure QR photos.
     */
    public const string QR_PHOTO = "\xFF\x4F\xFF\x51\x00\x2F\xFF\xFF\x00\x01\xFF\x52\x00\x0C\xFF\xD9";

    /** @var array<string, array{key: string, cert: string}> */
    private static array $identities = [];

    private static ?string $directory = null;

    /**
     * A cached RSA key pair and self-signed certificate.
     *
     * @return array{key: string, cert: string}
     */
    public static function identity(string $name = 'uidai-test', int $validDays = 365): array
    {
        $cacheKey = $name . ':' . $validDays;

        if (isset(self::$identities[$cacheKey])) {
            return self::$identities[$cacheKey];
        }

        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($config);

        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Could not generate a test key.');
        }

        $csr = openssl_csr_new(['commonName' => $name, 'organizationName' => 'Test Only'], $key, $config);

        // $key is passed by reference, so it has to be checked again.
        if ($csr === false || $csr === true || ! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Could not create a test CSR.');
        }

        $certificate = openssl_csr_sign($csr, null, $key, $validDays, $config);

        if ($certificate === false) {
            throw new RuntimeException('Could not self-sign a test certificate.');
        }

        if (! openssl_pkey_export($key, $keyPem) || ! openssl_x509_export($certificate, $certPem) || ! is_string($keyPem) || ! is_string($certPem)) {
            throw new RuntimeException('Could not export the test key pair.');
        }

        return self::$identities[$cacheKey] = ['key' => $keyPem, 'cert' => $certPem];
    }

    /**
     * Unsigned Offline e-KYC XML.
     *
     * @param array<string, mixed> $overrides last_four, generated_at, name, dob, gender, mobile, email, photo, share_code
     */
    public static function xml(array $overrides = []): string
    {
        $shareCode = self::text($overrides['share_code'] ?? null, self::SHARE_CODE);
        $lastFour = self::text($overrides['last_four'] ?? null, '5678');
        $lastDigit = (int) mb_substr($lastFour, -1);
        $generatedAt = $overrides['generated_at'] ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        assert($generatedAt instanceof DateTimeImmutable);

        $mobile = $overrides['mobile'] ?? self::MOBILE;
        $email = $overrides['email'] ?? self::EMAIL;

        $attributes = [
            'dob' => $overrides['dob'] ?? '15-08-1990',
            'e' => is_string($email) ? HashMatcher::hash($email, $shareCode, $lastDigit) : '',
            'gender' => $overrides['gender'] ?? 'F',
            'm' => is_string($mobile) ? HashMatcher::hash($mobile, $shareCode, $lastDigit) : '',
            'name' => $overrides['name'] ?? 'Asha Verma',
        ];

        $poi = '';

        foreach ($attributes as $name => $value) {
            $poi .= ' ' . $name . '="' . htmlspecialchars(self::text($value), ENT_XML1) . '"';
        }

        $photo = $overrides['photo'] ?? 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<OfflinePaperlessKyc referenceId="' . $lastFour . $generatedAt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('YmdHisv') . '">'
            . '<UidData>'
            . '<Poi' . $poi . '/>'
            . '<Poa careof="D/O: Ramesh Verma" country="India" dist="New Delhi" house="221B" landmark="Near Park" loc="Lajpat Nagar" pc="110024" po="Lajpat Nagar" state="Delhi" street="Ring Road" subdist="Defence Colony" vtc="New Delhi"/>'
            . '<Pht>' . self::text($photo) . '</Pht>'
            . '</UidData>'
            . '</OfflinePaperlessKyc>';
    }

    /**
     * Offline e-KYC XML with an enveloped signature, like UIDAI produces.
     *
     * @param array<string, mixed> $overrides See xml()
     */
    public static function signedXml(array $overrides = [], string $algorithm = XMLSecurityKey::RSA_SHA256, string $identity = 'uidai-test'): string
    {
        return self::sign(self::xml($overrides), $algorithm, $identity);
    }

    /**
     * Sign arbitrary XML with an enveloped signature over the whole document.
     */
    public static function sign(string $xml, string $algorithm = XMLSecurityKey::RSA_SHA256, string $identity = 'uidai-test'): string
    {
        $pair = self::identity($identity);

        $document = new DOMDocument();
        $document->loadXML($xml);

        $dsig = new XMLSecurityDSig('');
        $dsig->setCanonicalMethod(XMLSecurityDSig::C14N);
        $dsig->addReference(
            $document,
            $algorithm === XMLSecurityKey::RSA_SHA1 ? XMLSecurityDSig::SHA1 : XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'],
            ['force_uri' => true],
        );

        $key = new XMLSecurityKey($algorithm, ['type' => 'private']);
        $key->loadKey($pair['key']);

        $dsig->sign($key);
        $dsig->add509Cert($pair['cert']);
        $root = $document->documentElement;

        if (! $root instanceof DOMElement) {
            throw new RuntimeException('The XML to sign has no root element.');
        }

        $dsig->appendSignature($root);

        return (string) $document->saveXML();
    }

    /**
     * Decompressed Secure QR bytes: delimited fields, photo, optional hashes, RSA signature.
     *
     * @param array<string, mixed> $overrides last_four, generated_at, name, dob, gender, mobile, email, photo, indicator, fields (raw field list)
     * @param string|null          $version   "V2", "V5", ... or null for the legacy layout with hashes
     */
    public static function secureQrBytes(array $overrides = [], ?string $version = 'V2', string $identity = 'uidai-test'): string
    {
        $lastFour = self::text($overrides['last_four'] ?? null, '5678');
        $lastDigit = (int) mb_substr($lastFour, -1);
        $generatedAt = $overrides['generated_at'] ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        assert($generatedAt instanceof DateTimeImmutable);

        $mobile = array_key_exists('mobile', $overrides) ? $overrides['mobile'] : self::MOBILE;
        $email = array_key_exists('email', $overrides) ? $overrides['email'] : self::EMAIL;
        $indicator = $overrides['indicator'] ?? (string) ((is_string($email) ? 1 : 0) + (is_string($mobile) ? 2 : 0));

        $fields = $overrides['fields'] ?? [
            $indicator,
            $lastFour . $generatedAt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('YmdHisv'),
            $overrides['name'] ?? 'Asha Verma',
            $overrides['dob'] ?? '15-08-1990',
            $overrides['gender'] ?? 'F',
            'D/O: Ramesh Verma', 'New Delhi', 'Near Park', '221B', 'Lajpat Nagar', '110024', 'Lajpat Nagar', 'Delhi', 'Ring Road', 'Defence Colony', 'New Delhi',
        ];
        assert(is_array($fields));

        if ($version !== null) {
            array_unshift($fields, $version);
            $fields[] = is_string($mobile) ? mb_substr($mobile, -4) : '';
        }

        $data = implode('', array_map(static fn (mixed $field): string => mb_convert_encoding(self::text($field), 'ISO-8859-1', 'UTF-8') . "\xFF", $fields));
        $data .= self::text($overrides['photo'] ?? null, self::QR_PHOTO);

        if ($version === null) {
            $data .= is_string($email) ? (string) hex2bin(HashMatcher::hash($email, '', $lastDigit)) : '';
            $data .= is_string($mobile) ? (string) hex2bin(HashMatcher::hash($mobile, '', $lastDigit)) : '';
        }

        if (! openssl_sign($data, $signature, self::identity($identity)['key'], OPENSSL_ALGO_SHA256) || ! is_string($signature)) {
            throw new RuntimeException('Could not sign the test Secure QR payload.');
        }

        return $data . $signature;
    }

    /**
     * The scanned text of a Secure QR code: the compressed bytes as one big decimal number.
     *
     * @param array<string, mixed> $overrides See secureQrBytes()
     */
    public static function secureQr(array $overrides = [], ?string $version = 'V2', string $identity = 'uidai-test'): string
    {
        return self::encodeSecureQr(self::secureQrBytes($overrides, $version, $identity));
    }

    /**
     * GZIP-compress decompressed Secure QR bytes and encode them as decimal.
     */
    public static function encodeSecureQr(string $bytes): string
    {
        return DecimalBytes::fromBytes((string) gzencode($bytes));
    }

    /**
     * Write a share-code-protected ZIP containing the given XML; returns its path.
     *
     * @param array<string, string> $extraEntries
     */
    public static function zip(string $xml, string $shareCode = self::SHARE_CODE, int $method = ZipArchive::EM_AES_256, array $extraEntries = [], string $entryName = 'offlineaadhaar20261008123456789.xml'): string
    {
        $path = self::tempPath('zip');
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the test ZIP.');
        }

        $zip->addFromString($entryName, $xml);

        if ($method !== ZipArchive::EM_NONE) {
            $zip->setEncryptionName($entryName, $method, $shareCode);
        }

        foreach ($extraEntries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    /**
     * Write contents to a temporary file; returns its path.
     */
    public static function file(string $contents, string $extension = 'tmp'): string
    {
        $path = self::tempPath($extension);
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Write the test certificate to a temporary file (PEM or DER); returns its path.
     */
    public static function certificateFile(string $identity = 'uidai-test', bool $der = false, int $validDays = 365): string
    {
        $pem = self::identity($identity, $validDays)['cert'];

        if (! $der) {
            return self::file($pem, 'pem');
        }

        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);

        return self::file((string) base64_decode((string) $body, true), 'cer');
    }

    /**
     * A temporary directory for this test process, removed when the process exits.
     */
    public static function directory(): string
    {
        if (self::$directory !== null) {
            return self::$directory;
        }

        $directory = sys_get_temp_dir() . '/aadhaar-offline-tests/' . getmypid() . '-' . bin2hex(random_bytes(4));

        if (! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the test directory [{$directory}].");
        }

        register_shutdown_function(static fn () => self::remove($directory));

        return self::$directory = $directory;
    }

    /**
     * A scalar override as text; anything else becomes the default.
     */
    private static function text(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    private static function tempPath(string $extension): string
    {
        return self::directory() . '/' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                self::remove($path . '/' . $entry);
            }

            rmdir($path);

            return;
        }

        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
