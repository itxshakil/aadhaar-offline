# Installation

Using Laravel? Install [itxshakil/laravel-aadhaar-offline](https://github.com/itxshakil/laravel-aadhaar-offline) instead.
It requires this package and adds a facade, validation rules, Artisan commands and events.

## 1. Install the package

```bash
composer require itxshakil/aadhaar-offline
```

PHP needs the `dom`, `openssl`, `zip` and `zlib` extensions. Check with:

```bash
php -m | grep -E "dom|openssl|zip|zlib"
```

## 2. Get the UIDAI certificate

The package needs the UIDAI public certificate that signs Offline e-KYC XML and Secure QR codes.
It doesn't ship one, for two reasons: UIDAI rotates certificates, and you should get trust anchors from the source yourself.

1. Find the current certificate on UIDAI's [Data and Downloads page](https://uidai.gov.in/en/data-and-download).
   Offline e-KYC certificates are named `uidai_offline_publickey_*.cer`.
2. Download it to a directory outside your web root:

```bash
curl -o storage/certs/uidai_offline_publickey_2026.cer \
    https://backend.uidai.gov.in/get/files/media/document/2026-07/uidai_offline_publickey_2026.cer
```

3. Compare its SHA-256 fingerprint with the table below before trusting it:

```bash
openssl x509 -in storage/certs/uidai_offline_publickey_2026.cer -noout -fingerprint -sha256
```

UIDAI moves files between URLs, so if the link above stops working, take the current one from the Data and Downloads page.

Offline e-KYC certificates on that page, as checked on 2026-10-10:

| File | Subject | Valid to | SHA-256 fingerprint |
|------|---------|----------|---------------------|
| `uidai_offline_publickey_2026.cer` | DS Unique Identification Authority of India 06 | 2029-02-03 | `E0:30:4B:9E:61:EE:36:40:EC:DD:AE:2D:B4:B6:17:F2:E2:67:8F:57:DB:C2:82:6C:2F:86:AC:5C:04:F2:77:DF` |
| `uidai_offline_publickey_17022026.cer` | DS Unique Identification Authority of India 05 | 2026-02-16 | `00:57:3C:69:2C:F0:4F:BE:E1:13:AE:5F:D5:2F:2A:65:4D:36:1B:F8:EE:4D:06:C4:60:08:90:6B:36:DF:81:69` |
| `uidai_offline_publickey_26022021.cer` | DS UNIQUE IDENTIFICATION AUTHORITY OF INDIA 05 | 2024-02-27 | `E0:F0:F8:69:D3:2E:FC:7E:80:FA:E2:22:37:17:A5:6D:CF:8B:61:6F:82:0B:54:2A:49:E5:BD:5A:BF:1C:0F:7D` |

Offline e-KYC downloads made today are signed with the 2026 certificate.
The expired ones only matter for Secure QR codes printed while they were current, so keep them if you verify Secure QR codes.

## 3. Create a reader

```php
use Shakil\AadhaarOffline\OfflineKycReader;

$reader = OfflineKycReader::create(
    certificates: [__DIR__ . '/storage/certs/uidai_offline_publickey_2026.cer'],
);
```

PEM and DER files are both accepted.
During a rotation, list both the old and the new certificate.

`create()` also takes `maxAgeDays` (default 3, for Offline e-KYC XML), `qrMaxAgeDays` (default `null`, for Secure QR codes), `maxZipBytes`, `maxXmlBytes` and two callbacks, `onVerified` and `onRejected`, for audit logging.
Build the reader once and reuse it, for example by registering it in your container.

Check which certificates the reader trusts:

```php
print_r($reader->certificates()->describe()); // subject, SHA-256 fingerprint, validity, expired
```

## 4. Test with a real file

Before going live, verify your own Offline e-KYC download once:

```php
$kyc = $reader->verifyZip('/path/to/offlineaadhaar.zip', '1234');
print_r($kyc->toArray()); // no share code, hashes or raw XML
```

The package's opt-in `composer test:real` suite does the same with shape-only assertions; see `CONTRIBUTING.md`.
If verification fails on a genuine file, please [open an issue](https://github.com/itxshakil/aadhaar-offline/issues) with the exception class and message.
**Do not attach the file.**

## Local development without a certificate

```php
$reader = OfflineKycReader::create(certificates: [], verifySignature: false);
```

Never deploy with signature checks disabled.
