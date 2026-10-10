# Releasing

## One-time repository setup

These are already applied to `itxshakil/aadhaar-offline`.
They are listed so the setup can be checked or repeated.

1. Topics: `php`, `php-library`, `aadhaar`, `aadhaar-offline`, `ekyc`, `kyc`, `uidai`, `secure-qr`, `xml-signature`, `india`, `fintech`.
2. Labels: the intent labels (`feature`, `bug`, `security`, `breaking-change`, `dependencies`, `documentation`, `skip-changelog`) and the area labels used by `.github/labeler.yml`.
   See the label table in `CONTRIBUTING.md`.
3. Private vulnerability reporting: enabled (Settings → Code security).
4. Packagist: the GitHub webhook `https://packagist.org/api/github` updates the package on every push and tag.
5. Merge settings and the `main` ruleset, with the `gh` CLI logged in as `itxshakil` with the `workflow` scope:

```bash
gh repo edit itxshakil/aadhaar-offline --enable-auto-merge --delete-branch-on-merge

gh api -X POST repos/itxshakil/aadhaar-offline/rulesets --input - <<'JSON'
{
  "name": "main",
  "target": "branch",
  "enforcement": "active",
  "conditions": { "ref_name": { "include": ["~DEFAULT_BRANCH"], "exclude": [] } },
  "bypass_actors": [
    { "actor_type": "RepositoryRole", "actor_id": 5, "bypass_mode": "always" }
  ],
  "rules": [
    { "type": "deletion" },
    { "type": "non_fast_forward" },
    { "type": "required_status_checks", "parameters": {
        "strict_required_status_checks_policy": false,
        "required_status_checks": [{ "context": "CI passed" }]
    } }
  ]
}
JSON
```

Pull requests can only merge when the `CI passed` job is green, which lets Dependabot's grouped PRs merge themselves.
Admins (actor 5) can still push straight to `main`.

## Each release

Releases are manual: nothing is published or tagged automatically.
The GitHub release notes are the record of changes; `CHANGELOG.md` is not updated automatically.

1. Merge pull requests with intent labels.
   `release-drafter` keeps a draft release up to date and picks the next version from those labels.
2. Make sure CI on `main` is green.
3. Open the draft on GitHub, check the version and notes, and publish it.
   Publishing creates the tag.
4. The `Release` workflow checks that the tag installs cleanly without dev dependencies.
5. Packagist picks up the new tag through the webhook.
   Check that it appears on https://packagist.org/packages/itxshakil/aadhaar-offline.

## Before 1.0.0

- Verify a real Secure QR code (`composer test:real` with `AADHAAR_OFFLINE_REAL_QR_FILE`) and add the result to the README status note.
- Release the Laravel package after this one, raising its `itxshakil/aadhaar-offline` constraint if it needs the new version.
- Re-check the certificate table in `docs/installation.md` against UIDAI's Data and Downloads page.
