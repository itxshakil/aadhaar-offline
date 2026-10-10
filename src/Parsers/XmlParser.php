<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Parsers;

use DOMDocument;
use DOMElement;
use SensitiveParameter;
use Shakil\AadhaarOffline\Contracts\Verifier;
use Shakil\AadhaarOffline\Data\Address;
use Shakil\AadhaarOffline\Data\OfflineKyc;
use Shakil\AadhaarOffline\Data\Photo;
use Shakil\AadhaarOffline\Enums\Gender;
use Shakil\AadhaarOffline\Exceptions\MalformedPayloadException;

/**
 * Parses the UIDAI Offline e-KYC XML (<OfflinePaperlessKyc>) into an OfflineKyc object.
 *
 * Hardened against XXE: DOCTYPE declarations are refused and network access is disabled.
 */
final readonly class XmlParser
{
    public const string ROOT_ELEMENT = 'OfflinePaperlessKyc';

    public function __construct(
        private int $maxXmlBytes = 2 * 1024 * 1024,
    ) {}

    /**
     * Safely load untrusted XML into a DOMDocument.
     *
     * @throws MalformedPayloadException
     */
    public static function load(string $xml, int $maxXmlBytes = 2 * 1024 * 1024): DOMDocument
    {
        if ($xml === '' || mb_strlen($xml, '8bit') > $maxXmlBytes) {
            throw new MalformedPayloadException('The XML is empty or larger than allowed.');
        }

        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new MalformedPayloadException('XML with DOCTYPE or ENTITY declarations is not accepted.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new MalformedPayloadException('The payload is not well-formed XML.');
        }

        return $document;
    }

    /**
     * Parse the XML. The share code is kept on the result for mobile/email matching.
     *
     * @throws MalformedPayloadException
     */
    public function parse(string $xml, #[SensitiveParameter] ?string $shareCode = null, ?Verifier $verifier = null): OfflineKyc
    {
        $document = self::load($xml, $this->maxXmlBytes);
        $root = $document->documentElement;

        if (! $root instanceof DOMElement || $root->localName !== self::ROOT_ELEMENT) {
            throw new MalformedPayloadException('The XML is not an Aadhaar Offline e-KYC document.');
        }

        $uidData = $this->child($root, 'UidData');
        $poi = $this->child($uidData, 'Poi');
        $poa = $this->firstChild($uidData, 'Poa');
        $pht = $this->firstChild($uidData, 'Pht');

        $photoContent = $pht instanceof DOMElement ? trim($pht->textContent) : '';

        return new OfflineKyc(
            referenceId: $root->getAttribute('referenceId'),
            name: $this->attribute($poi, 'name'),
            dateOfBirth: $this->attribute($poi, 'dob'),
            gender: Gender::tryFrom(mb_strtoupper((string) $this->attribute($poi, 'gender'))),
            address: Address::fromUidaiAttributes($poa instanceof DOMElement ? $this->attributes($poa) : []),
            photo: $photoContent === '' ? null : Photo::fromBase64($photoContent),
            mobileHash: $this->attribute($poi, 'm'),
            emailHash: $this->attribute($poi, 'e'),
            shareCode: $shareCode,
            rawXml: $xml,
            verifier: $verifier,
        );
    }

    private function child(DOMElement $parent, string $name): DOMElement
    {
        $element = $this->firstChild($parent, $name);

        if (! $element instanceof DOMElement) {
            throw new MalformedPayloadException("The XML is missing the <{$name}> element.");
        }

        return $element;
    }

    private function firstChild(DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }

    private function attribute(DOMElement $element, string $name): ?string
    {
        if (! $element->hasAttribute($name)) {
            return null;
        }

        $value = trim($element->getAttribute($name));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    private function attributes(DOMElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes ?? [] as $attribute) {
            $attributes[$attribute->nodeName] = $attribute->nodeValue ?? '';
        }

        return $attributes;
    }
}
