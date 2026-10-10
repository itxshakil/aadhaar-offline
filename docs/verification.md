# How verification works

## The payload

An Offline e-KYC download is a ZIP protected with a 4-digit share code the resident chooses. Inside is one XML file:

```xml
<OfflinePaperlessKyc referenceId="5678 + YYYYMMDDHHMMSSsss">
  <UidData>
    <Poi name="…" dob="DD-MM-YYYY" gender="M|F|T" m="mobile hash" e="email hash"/>
    <Poa careof="…" house="…" street="…" landmark="…" loc="…" vtc="…" po="…"
         subdist="…" dist="…" state="…" country="…" pc="…"/>
    <Pht>base64 photo</Pht>
  </UidData>
  <Signature xmlns="http://www.w3.org/2000/09/xmldsig#">…</Signature>
</OfflinePaperlessKyc>
```

The `referenceId` is the last four digits of the Aadhaar number followed by the generation timestamp (IST). The full Aadhaar number is not in the file.

## Reading (`fromZip`, `fromXml`)

- The share code must be exactly 4 digits.
- The ZIP must be under `limits.max_zip_bytes` and contain **exactly one** `.xml` entry, not in a subdirectory, whose uncompressed size is under `limits.max_xml_bytes`.
- A wrong share code gives `InvalidShareCodeException`, for both AES-256 and legacy ZipCrypto archives.
- The XML is loaded with network access disabled. Any `DOCTYPE` or `ENTITY` declaration is refused, which blocks XXE.
- The root must be `OfflinePaperlessKyc` with a valid `referenceId`, and `UidData/Poi` must exist.

Reading does **not** check authenticity.

## Signature (`verify()`)

Using [`robrichards/xmlseclibs`](https://github.com/robrichards/xmlseclibs) 4.x:

1. Exactly one `ds:Signature` element in the whole document, and it must be a direct child of the root.
2. Exactly one `Reference`, with `URI=""`, so the signature covers the entire document (minus itself, via the enveloped-signature transform). This rules out signature-wrapping attacks, where a valid signature covers one part of a document while the parser reads another.
3. `SignatureMethod` must be in `aadhaar-offline.algorithms` (rsa-sha256 and rsa-sha1 by default). Digests must be SHA-256 or SHA-1.
4. The reference digest is recomputed and compared.
5. The signature is checked against each certificate in `aadhaar-offline.certificates`. Any `KeyInfo` / `X509Certificate` in the file is **ignored**: a forger can embed their own certificate, so only your pinned certificates count.

## Freshness

`generatedAt()` comes from the reference ID. If `max_age_days` is set, payloads older than that fail with `StaleKycException`. RBI's KYC guidance is commonly read as requiring offline verification files to be no older than 3 days. Confirm what applies to you.

## Hash matching

```
hash = value + shareCode
repeat max(1, lastDigitOfAadhaar) times:
    hash = sha256_hex(hash)
```

`lastDigitOfAadhaar` is the 4th character of the reference ID. Mobiles are normalised to 10 digits (`+91`, `91`, `0` prefixes and separators are removed). Emails are tried as given (trimmed) and lower-cased, because UIDAI doesn't document the case it hashes.

## Not yet supported

- Aadhaar Secure QR codes (planned for 0.2).
- Fetching XML from a vendor's OTP API. Use your vendor's SDK, then pass the XML to `fromXml()` so it is still verified locally.
