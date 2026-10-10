# Testing

Never use real Aadhaar data in tests.
The package ships two helpers in `Shakil\AadhaarOffline\Testing` so you don't have to.

Using Laravel? The Laravel package adds `AadhaarOffline::fake()` on top of these; see its testing guide.

## Payload objects without signatures

`OfflineKycFactory` builds an `OfflineKyc` directly, for code that only consumes the data:

```php
use Shakil\AadhaarOffline\Testing\OfflineKycFactory;

$kyc = OfflineKycFactory::make(['name' => 'Asha Verma', 'mobile' => '9876543210', 'photo' => null]);
```

Supported attributes: `last_four`, `generated_at`, `name`, `dob`, `gender`, `address`, `photo`, `mobile`, `email` and `share_code`.
Mobile and email are hashed for you, so `matchesMobile()` works as it would on a real payload.

## Signed payloads for the real verification path

`Fixtures` builds payloads in the exact UIDAI shapes, signed with a key generated at runtime:

```php
use Shakil\AadhaarOffline\OfflineKycReader;
use Shakil\AadhaarOffline\Testing\Fixtures;

$reader = OfflineKycReader::create([Fixtures::certificateFile()]); // trust the test certificate

$reader->verifyZip(Fixtures::zip(Fixtures::signedXml()), Fixtures::SHARE_CODE); // share-code ZIP
$reader->verifyXml(Fixtures::signedXml(['name' => 'Ravi Kumar']));             // signed XML
$reader->verifySecureQr(Fixtures::secureQr());                                 // Secure QR (V2 layout)
$reader->verifySecureQr(Fixtures::secureQr(version: null));                    // legacy QR with hashes
```

Pass `identity: 'attacker'` to sign with a different key and test that forged payloads are rejected.
`generated_at` lets you test the age limits.

Temporary files go to a per-process directory that is removed when the process exits.
Trust the test certificate only in tests, never in production configuration.

**Never commit a real Offline e-KYC file or QR code**, even your own.
