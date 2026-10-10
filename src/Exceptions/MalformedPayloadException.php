<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Exceptions;

/**
 * The Offline e-KYC payload is not in the expected UIDAI format.
 */
class MalformedPayloadException extends AadhaarOfflineException {}
