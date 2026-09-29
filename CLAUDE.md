# Contributor & AI Assistant Notes

## ⚠️ This is a PUBLIC repository

Anything committed here — including code, comments, commit messages, and pull-request
titles/descriptions — is publicly visible and effectively permanent (edit history and
clones persist even after deletion). **Never** include internal-only information:

- **Credentials or secrets** of any kind, including test/sandbox keys and basic-auth pairs.
- **Internal hostnames, URLs, or environment endpoints** (dev/qa/staging internal domains).
- **Cloud resource identifiers** — account IDs, database/table names, function names, ARNs.
- **Internal issue/ticket IDs** and internal project references.
- **Backend implementation details** — internal service/class names, internal architecture,
  or known internal gaps/defects.
- **Deployment or environment state** (what is or isn't deployed where).

Keep everything here scoped to **the plugin itself and its public integration surface**.
Internal analysis, findings, credentials, and tickets belong in the internal tracker — link
to them from internal channels, never from this repo.

## Test evidence

Test artifacts (screenshots, recordings, network/HAR logs, DB dumps) are git-ignored under
`tests/evidence/` and must **not** be committed — they routinely contain the internal details
listed above.

## Scripts

Helper scripts committed here must read endpoints, keys, and credentials from **environment
variables** — never hard-code defaults. A script whose defaults only make sense inside one
organisation's network does not belong in this repository at all; keep that tooling internal.

## Test layers

- `tests/run-unit.sh` — contract/unit harnesses. Self-contained: no network, no Docker.
- `tests/runtime/` — dockerised WordPress runtime harness driven by a local API stub
  (`api-stub-router.php`) that mirrors the real response shapes. Self-contained.

Both layers must stay runnable by anyone who clones this repository. Harnesses that require
private endpoints, credentials, or a specific operator's machine are kept outside this repo.

## Releasing

A release is the tag `vX.Y.Z` on `main` plus its GitHub Release. Every release PR updates all
of the following together; a release that misses one ships wrong information to merchants.

1. **Version** — `Version:` in the header of `woocommerce-oen-payment.php`, the
   `OEN_PAYMENT_VERSION` constant in the same file, `Stable tag:` in `readme.txt`, and the example
   folder name `woocommerce-oen-payment-X.Y.Z` in `README.md` 安裝 and `docs/setup-guide.md` 步驟 2.
2. **Compatibility** — `WC tested up to:` (plugin header and `readme.txt`), `Tested up to:`
   (`readme.txt`), and the tested-versions sentence in `README.md` 系統需求. Raise them only to
   versions you actually tested this release on. When a minimum changes, update `Requires at least:`,
   `Requires PHP:` and `WC requires at least:` in the plugin header and `readme.txt`, and the
   `README.md` 系統需求 table, together.
3. **Changelog** — one entry for the new version in `CHANGELOG.md` (Traditional Chinese) and the
   same items in `readme.txt` › `== Changelog ==` (English). Write what changed for merchants and
   buyers; no internal references.
4. **Translations** — when user-facing strings change, regenerate
   `languages/woocommerce-oen-payment.pot` with
   `wp i18n make-pot . languages/woocommerce-oen-payment.pot --exclude=tests,docs --headers='{"Report-Msgid-Bugs-To":"https://github.com/OEN-Tech/woocommerce-oen-payment/issues"}'`,
   update and compile `languages/woocommerce-oen-payment-zh_TW.po` / `.mo`, and set
   `Project-Id-Version` to the new version. Then re-check the UI strings quoted in the docs.
5. **Docs** — if the release changes behaviour or a screen described in `README.md`,
   `docs/setup-guide.md`, or `docs/faq.md`, update the text and retake the affected screenshots in
   `docs/images/` in the same PR. When you retake screenshots, also update the
   screenshot-environment line in `docs/setup-guide.md` (including the plugin version).
6. **Release archive** — merchants install GitHub's "Source code (zip)". `.gitattributes` keeps
   `docs/`, `tests/` and repository-only files out of it. Add any new file that the plugin does not
   need at runtime there, and check the result with `git archive HEAD | tar -t`.
7. **GitHub Release** — after tagging, publish the release notes from the `CHANGELOG.md` entry.

`README.md` and `docs/` describe the latest release. Behaviour that exists only on `main` is not
documented as available until it is released.

## Documentation

- `README.md` is ordered for merchants first (「使用外掛」), then developers (「開發者參考」).
  Keep merchant sections free of code identifiers; put option keys, meta keys, hooks, and field
  names in the developer half.
- `docs/setup-guide.md` (step-by-step with screenshots) and `docs/faq.md` are merchant-facing.
  Write them in Traditional Chinese with technical terms in English, in short sentences.
- Screenshots in `docs/images/` use demo data only: no real merchant, person, domain, transaction,
  session, webhook or refund identifier, payment code, key, IP address, or internal host. Mask
  anything else before committing.
