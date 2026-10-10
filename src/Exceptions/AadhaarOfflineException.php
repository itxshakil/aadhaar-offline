<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Exceptions;

use RuntimeException;

/**
 * Base exception for every error thrown by the Aadhaar Offline package.
 *
 * Messages never contain the Aadhaar number, share code, mobile or email.
 */
class AadhaarOfflineException extends RuntimeException {}
