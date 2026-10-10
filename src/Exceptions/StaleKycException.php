<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Exceptions;

/**
 * The Offline e-KYC payload is older than the allowed maximum age.
 */
class StaleKycException extends AadhaarOfflineException {}
