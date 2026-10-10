<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Exceptions;

/**
 * The UIDAI digital signature on the Offline e-KYC payload is missing or invalid.
 */
class InvalidSignatureException extends AadhaarOfflineException {}
