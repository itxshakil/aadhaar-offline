# Contributing to Aadhaar Offline

First off, thank you for considering contributing to Aadhaar Offline! Your contributions help make this package better for everyone.

## Code of Conduct

Our Code of Conduct governs this project and everyone participating in it. By participating, you are expected to uphold this code.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check existing issues to avoid duplicates. Include as many details as possible:

* Clear and descriptive title
* Steps to reproduce the problem
* Expected vs observed behavior
* PHP and package versions
* Minimal reproducible example if possible

### Suggesting Enhancements

If you have a suggestion for the project:

* Clear, descriptive title
* Detailed explanation of the enhancement
* Why it’s useful
* Any additional context or screenshots

### Pull Requests

1. Fork the repository
2. Create a branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Run the full QA pipeline: `composer qa`
5. Commit changes: `git commit -m 'Add amazing feature'`
6. Push branch: `git push origin feature/amazing-feature`
7. Open a Pull Request

#### Development Prerequisites

* PHP 8.3 / 8.4 / 8.5
* Composer

#### Coding Standards

* Follow PSR-12 coding standards
* Write PHPUnit tests for new features
* **Never commit real Aadhaar data, Offline e-KYC files or private keys.** Use the synthetic payloads from `src/Testing/Fixtures.php`
* Maintain existing test coverage
* Document new code using PHPDoc blocks
* Keep the codebase clean and maintainable

### Development Setup

```bash
git clone https://github.com/itxshakil/aadhaar-offline.git
composer install
```

Available composer scripts:

| Command                  | Description                                      |
| ------------------------ | ------------------------------------------------ |
| `composer test`          | Run the full PHPUnit test suite                  |
| `composer test:coverage` | Run tests with HTML + Clover coverage output     |
| `composer test:real`     | Opt-in tests against your own real e-KYC file    |
| `composer analyse`       | Run PHPStan static analysis                      |
| `composer lint`          | Check code style with Pint (no changes)          |
| `composer lint:fix`      | Auto-fix code style with Rector + Pint           |
| `composer rector`        | Run Rector standalone (modernise/refactor code)  |
| `composer qa`            | Full QA pipeline: lint + analyse + tests         |

### Testing with a real file

`composer test:real` runs `tests/Real` against your own Offline e-KYC download.
Set `AADHAAR_OFFLINE_REAL_FILE`, `AADHAAR_OFFLINE_REAL_SHARE_CODE` and `AADHAAR_OFFLINE_REAL_CERTIFICATES` first, and optionally `AADHAAR_OFFLINE_REAL_MOBILE`.
For a Secure QR code, save the scanned digits to a text file and set `AADHAAR_OFFLINE_REAL_QR_FILE`.
Keep the file outside the repository; `*.zip` and `offlineaadhaar*` are gitignored as a safety net.
The tests assert shapes only, so failures never print personal data.

## Pull Request Guidelines

* Update `README.md` if the interface changes
* Update `CHANGELOG.md` via PR notes (labels are automatically mapped to changelog categories)
* The PR must work for PHP 8.3 / 8.4 / 8.5
* Include tests for new features
* Follow existing coding style
* Use clear, descriptive commit messages

### Label Guidance (for Automatic Changelog)

Release notes and the next version number come from **intent labels**, which a maintainer applies by hand:

| Label             | Effect                                          |
| ----------------- | ----------------------------------------------- |
| `breaking-change` | ⚠️ Breaking Changes, next major version         |
| `feature`         | 🚀 New Features, next minor version             |
| `security`        | 🔐 Security                                     |
| `bug`             | 🐛 Bug Fixes                                    |
| `dependencies`    | ⬆️ Dependencies (Dependabot adds it)            |
| `documentation`   | 📚 Documentation                                |
| `skip-changelog`  | Left out of the release notes                   |

Area labels (`core`, `parsing`, `verification`, `validation`, `tests`, `ci`, `tooling`, `meta`) are added automatically from the changed paths.
They are for triage only: a path says where a change is, not what it does.

## License

By contributing, your contributions are licensed under the same license as the project (MIT).

## Questions?

Don't hesitate to create an issue for any questions you might have.

---

Again, thank you for your contribution! 🚀
