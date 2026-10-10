<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Data;

/**
 * Proof-of-address block (<Poa>) from the Offline e-KYC payload.
 */
final readonly class Address
{
    public function __construct(
        public ?string $careOf = null,
        public ?string $house = null,
        public ?string $street = null,
        public ?string $landmark = null,
        public ?string $locality = null,
        public ?string $vtc = null,
        public ?string $postOffice = null,
        public ?string $subDistrict = null,
        public ?string $district = null,
        public ?string $state = null,
        public ?string $country = null,
        public ?string $pincode = null,
    ) {}

    /**
     * Build from the attributes of a <Poa> element (UIDAI attribute names).
     *
     * @param array<string, string> $attributes
     */
    public static function fromUidaiAttributes(array $attributes): self
    {
        $value = static fn (string $key): ?string => self::clean($attributes[$key] ?? null);

        return new self(
            careOf: $value('careof'),
            house: $value('house'),
            street: $value('street'),
            landmark: $value('landmark'),
            locality: $value('loc'),
            vtc: $value('vtc'),
            postOffice: $value('po'),
            subDistrict: $value('subdist'),
            district: $value('dist'),
            state: $value('state'),
            country: $value('country'),
            pincode: $value('pc'),
        );
    }

    /**
     * Single-line address, skipping empty parts and the care-of line.
     */
    public function formatted(string $separator = ', '): string
    {
        $parts = [
            $this->house,
            $this->street,
            $this->landmark,
            $this->locality,
            $this->vtc,
            $this->postOffice,
            $this->subDistrict,
            $this->district,
            $this->state,
            $this->pincode,
            $this->country,
        ];

        $parts = array_values(array_unique(array_filter($parts, static fn (?string $part): bool => $part !== null)));

        return implode($separator, $parts);
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'care_of' => $this->careOf,
            'house' => $this->house,
            'street' => $this->street,
            'landmark' => $this->landmark,
            'locality' => $this->locality,
            'vtc' => $this->vtc,
            'post_office' => $this->postOffice,
            'sub_district' => $this->subDistrict,
            'district' => $this->district,
            'state' => $this->state,
            'country' => $this->country,
            'pincode' => $this->pincode,
        ];
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
