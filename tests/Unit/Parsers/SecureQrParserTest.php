<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Parsers;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Data\Photo;
use Shakil\AadhaarOffline\Data\SignedBytes;
use Shakil\AadhaarOffline\Enums\Gender;
use Shakil\AadhaarOffline\Enums\PayloadSource;
use Shakil\AadhaarOffline\Exceptions\MalformedPayloadException;
use Shakil\AadhaarOffline\Parsers\SecureQrParser;
use Shakil\AadhaarOffline\Testing\Fixtures;

class SecureQrParserTest extends TestCase
{
    public function test_it_parses_a_v2_code(): void
    {
        $kyc = (new SecureQrParser())->parse(Fixtures::secureQr());

        $this->assertSame(PayloadSource::SecureQr, $kyc->source());
        $this->assertSame('5678', $kyc->lastFourDigits());
        $this->assertSame('Asha Verma', $kyc->name());
        $this->assertSame('1990-08-15', $kyc->dob()?->format('Y-m-d'));
        $this->assertSame(Gender::Female, $kyc->gender());
        $this->assertSame('110024', $kyc->address()->pincode);
        $this->assertSame('Defence Colony', $kyc->address()->subDistrict);
        $this->assertSame('3210', $kyc->mobileLastFourDigits());
        $this->assertFalse($kyc->hasMobile());
        $this->assertFalse($kyc->hasEmail());
        $this->assertFalse($kyc->isVerified());
    }

    public function test_the_photo_may_contain_the_delimiter_byte(): void
    {
        $photo = (new SecureQrParser())->parse(Fixtures::secureQr())->photo();

        $this->assertInstanceOf(Photo::class, $photo);
        $this->assertSame(Fixtures::QR_PHOTO, $photo->contents());
        $this->assertSame('image/jp2', $photo->mimeType());
    }

    public function test_it_parses_later_versions_with_the_same_layout(): void
    {
        $kyc = (new SecureQrParser())->parse(Fixtures::secureQr(version: 'V5'));

        $this->assertSame('Asha Verma', $kyc->name());
        $this->assertSame('3210', $kyc->mobileLastFourDigits());
    }

    public function test_it_rejects_unknown_versions_instead_of_misreading_them(): void
    {
        $this->expectException(MalformedPayloadException::class);
        $this->expectExceptionMessage('V9');

        (new SecureQrParser())->parse(Fixtures::secureQr(version: 'V9'));
    }

    public function test_it_parses_the_legacy_layout_with_both_hashes(): void
    {
        $kyc = (new SecureQrParser())->parse(Fixtures::secureQr(version: null));

        $this->assertSame('Asha Verma', $kyc->name());
        $this->assertNull($kyc->mobileLastFourDigits());
        $this->assertSame(Fixtures::QR_PHOTO, $kyc->photo()?->contents());
        $this->assertTrue($kyc->matchesMobile('+91 98765 43210'));
        $this->assertTrue($kyc->matchesEmail('resident@example.com'));
        $this->assertFalse($kyc->matchesMobile('9876543211'));
        $this->assertFalse($kyc->matchesEmail('someone@example.com'));
    }

    public function test_it_parses_the_legacy_layout_with_only_a_mobile_hash(): void
    {
        $kyc = (new SecureQrParser())->parse(Fixtures::secureQr(['email' => null], version: null));

        $this->assertTrue($kyc->hasMobile());
        $this->assertFalse($kyc->hasEmail());
        $this->assertTrue($kyc->matchesMobile(Fixtures::MOBILE));
        $this->assertSame(Fixtures::QR_PHOTO, $kyc->photo()?->contents());
    }

    public function test_it_parses_the_legacy_layout_with_only_an_email_hash(): void
    {
        $kyc = (new SecureQrParser())->parse(Fixtures::secureQr(['mobile' => null], version: null));

        $this->assertFalse($kyc->hasMobile());
        $this->assertTrue($kyc->matchesEmail(Fixtures::EMAIL));
        $this->assertSame(Fixtures::QR_PHOTO, $kyc->photo()?->contents());
    }

    public function test_it_decodes_latin_1_text(): void
    {
        $kyc = (new SecureQrParser())->parse(Fixtures::secureQr(['name' => 'José Dé']));

        $this->assertSame('José Dé', $kyc->name());
    }

    public function test_it_keeps_the_signed_bytes_out_of_dumps(): void
    {
        // A fixed time keeps "3210" (the mobile's last four) from appearing in the timestamp by chance.
        $kyc = (new SecureQrParser())->parse(Fixtures::secureQr(['generated_at' => new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('Asia/Kolkata'))]));
        $signed = $kyc->signedBytes();

        $this->assertInstanceOf(SignedBytes::class, $signed);
        $this->assertSame(256, mb_strlen($signed->signature, '8bit'));
        $this->assertStringNotContainsString('Asha', print_r($signed, true));
        $this->assertArrayNotHasKey('mobile_last_four', $kyc->toArray());
        $this->assertStringNotContainsString('3210', (string) json_encode($kyc));
    }

    public function test_it_rejects_non_numeric_input(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new SecureQrParser())->parse('<PrintLetterBarcodeData uid="1"/>');
    }

    public function test_it_rejects_input_longer_than_a_qr_code_can_hold(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new SecureQrParser())->parse(str_repeat('9', SecureQrParser::MAX_DIGITS + 1));
    }

    public function test_it_rejects_a_number_that_is_not_gzip(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new SecureQrParser())->parse('123456789012345678901234567890');
    }

    public function test_it_rejects_a_payload_that_decompresses_beyond_the_limit(): void
    {
        $this->expectException(MalformedPayloadException::class);
        $this->expectExceptionMessage('larger than allowed');

        (new SecureQrParser(maxDecompressedBytes: 1024))->parse(Fixtures::encodeSecureQr(str_repeat("\x00", 4096)));
    }

    public function test_it_rejects_a_payload_with_too_few_fields(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new SecureQrParser())->parse(Fixtures::encodeSecureQr("V2\xFF2\xFF" . str_repeat('x', 300)));
    }

    public function test_it_rejects_an_unknown_email_mobile_indicator(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new SecureQrParser())->parse(Fixtures::secureQr(['indicator' => '7']));
    }

    public function test_it_rejects_a_payload_too_short_for_a_signature(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new SecureQrParser())->parse(Fixtures::encodeSecureQr('V2'));
    }
}
