<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Data;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use SensitiveParameter;
use Shakil\AadhaarOffline\Contracts\Verifier;
use Shakil\AadhaarOffline\Enums\Gender;
use Shakil\AadhaarOffline\Enums\PayloadSource;
use Shakil\AadhaarOffline\Exceptions\AadhaarOfflineException;
use Shakil\AadhaarOffline\Exceptions\ConfigurationException;
use Shakil\AadhaarOffline\Exceptions\MalformedPayloadException;
use Shakil\AadhaarOffline\Security\HashMatcher;
use Shakil\AadhaarOffline\Support\AadhaarNumber;

/**
 * A parsed Aadhaar Offline e-KYC payload: Offline e-KYC XML or a Secure QR code.
 *
 * Parsing does not mean the data is genuine. Call verify() before trusting it.
 * The full Aadhaar number is never part of the payload; only the last four
 * digits are available, through the reference ID.
 */
final class OfflineKyc implements JsonSerializable
{
    public const string TIMEZONE = 'Asia/Kolkata';

    private bool $verified = false;

    public function __construct(
        private readonly string $referenceId,
        private readonly ?string $name = null,
        private readonly ?string $dateOfBirth = null,
        private readonly ?Gender $gender = null,
        private readonly Address $address = new Address(),
        private readonly ?Photo $photo = null,
        private readonly ?string $mobileHash = null,
        private readonly ?string $emailHash = null,
        #[SensitiveParameter]
        private readonly ?string $shareCode = null,
        private readonly ?string $rawXml = null,
        private readonly ?Verifier $verifier = null,
        private readonly ?SignedBytes $signedBytes = null,
        private readonly ?string $mobileLastFour = null,
    ) {
        if (preg_match('/^\d{4}\d{14,17}$/', $referenceId) !== 1) {
            throw new MalformedPayloadException('The referenceId is not in the expected UIDAI format.');
        }
    }

    /**
     * Hide sensitive fields from dd(), dump() and var_dump().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /**
     * Verify the UIDAI signature and freshness rules.
     *
     * @throws AadhaarOfflineException
     */
    public function verify(): self
    {
        if (! $this->verifier instanceof Verifier) {
            throw new ConfigurationException('This payload has no verifier. Create it through the AadhaarOffline facade.');
        }

        $this->verifier->verify($this);
        $this->verified = true;

        return $this;
    }

    /**
     * Whether verify() has completed successfully on this instance.
     */
    public function isVerified(): bool
    {
        return $this->verified;
    }

    /**
     * Whether this came from Offline e-KYC XML or a Secure QR code.
     */
    public function source(): PayloadSource
    {
        return $this->signedBytes instanceof SignedBytes ? PayloadSource::SecureQr : PayloadSource::Xml;
    }

    /**
     * UIDAI reference ID: last four digits of the Aadhaar number followed by a timestamp.
     */
    public function referenceId(): string
    {
        return $this->referenceId;
    }

    /**
     * Last four digits of the Aadhaar number.
     */
    public function lastFourDigits(): string
    {
        return mb_substr($this->referenceId, 0, 4);
    }

    /**
     * Masked Aadhaar number, e.g. "XXXX XXXX 1234".
     */
    public function maskedAadhaar(string $maskCharacter = 'X', bool $grouped = true): string
    {
        return (string) AadhaarNumber::mask($this->lastFourDigits(), $maskCharacter, $grouped);
    }

    /**
     * When UIDAI generated the payload (IST), parsed from the reference ID.
     */
    public function generatedAt(): DateTimeImmutable
    {
        $timestamp = mb_substr($this->referenceId, 4);
        $timezone = new DateTimeZone(self::TIMEZONE);
        $seconds = mb_substr($timestamp, 0, 14);
        $milliseconds = mb_str_pad(mb_substr($timestamp, 14), 3, '0');

        $date = DateTimeImmutable::createFromFormat('!YmdHisv', $seconds . $milliseconds, $timezone);

        if ($date === false) {
            throw new MalformedPayloadException('The referenceId does not contain a valid timestamp.');
        }

        return $date;
    }

    /**
     * Whole days elapsed since the payload was generated.
     */
    public function ageInDays(?DateTimeInterface $now = null): int
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));

        $seconds = $now->getTimestamp() - $this->generatedAt()->getTimestamp();

        return intdiv(max(0, $seconds), 86_400);
    }

    /**
     * Whether the payload is no older than the given number of days.
     */
    public function isFresh(int $maxAgeDays, ?DateTimeInterface $now = null): bool
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));

        return $now->getTimestamp() - $this->generatedAt()->getTimestamp() <= $maxAgeDays * 86_400;
    }

    /**
     * Resident name as printed on Aadhaar.
     */
    public function name(): ?string
    {
        return $this->name;
    }

    /**
     * Date of birth as it appears in the payload (usually "DD-MM-YYYY", sometimes only "YYYY").
     */
    public function dateOfBirthRaw(): ?string
    {
        return $this->dateOfBirth;
    }

    /**
     * Full date of birth, or null when only the year is present.
     */
    public function dob(): ?DateTimeImmutable
    {
        if ($this->dateOfBirth === null) {
            return null;
        }

        foreach (['!d-m-Y', '!d/m/Y', '!Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $this->dateOfBirth, new DateTimeZone(self::TIMEZONE));

            if ($date !== false && $date->format(mb_substr($format, 1)) === $this->dateOfBirth) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Year of birth, available even when the full date is not.
     */
    public function birthYear(): ?int
    {
        if ($this->dateOfBirth === null || preg_match('/(\d{4})/', $this->dateOfBirth, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Age in whole years on the given date (or today), when the full date of birth is known.
     */
    public function age(?DateTimeInterface $on = null): ?int
    {
        $dob = $this->dob();

        if (! $dob instanceof DateTimeImmutable) {
            return null;
        }

        $on ??= new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));

        return $dob->diff($on)->y;
    }

    public function gender(): ?Gender
    {
        return $this->gender;
    }

    public function address(): Address
    {
        return $this->address;
    }

    public function photo(): ?Photo
    {
        return $this->photo;
    }

    /**
     * Whether the payload carries a mobile number hash (i.e. a mobile is linked).
     *
     * Secure QR codes from version 2 on carry no hash; see mobileLastFourDigits().
     */
    public function hasMobile(): bool
    {
        return $this->mobileHash !== null && $this->mobileHash !== '';
    }

    /**
     * Last four digits of the linked mobile number, carried by Secure QR codes from version 2 on.
     *
     * Only meaningful after verify(). Not included in toArray().
     */
    public function mobileLastFourDigits(): ?string
    {
        return $this->mobileLastFour;
    }

    /**
     * Whether the payload carries an email hash (i.e. an email is linked).
     */
    public function hasEmail(): bool
    {
        return $this->emailHash !== null && $this->emailHash !== '';
    }

    /**
     * Whether the given mobile number is the one linked to this Aadhaar.
     *
     * Only meaningful after verify(); a forged payload can carry any hash.
     */
    public function matchesMobile(string $mobile): bool
    {
        return $this->hasMobile()
            && HashMatcher::matchesMobile($mobile, (string) $this->mobileHash, $this->requireShareCode(), $this->lastDigit());
    }

    /**
     * Whether the given email address is the one linked to this Aadhaar.
     *
     * Only meaningful after verify(); a forged payload can carry any hash.
     */
    public function matchesEmail(string $email): bool
    {
        return $this->hasEmail()
            && HashMatcher::matchesEmail($email, (string) $this->emailHash, $this->requireShareCode(), $this->lastDigit());
    }

    /**
     * The original signed XML, needed for signature verification.
     *
     * Contains personal data. Do not log or store it unless you have to.
     */
    public function rawXml(): ?string
    {
        return $this->rawXml;
    }

    /**
     * The signed bytes and signature of a Secure QR payload, needed for signature verification.
     *
     * Contains personal data. Do not log or store it unless you have to.
     */
    public function signedBytes(): ?SignedBytes
    {
        return $this->signedBytes;
    }

    /**
     * Privacy-safe array: no share code, hashes, raw XML or full Aadhaar number.
     * The photo is included only when asked for.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $withPhoto = false): array
    {
        $data = [
            'source' => $this->source()->value,
            'reference_id' => $this->referenceId,
            'masked_aadhaar' => $this->maskedAadhaar(),
            'last_four_digits' => $this->lastFourDigits(),
            'name' => $this->name,
            'date_of_birth' => $this->dob()?->format('Y-m-d') ?? $this->dateOfBirth,
            'gender' => $this->gender?->value,
            'address' => $this->address->toArray(),
            'has_mobile' => $this->hasMobile(),
            'has_email' => $this->hasEmail(),
            'generated_at' => $this->generatedAt()->format(DATE_ATOM),
            'verified' => $this->verified,
        ];

        if ($withPhoto) {
            $data['photo'] = $this->photo?->toDataUri();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function lastDigit(): int
    {
        return (int) mb_substr($this->referenceId, 3, 1);
    }

    private function requireShareCode(): string
    {
        // Secure QR hashes are not salted with a share code.
        if ($this->source() === PayloadSource::SecureQr) {
            return '';
        }

        if ($this->shareCode === null || $this->shareCode === '') {
            throw new ConfigurationException('The share code is required to match mobile or email hashes.');
        }

        return $this->shareCode;
    }
}
