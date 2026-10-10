# Privacy and storage

Aadhaar data is personal data protected by the Aadhaar Act, its regulations, the Digital Personal Data Protection Act 2023 and sector rules (RBI, SEBI, IRDAI…). This package can't make you compliant on its own, but its defaults try not to make things worse.

## What the package does

| Concern | Default |
|---------|---------|
| Full Aadhaar number | Never part of the result. Only the last four digits are, from `referenceId`. |
| Share code | Kept in memory for hash matching. Hidden from `toArray()`, JSON, `dd()`, and stack traces (`#[SensitiveParameter]`). |
| Mobile/email hashes | Hidden from `toArray()`, JSON and `dd()`. |
| Raw XML | Available through `rawXml()` only. Never serialised. |
| Photo | In memory. `toArray()` leaves it out unless you ask. |
| Callbacks | `onVerified` / `onRejected` receive the payload; log only `referenceId()`, `maskedAadhaar()` and the exception class. |
| Exceptions | Messages contain no personal data. |

## What you should do

- **Don't store the original ZIP or XML** unless a regulation requires it. If it does, encrypt it at rest and restrict access.
- **Don't name files or folders after the Aadhaar number.** A path like `docs/{aadhaarNumber}/{aadhaarNumber}.xml` leaks the number to anyone who can list the directory, and is a common mistake.
- **Never put Aadhaar files under `public/`.**
- **Store numbers masked**, with `maskedAadhaar()` or `AadhaarNumber::mask()`.
  If you really must keep full numbers, UIDAI requires them in an Aadhaar Data Vault (encrypted, with reference tokens).
- **Log reference IDs, not data.** Use the `onVerified` / `onRejected` callbacks for audit logs.
- **Store photos privately**, under a random file name, and delete them when you no longer need them.
