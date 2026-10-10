# Aadhaar Offline for PHP

<p>
  <a href="https://packagist.org/packages/itxshakil/aadhaar-offline"><img src="https://img.shields.io/packagist/v/itxshakil/aadhaar-offline" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/itxshakil/aadhaar-offline"><img src="https://img.shields.io/packagist/dt/itxshakil/aadhaar-offline" alt="Total Downloads"></a>
  <a href="https://github.com/itxshakil/aadhaar-offline/actions"><img src="https://github.com/itxshakil/aadhaar-offline/actions/workflows/ci.yml/badge.svg?branch=main" alt="Tests"></a>
  <a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License"></a>
</p>

Read and verify Aadhaar Offline e-KYC files and Aadhaar Secure QR codes in any PHP application, with no framework required.
It opens the share-code-protected ZIP, **checks UIDAI's digital signature locally** against certificates you pin, and gives you a typed object with the resident's name, date of birth, gender, address and photo.
It can also confirm a mobile number or email against the hashes in the file, and it never needs the full Aadhaar number.

**Don't trust the XML, verify it.**
Many integrations accept whatever XML a KYC vendor returns, or whatever ZIP a user uploads, without checking the signature.
Anyone can edit an XML file.
This package checks the signature against UIDAI's public certificate before you rely on any of the data.

**Using Laravel?** Install [itxshakil/laravel-aadhaar-offline](https://github.com/itxshakil/laravel-aadhaar-offline).
It builds on this package and adds a facade, validation rules, Artisan commands, events, an upload form and a test fake.

> **Status: 0.x.** The test suite uses synthetic, self-signed fixtures.
> The Offline e-KYC ZIP path has also been checked against a real 2026 UIDAI download and UIDAI's published `uidai_offline_publickey_2026.cer`.
> Secure QR follows UIDAI's published layout and well-maintained open-source decoders, but has not yet been checked against a real card.
> Please [open an issue](https://github.com/itxshakil/aadhaar-offline/issues) if anything differs.

---

## Features

- 🔐 **Local signature verification** against certificates you pin. The key is never read from the document, so a forged file can't bring its own key.
- 📷 **Secure QR codes** from Aadhaar letters, PVC cards and e-Aadhaar PDFs, legacy and V2/V3/V5 layouts, including the photo.
- 🗜️ **Share-code ZIPs** (AES-256 and ZipCrypto) with zip-bomb, size and entry-count guards.
- 🧾 **Typed result**: `OfflineKyc` with name, date of birth, `Gender`, `Address`, `Photo` and generation time.
- 📱 **Mobile/email matching** with UIDAI's iterated SHA-256 scheme.
- ⏱️ **Freshness rules**, separate for downloaded XML (default 3 days) and printed QR codes (off by default).
- 🙈 **Privacy-safe by default**: only the last four digits are exposed, and `toArray()`, JSON and dumps leave out hashes, share codes and raw payloads.
- 🛡️ **XXE-hardened** XML parsing.
- 🧪 **Test helpers** that build signed synthetic payloads, so tests never need real Aadhaar data.
- 🔍 **PHPStan max level** with strict rules, and no runtime dependency beyond `robrichards/xmlseclibs`.

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | ^8.3 with `ext-dom`, `ext-openssl`, `ext-zip`, `ext-zlib` |
| UIDAI public certificate | From UIDAI's [Data and Downloads page](https://uidai.gov.in/en/data-and-download) |

---

## Quick Start

```bash
composer require itxshakil/aadhaar-offline
```

Download `uidai_offline_publickey_2026.cer` from UIDAI and check its fingerprint first; see [docs/installation.md](docs/installation.md).

```php
use Shakil\AadhaarOffline\OfflineKycReader;

$reader = OfflineKycReader::create(certificates: ['/path/to/uidai_offline_publickey_2026.cer']);

$kyc = $reader->verifyZip($_FILES['aadhaar_zip']['tmp_name'], $_POST['share_code']);

$kyc->name();                    // "Asha Verma"
$kyc->dob()?->format('Y-m-d');   // "1990-08-15"
$kyc->gender()?->label();        // "Female"
$kyc->address()->pincode;        // "110024"
$kyc->maskedAadhaar();           // "XXXX XXXX 5678"
$kyc->matchesMobile($_POST['phone']); // true / false
```

---

## Reading Payloads

Every reader returns an **unverified** `OfflineKyc`.
Call `verify()`, or use the `verify*` shortcuts.

```php
// The ZIP the resident downloaded from UIDAI, plus their 4-digit share code
$kyc = $reader->fromZip($path, '1234')->verify();

// Signed XML returned by a KYC vendor's OTP flow. Still verified locally.
$kyc = $reader->fromXml($vendorResponse['raw_xml'], shareCode: '1234')->verify();

// An XML file on disk
$kyc = $reader->fromXmlFile('/path/to/offline.xml', '1234')->verify();

// The Secure QR code: pass the scanned text, a long string of digits
$kyc = $reader->fromSecureQr($scannedDigits)->verify();

// One-step shortcuts
$kyc = $reader->verifyZip($path, '1234');
$kyc = $reader->verifyXml($xml, '1234');
$kyc = $reader->verifySecureQr($scannedDigits);
```

### What you get

| Method | Returns |
|--------|---------|
| `name()` | `?string` |
| `dob()` | `?DateTimeImmutable`, or `null` when only the year is present |
| `dateOfBirthRaw()` / `birthYear()` / `age()` | `?string` / `?int` / `?int` |
| `gender()` | `?Gender` (`Male`, `Female`, `Transgender`) |
| `address()` | `Address` with `careOf`, `house`, `street`, `landmark`, `locality`, `vtc`, `postOffice`, `subDistrict`, `district`, `state`, `country`, `pincode`, `formatted()` |
| `photo()` | `?Photo` with `toDataUri()`, `contents()`, `mimeType()`, `extension()` |
| `referenceId()` | UIDAI reference ID (last 4 digits + timestamp) |
| `lastFourDigits()` / `maskedAadhaar()` | `"5678"` / `"XXXX XXXX 5678"` |
| `generatedAt()` / `ageInDays()` / `isFresh($days)` | When UIDAI generated the payload |
| `hasMobile()` / `hasEmail()` | Whether a mobile/email hash is present |
| `source()` | `PayloadSource::Xml` or `PayloadSource::SecureQr` |
| `mobileLastFourDigits()` | Last four mobile digits, from Secure QR version 2 and later. Not in `toArray()` |
| `isVerified()` | Whether `verify()` succeeded on this instance |
| `toArray($withPhoto = false)` | Privacy-safe array |

---

## Verification

`verify()` runs two checks and throws on the first failure:

1. **Signature.**
   For XML, the document must contain exactly one enveloped signature, directly under the root, with a single reference covering the whole document (`URI=""`).
   For Secure QR codes, the 256-byte RSA signature must cover every byte before it.
   Either way, the signature is checked against **your configured certificates only**, never a key embedded in the file.
2. **Freshness.**
   The timestamp in the reference ID must be within `maxAgeDays` for XML (default 3) or `qrMaxAgeDays` for QR codes (off by default, because printed cards are old).

```php
use Shakil\AadhaarOffline\Exceptions\{InvalidSignatureException, StaleKycException};

try {
    $kyc = $reader->verifyZip($path, $shareCode);
} catch (InvalidSignatureException) {
    // Forged, edited, or signed by an untrusted key
} catch (StaleKycException) {
    // Ask the resident to download a fresh file
}
```

For audit logs, pass `onVerified` and `onRejected` callbacks to `create()`.
Log `referenceId()`, `maskedAadhaar()` and the exception class, never the payload itself.

> **How it works in detail:** [docs/verification.md](docs/verification.md)

---

## Matching Mobile and Email

The file doesn't contain the mobile number or email, only hashes.
Ask the user for their number and check it:

```php
$kyc->matchesMobile('+91 98765 43210'); // bool
$kyc->matchesEmail('asha@example.com');  // bool
```

For Offline e-KYC XML the hash is SHA-256 of `value + shareCode`, repeated *N* times, where *N* is the last digit of the Aadhaar number (0 and 1 both mean once).
Matching needs the share code, so pass it to `fromXml()` when you use vendor XML.
Legacy Secure QR codes use the same scheme without the share code; version 2 and later carry only `mobileLastFourDigits()`.

> Only trust a match **after** `verify()`. A forged file can contain any hash.

---

## Privacy

- The full Aadhaar number is **never** part of the result; only the last four digits are.
- `toArray()`, `json_encode()`, `var_dump()` and stack traces leave out the share code, hashes and raw payloads.
- Exception messages contain no personal data.
- The photo stays in memory. If you store it, use private storage and a random file name.
- Store Aadhaar numbers masked, with `maskedAadhaar()` or `Shakil\AadhaarOffline\Support\AadhaarNumber::mask()`.

For format-only checks, use `AadhaarNumber::isValid()` and `Verhoeff::validate()` from `Shakil\AadhaarOffline\Support`.

> **More:** [docs/privacy.md](docs/privacy.md)

---

## Testing

Never use real Aadhaar data in tests.
`Shakil\AadhaarOffline\Testing\Fixtures` builds signed synthetic ZIPs, XML and Secure QR codes with a key generated at runtime, and `OfflineKycFactory` builds `OfflineKyc` objects directly.

```php
$reader = OfflineKycReader::create([Fixtures::certificateFile()]);

$reader->verifySecureQr(Fixtures::secureQr(['name' => 'Ravi Kumar']));
```

> **Full testing guide:** [docs/testing.md](docs/testing.md)

---

## Error Handling

Every exception extends `Shakil\AadhaarOffline\Exceptions\AadhaarOfflineException`.

| Exception | Meaning |
|-----------|---------|
| `InvalidShareCodeException` | Share code isn't 4 digits or doesn't open the ZIP |
| `MalformedPayloadException` | Not a ZIP, wrong number of entries, too large, not well-formed XML, DOCTYPE present, or not an Offline e-KYC document. For QR codes: not a Secure QR number, corrupt, truncated, or an unsupported version |
| `InvalidSignatureException` | Missing, duplicated, edited or untrusted signature, on XML or QR |
| `StaleKycException` | Older than `maxAgeDays` (XML) or `qrMaxAgeDays` (QR) |
| `ConfigurationException` | No certificate configured, certificate unreadable, or share code missing for hash matching |

---

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md).
Report security issues privately as described in [SECURITY.md](SECURITY.md).

**Never commit real Aadhaar data, Offline e-KYC files or private keys.**
The test suite generates its own keys and synthetic payloads.

---

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).

This package is not affiliated with or endorsed by UIDAI. "Aadhaar" is used only to describe compatibility.
