<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Parsers;

use SensitiveParameter;
use Shakil\AadhaarOffline\Exceptions\InvalidShareCodeException;
use Shakil\AadhaarOffline\Exceptions\MalformedPayloadException;
use ZipArchive;

/**
 * Opens the share-code-protected Offline e-KYC ZIP and returns the XML inside.
 */
final readonly class ZipReader
{
    public function __construct(
        private int $maxZipBytes = 2 * 1024 * 1024,
        private int $maxXmlBytes = 2 * 1024 * 1024,
    ) {}

    /**
     * Share codes are the 4 characters the resident chose when downloading.
     *
     * @throws InvalidShareCodeException
     */
    public static function assertShareCode(#[SensitiveParameter] string $shareCode): void
    {
        if (preg_match('/^\d{4}$/', $shareCode) !== 1) {
            throw new InvalidShareCodeException('The share code must be exactly 4 digits.');
        }
    }

    /**
     * Read the single XML entry from the ZIP using the share code as password.
     *
     * @throws InvalidShareCodeException
     * @throws MalformedPayloadException
     */
    public function read(string $path, #[SensitiveParameter] string $shareCode): string
    {
        self::assertShareCode($shareCode);

        if (! is_file($path) || ! is_readable($path)) {
            throw new MalformedPayloadException('The Offline e-KYC ZIP file could not be read.');
        }

        $size = filesize($path);

        if ($size === false || $size === 0 || $size > $this->maxZipBytes) {
            throw new MalformedPayloadException('The Offline e-KYC ZIP file is empty or larger than allowed.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new MalformedPayloadException('The uploaded file is not a valid ZIP archive.');
        }

        try {
            return $this->extract($zip, $shareCode);
        } finally {
            $zip->close();
        }
    }

    private function extract(ZipArchive $zip, string $shareCode): string
    {
        if ($zip->numFiles !== 1) {
            throw new MalformedPayloadException('The Offline e-KYC ZIP must contain exactly one file.');
        }

        $stat = $zip->statIndex(0);

        if ($stat === false || ! str_ends_with(mb_strtolower($stat['name']), '.xml') || str_contains($stat['name'], '/')) {
            throw new MalformedPayloadException('The Offline e-KYC ZIP must contain a single XML file.');
        }

        if ($stat['size'] <= 0 || $stat['size'] > $this->maxXmlBytes) {
            throw new MalformedPayloadException('The XML inside the ZIP is empty or larger than allowed.');
        }

        $zip->setPassword($shareCode);

        $contents = $zip->getFromIndex(0);

        if ($contents === false || $contents === '') {
            throw new InvalidShareCodeException('The share code does not open this Offline e-KYC ZIP.');
        }

        // ZipCrypto's password check is a single byte; garbage output means the code was wrong.
        if (! str_contains(mb_substr(ltrim($contents, "\xEF\xBB\xBF \t\r\n"), 0, 100, '8bit'), '<')) {
            throw new InvalidShareCodeException('The share code does not open this Offline e-KYC ZIP.');
        }

        return $contents;
    }
}
