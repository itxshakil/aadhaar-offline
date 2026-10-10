<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Data;

use Shakil\AadhaarOffline\Exceptions\MalformedPayloadException;

/**
 * Resident photo (<Pht>) from the Offline e-KYC payload.
 *
 * Kept in memory only. Nothing is written to disk unless you ask for it.
 */
final readonly class Photo
{
    public function __construct(
        private string $bytes,
    ) {}

    /**
     * Build from the base64 content of a <Pht> element.
     */
    public static function fromBase64(string $base64): self
    {
        $bytes = base64_decode((string) preg_replace('/\s+/', '', $base64), true);

        if ($bytes === false || $bytes === '') {
            throw new MalformedPayloadException('The resident photo is not valid base64.');
        }

        return new self($bytes);
    }

    /**
     * Raw image bytes.
     */
    public function contents(): string
    {
        return $this->bytes;
    }

    /**
     * Base64-encoded image bytes.
     */
    public function toBase64(): string
    {
        return base64_encode($this->bytes);
    }

    /**
     * "data:" URI for use in an <img> tag without storing the file.
     */
    public function toDataUri(): string
    {
        return 'data:' . $this->mimeType() . ';base64,' . $this->toBase64();
    }

    /**
     * MIME type detected from the image's magic bytes.
     */
    public function mimeType(): string
    {
        return match (true) {
            str_starts_with($this->bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($this->bytes, "\x89PNG") => 'image/png',
            str_starts_with($this->bytes, "\x00\x00\x00\x0CjP  "),
            str_starts_with($this->bytes, "\xFF\x4F\xFF\x51") => 'image/jp2',
            default => 'application/octet-stream',
        };
    }

    /**
     * Suggested file extension for the detected MIME type.
     */
    public function extension(): string
    {
        return match ($this->mimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/jp2' => 'jp2',
            default => 'bin',
        };
    }

    /**
     * Size of the image in bytes.
     */
    public function size(): int
    {
        return mb_strlen($this->bytes, '8bit');
    }
}
