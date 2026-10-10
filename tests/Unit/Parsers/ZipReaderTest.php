<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Parsers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Exceptions\InvalidShareCodeException;
use Shakil\AadhaarOffline\Exceptions\MalformedPayloadException;
use Shakil\AadhaarOffline\Parsers\ZipReader;
use Shakil\AadhaarOffline\Testing\Fixtures;
use ZipArchive;

class ZipReaderTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function encryptionProvider(): array
    {
        return [
            'AES-256' => [ZipArchive::EM_AES_256],
            'ZipCrypto' => [ZipArchive::EM_TRAD_PKWARE],
        ];
    }

    #[DataProvider('encryptionProvider')]
    public function test_it_opens_the_zip_with_the_share_code(int $method): void
    {
        $xml = Fixtures::xml();

        $this->assertSame($xml, (new ZipReader())->read(Fixtures::zip($xml, method: $method), Fixtures::SHARE_CODE));
    }

    #[DataProvider('encryptionProvider')]
    public function test_it_rejects_the_wrong_share_code(int $method): void
    {
        $this->expectException(InvalidShareCodeException::class);

        (new ZipReader())->read(Fixtures::zip(Fixtures::xml(), method: $method), '0000');
    }

    public function test_it_requires_a_four_digit_share_code(): void
    {
        $this->expectException(InvalidShareCodeException::class);

        (new ZipReader())->read(Fixtures::zip(Fixtures::xml()), '12a4');
    }

    public function test_it_rejects_more_than_one_entry(): void
    {
        $this->expectException(MalformedPayloadException::class);
        $this->expectExceptionMessage('exactly one file');

        (new ZipReader())->read(Fixtures::zip(Fixtures::xml(), extraEntries: ['extra.txt' => 'x']), Fixtures::SHARE_CODE);
    }

    public function test_it_rejects_a_non_xml_entry(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new ZipReader())->read(Fixtures::zip(Fixtures::xml(), entryName: 'payload.exe'), Fixtures::SHARE_CODE);
    }

    public function test_it_rejects_entries_in_subdirectories(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new ZipReader())->read(Fixtures::zip(Fixtures::xml(), entryName: '../evil.xml'), Fixtures::SHARE_CODE);
    }

    public function test_it_rejects_files_that_are_not_zips(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new ZipReader())->read(Fixtures::file('just text', 'zip'), Fixtures::SHARE_CODE);
    }

    public function test_it_rejects_a_missing_file(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new ZipReader())->read('/nonexistent/file.zip', Fixtures::SHARE_CODE);
    }

    public function test_it_enforces_the_zip_size_limit(): void
    {
        $this->expectException(MalformedPayloadException::class);

        (new ZipReader(maxZipBytes: 100))->read(Fixtures::zip(Fixtures::xml()), Fixtures::SHARE_CODE);
    }

    public function test_it_enforces_the_uncompressed_size_limit(): void
    {
        $bomb = '<OfflinePaperlessKyc>' . str_repeat(' ', 500_000) . '</OfflinePaperlessKyc>';

        $this->expectException(MalformedPayloadException::class);
        $this->expectExceptionMessage('larger than allowed');

        (new ZipReader(maxXmlBytes: 100_000))->read(Fixtures::zip($bomb), Fixtures::SHARE_CODE);
    }

    /**
     * A ZIP bomb can understate its uncompressed size to slip past the stat check.
     * libzip then returns at most the declared size, so the read stays bounded.
     */
    #[DataProvider('encryptionProvider')]
    public function test_an_understated_uncompressed_size_never_yields_more_than_declared(int $method): void
    {
        $path = Fixtures::zip('<x>' . str_repeat('A', 100_000) . '</x>', method: $method);
        $bytes = (string) file_get_contents($path);

        foreach (["PK\x01\x02" => 24, "PK\x03\x04" => 22] as $signature => $offset) {
            $position = (int) mb_strpos($bytes, $signature, 0, '8bit');

            $declared = unpack('V', mb_substr($bytes, $position + $offset, 4, '8bit'));

            // Encrypted entries may leave the local header size at 0 and use a data descriptor.
            if (is_array($declared) && $declared[1] !== 0) {
                $bytes = substr_replace($bytes, pack('V', 100), $position + $offset, 4);
            }
        }

        file_put_contents($path, $bytes);

        $this->assertSame(100, mb_strlen((new ZipReader(maxXmlBytes: 1024))->read($path, Fixtures::SHARE_CODE), '8bit'));
    }
}
