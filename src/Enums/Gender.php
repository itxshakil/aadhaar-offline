<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Enums;

/**
 * Gender codes used in the UIDAI Offline e-KYC payload.
 */
enum Gender: string
{
    case Male = 'M';
    case Female = 'F';
    case Transgender = 'T';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
            self::Transgender => 'Transgender',
        };
    }
}
