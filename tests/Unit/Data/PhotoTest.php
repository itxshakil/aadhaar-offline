<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Data\Photo;
use Shakil\AadhaarOffline\Exceptions\MalformedPayloadException;
use Shakil\AadhaarOffline\Testing\OfflineKycFactory;

class PhotoTest extends TestCase
{
    public function test_it_detects_jpeg_and_builds_a_data_uri(): void
    {
        $photo = Photo::fromBase64(OfflineKycFactory::PHOTO_BASE64);

        $this->assertSame('image/jpeg', $photo->mimeType());
        $this->assertSame('jpg', $photo->extension());
        $this->assertStringStartsWith('data:image/jpeg;base64,', $photo->toDataUri());
        $this->assertSame($photo->size(), mb_strlen($photo->contents(), '8bit'));
    }

    public function test_it_detects_png_and_jpeg2000(): void
    {
        $this->assertSame('image/png', (new Photo("\x89PNG\r\n\x1A\n"))->mimeType());
        $this->assertSame('image/jp2', (new Photo("\xFF\x4F\xFF\x51\x00"))->mimeType());
        $this->assertSame('application/octet-stream', (new Photo('nope'))->mimeType());
    }

    public function test_it_ignores_whitespace_in_base64(): void
    {
        $chunked = chunk_split(OfflineKycFactory::PHOTO_BASE64, 20, "\n");

        $this->assertSame('image/jpeg', Photo::fromBase64($chunked)->mimeType());
    }

    public function test_it_rejects_invalid_base64(): void
    {
        $this->expectException(MalformedPayloadException::class);

        Photo::fromBase64('***not base64***');
    }
}
