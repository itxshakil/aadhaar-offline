# Changelog

All notable changes to `aadhaar-offline` are documented in this file.
The [GitHub releases](https://github.com/itxshakil/aadhaar-offline/releases) list every change.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-10

First release.
Extracted from [itxshakil/laravel-aadhaar-offline](https://github.com/itxshakil/laravel-aadhaar-offline), which now builds on this package.
The Offline e-KYC ZIP path was checked against a real 2026 UIDAI download and UIDAI's published `uidai_offline_publickey_2026.cer`.

### Added

- **`OfflineKycReader`**: `fromZip()`, `fromXml()`, `fromXmlFile()` and `fromSecureQr()`, the `verify*` shortcuts, and `create()` to build a reader in one call without a framework.
- **Share-code ZIP reader**: AES-256 and ZipCrypto support, exactly-one-XML-entry rule, size and zip-bomb limits.
- **Hardened XML parser**: DOCTYPE/ENTITY declarations refused, network access disabled.
- **Local signature verification** with `robrichards/xmlseclibs` 4.x: single enveloped signature under the root, single whole-document reference, algorithm allowlist, and verification only against pinned certificates (PEM or DER, several allowed for rotation).
- **Secure QR codes** from Aadhaar letters, PVC cards and e-Aadhaar PDFs, in the legacy and V2/V3/V5 layouts, verified with SHA256withRSA against the same certificates.
- **Freshness checks**, separate for Offline e-KYC XML (default 3 days) and Secure QR codes (off by default).
- **`OfflineKyc` value object** with name, date of birth (full or year-only), `Gender` enum, `Address`, `Photo`, reference ID, masked Aadhaar and generation time.
  Privacy-safe `toArray()`, JSON and `__debugInfo()`.
- **Mobile/email matching** using UIDAI's iterated SHA-256 scheme.
- **Aadhaar number helpers**: `AadhaarNumber` (validation and masking) and `Verhoeff`.
- **`onVerified` / `onRejected` callbacks** for audit logging.
- **Testing helpers**: `Testing\Fixtures` builds signed synthetic ZIPs, XML and Secure QR codes, and `Testing\OfflineKycFactory` builds payload objects.

[Unreleased]: https://github.com/itxshakil/aadhaar-offline/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/itxshakil/aadhaar-offline/releases/tag/v0.1.0
