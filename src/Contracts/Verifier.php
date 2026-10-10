<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Contracts;

use Shakil\AadhaarOffline\Data\OfflineKyc;
use Shakil\AadhaarOffline\Exceptions\AadhaarOfflineException;

/**
 * Decides whether an Offline e-KYC payload can be trusted.
 */
interface Verifier
{
    /**
     * Verify the payload, throwing when it must not be trusted.
     *
     * @throws AadhaarOfflineException
     */
    public function verify(OfflineKyc $kyc): void;
}
