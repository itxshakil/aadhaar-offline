<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Parsers;

use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Enums\Gender;
use Shakil\AadhaarOffline\Exceptions\MalformedPayloadException;
use Shakil\AadhaarOffline\Parsers\XmlParser;
use Shakil\AadhaarOffline\Testing\Fixtures;

class XmlParserTest extends TestCase
{
    public function test_it_parses_a_uidai_offline_kyc_document(): void
    {
        $kyc = (new XmlParser())->parse(Fixtures::signedXml(['last_four' => '2468']), Fixtures::SHARE_CODE);

        $this->assertSame('Asha Verma', $kyc->name());
        $this->assertSame('1990-08-15', $kyc->dob()?->format('Y-m-d'));
        $this->assertSame(Gender::Female, $kyc->gender());
        $this->assertSame('D/O: Ramesh Verma', $kyc->address()->careOf);
        $this->assertSame('110024', $kyc->address()->pincode);
        $this->assertSame('image/png', $kyc->photo()?->mimeType());
        $this->assertSame('2468', $kyc->lastFourDigits());
        $this->assertTrue($kyc->matchesMobile(Fixtures::MOBILE));
        $this->assertTrue($kyc->matchesEmail(Fixtures::EMAIL));
        $this->assertNotNull($kyc->rawXml());
    }

    public function test_it_handles_missing_optional_parts(): void
    {
        $xml = '<OfflinePaperlessKyc referenceId="123420261008153045123"><UidData><Poi name="Test" m="" e=""/></UidData></OfflinePaperlessKyc>';

        $kyc = (new XmlParser())->parse($xml);

        $this->assertSame('Test', $kyc->name());
        $this->assertNull($kyc->photo());
        $this->assertNull($kyc->address()->pincode);
        $this->assertFalse($kyc->hasMobile());
        $this->assertNull($kyc->gender());
    }

    public function test_it_rejects_doctype_and_entities(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><OfflinePaperlessKyc referenceId="123420261008153045123"><UidData><Poi name="&xxe;"/></UidData></OfflinePaperlessKyc>';

        $this->expectException(MalformedPayloadException::class);
        $this->expectExceptionMessage('DOCTYPE');

        (new XmlParser())->parse($xml);
    }

    public function test_it_rejects_the_wrong_root_element(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new XmlParser())->parse('<Kyc referenceId="123420261008153045123"/>');
    }

    public function test_it_rejects_missing_poi(): void
    {
        $this->expectException(MalformedPayloadException::class);
        $this->expectExceptionMessage('<Poi>');

        (new XmlParser())->parse('<OfflinePaperlessKyc referenceId="123420261008153045123"><UidData/></OfflinePaperlessKyc>');
    }

    public function test_it_rejects_malformed_xml(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new XmlParser())->parse('<OfflinePaperlessKyc');
    }

    public function test_it_rejects_oversized_xml(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new XmlParser(maxXmlBytes: 100))->parse(Fixtures::xml());
    }

    public function test_it_rejects_a_bad_reference_id(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new XmlParser())->parse('<OfflinePaperlessKyc referenceId="abc"><UidData><Poi name="x"/></UidData></OfflinePaperlessKyc>');
    }
}
