# Security Policy

## Supported Versions

Only the latest minor release of 0.x receives security fixes until 1.0.0.

| Version | Supported          |
| ------- | ------------------ |
| 0.x     | :white_check_mark: |

## Reporting a Vulnerability

This package decides whether identity documents can be trusted, so a signature bypass is treated as critical.

**Never include real Aadhaar files, numbers or personal data in a report.** Reproduce with synthetic fixtures instead (see `Shakil\AadhaarOffline\Testing\Fixtures`).


**Please do not report security vulnerabilities through public GitHub issues.**

Report it privately through [GitHub's private vulnerability reporting](https://github.com/itxshakil/aadhaar-offline/security/advisories/new).
You should receive a response within 48 hours.
If you can't use GitHub, contact the maintainer through the details on the [GitHub profile](https://github.com/itxshakil).

Please include as much of the following information as possible to help us better understand and resolve the issue:

- The type of issue (e.g. signature bypass, XXE, data exposure)
- Full paths of source file(s) related to the manifestation of the issue
- The location of the affected source code (tag/branch/commit or direct URL)
- Any special configuration required to reproduce the issue
- Step-by-step instructions to reproduce the issue
- Proof-of-concept or exploit code (if possible)
- Impact of the issue, including how an attacker might exploit it

This information will help us triage your report more quickly.

## Disclosure Policy

- I will acknowledge receipt of your vulnerability report within 48 hours.
- I will confirm the vulnerability and determine its impact within 7 days.
- I will release a fix as soon as possible, depending on complexity.
- I will publicly disclose the vulnerability after a fix is available.
