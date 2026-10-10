<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Security;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Shakil\AadhaarOffline\Exceptions\ConfigurationException;
use Shakil\AadhaarOffline\Exceptions\InvalidSignatureException;
use Shakil\AadhaarOffline\Exceptions\MalformedPayloadException;
use Shakil\AadhaarOffline\Parsers\XmlParser;
use Throwable;

/**
 * Verifies the enveloped XML digital signature UIDAI places on Offline e-KYC XML.
 *
 * The verification key always comes from the trusted CertificateStore, never from
 * the document's own KeyInfo. The single Reference must cover the whole document
 * (URI=""), which rules out signature-wrapping tricks.
 */
final readonly class SignatureVerifier
{
    /** @var list<string> */
    public const array DEFAULT_ALGORITHMS = [
        XMLSecurityKey::RSA_SHA256,
        XMLSecurityKey::RSA_SHA1,
    ];

    /**
     * @param list<string> $allowedAlgorithms SignatureMethod URIs to accept
     */
    public function __construct(
        private CertificateStore $certificates,
        private array $allowedAlgorithms = self::DEFAULT_ALGORITHMS,
        private int $maxXmlBytes = 2 * 1024 * 1024,
    ) {}

    /**
     * The certificate the document claims it was signed with, as PEM, or null when absent.
     *
     * For diagnostics only, e.g. to identify which UIDAI certificate to download.
     * It is never used to verify anything: a forged document can carry any certificate.
     *
     * @throws MalformedPayloadException
     */
    public static function embeddedCertificate(string $xml, int $maxXmlBytes = 2 * 1024 * 1024): ?string
    {
        $certificates = XmlParser::load($xml, $maxXmlBytes)->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'X509Certificate');

        if ($certificates->length !== 1) {
            return null;
        }

        $der = base64_decode((string) preg_replace('/\s+/', '', (string) $certificates->item(0)?->textContent), true);

        if ($der === false || $der === '') {
            return null;
        }

        try {
            return CertificateStore::toPem($der, 'embedded certificate');
        } catch (ConfigurationException) {
            return null;
        }
    }

    /**
     * @throws InvalidSignatureException
     * @throws ConfigurationException
     */
    public function verify(string $xml): void
    {
        $trusted = $this->certificates->all();

        try {
            $document = XmlParser::load($xml, $this->maxXmlBytes);
        } catch (MalformedPayloadException $exception) {
            throw new InvalidSignatureException('The payload could not be loaded for signature verification.', $exception->getCode(), previous: $exception);
        }

        $algorithm = $this->assertStructure($document);
        $dsig = $this->prepare($document);

        foreach ($trusted as $pem) {
            $key = $this->key($algorithm, $pem);

            try {
                $result = $dsig->verify($key);
            } catch (Throwable $exception) {
                // Thrown for a missing or unusable SignatureValue: the document's fault, whatever the key.
                throw new InvalidSignatureException('The XML signature value could not be checked.', $exception->getCode(), previous: $exception);
            }

            if ($result === 1) {
                return;
            }

            // 0 means "not this key"; -1 means the key cannot check this signature (e.g. a non-RSA
            // certificate). Either way the next certificate may match, which matters during a rotation.
            while (openssl_error_string() !== false);
        }

        throw new InvalidSignatureException('The UIDAI signature does not verify against any trusted certificate.');
    }

    /**
     * Ensure exactly one Signature, directly under the root, with one Reference covering the whole document.
     *
     * @return string The SignatureMethod algorithm
     *
     * @throws InvalidSignatureException
     */
    private function assertStructure(DOMDocument $document): string
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);

        $signatures = $xpath->query('//ds:Signature');

        if ($signatures === false || $signatures->length !== 1) {
            throw new InvalidSignatureException('The payload must contain exactly one XML signature.');
        }

        $signature = $signatures->item(0);

        $root = $document->documentElement;

        if (! $root instanceof DOMElement || ! $signature instanceof DOMElement || ! $signature->parentNode instanceof DOMElement || ! $signature->parentNode->isSameNode($root)) {
            throw new InvalidSignatureException('The XML signature must be a direct child of the root element.');
        }

        $references = $xpath->query('./ds:SignedInfo/ds:Reference', $signature);

        if ($references === false || $references->length !== 1) {
            throw new InvalidSignatureException('The XML signature must contain exactly one Reference.');
        }

        $reference = $references->item(0);

        if (! $reference instanceof DOMElement || $reference->getAttribute('URI') !== '') {
            throw new InvalidSignatureException('The XML signature must cover the whole document.');
        }

        $algorithm = $xpath->evaluate('string(./ds:SignedInfo/ds:SignatureMethod/@Algorithm)', $signature);
        $algorithm = is_string($algorithm) ? $algorithm : '';

        if (! in_array($algorithm, $this->allowedAlgorithms, true)) {
            throw new InvalidSignatureException('The XML signature uses an algorithm that is not allowed.');
        }

        return $algorithm;
    }

    /**
     * Load a configured certificate's public key for the document's (allowlisted) algorithm.
     *
     * @throws ConfigurationException When the configured certificate cannot be used
     */
    private function key(string $algorithm, string $pem): XMLSecurityKey
    {
        try {
            $key = new XMLSecurityKey($algorithm, ['type' => 'public']);
            $key->loadKey($pem, false, true);
        } catch (Throwable $exception) {
            throw new ConfigurationException('A configured UIDAI certificate does not contain a usable public key.', $exception->getCode(), previous: $exception);
        }

        return $key;
    }

    /**
     * @throws InvalidSignatureException
     */
    private function prepare(DOMDocument $document): XMLSecurityDSig
    {
        $dsig = new XMLSecurityDSig();

        $dsig->allowedSignatureAlgorithms = $this->allowedAlgorithms;
        $dsig->allowedDigestAlgorithms = [XMLSecurityDSig::SHA256, XMLSecurityDSig::SHA1];

        try {
            if (! $dsig->locateSignature($document) instanceof DOMElement) {
                throw new InvalidSignatureException('The XML signature could not be located.');
            }

            $dsig->canonicalizeSignedInfo();

            if (! $dsig->validateReference()) {
                throw new InvalidSignatureException('The signed content has been modified.');
            }
        } catch (InvalidSignatureException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidSignatureException('The signed content has been modified or is not supported.', $exception->getCode(), previous: $exception);
        }

        return $dsig;
    }
}
