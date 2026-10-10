<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Enums;

/**
 * Where a parsed Aadhaar payload came from.
 */
enum PayloadSource: string
{
    /** Offline e-KYC XML, from the share-code ZIP or a KYC vendor. */
    case Xml = 'xml';

    /** The Secure QR code printed on Aadhaar letters, PVC cards and e-Aadhaar PDFs. */
    case SecureQr = 'secure_qr';
}
