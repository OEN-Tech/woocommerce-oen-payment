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

## Static analysis and CI

`.github/workflows/ci.yml` runs on every pull request and on pushes to `main`:

- `unit` — a PHP syntax check and `tests/run-unit.sh` on PHP 8.1 to 8.5 (8.1 is the minimum in
  the plugin header). `tests/RepositoryChecksTest.php` is part of it; see Releasing below.
- `static-analysis` — PHPStan at level 5 (`phpstan.neon.dist`, accepted findings in
  `phpstan-baseline.neon`) and PHP_CodeSniffer (`phpcs.xml.dist`: WordPress-Extra without the
  formatting sniffs this code base does not follow, plus PHP 8.1+ compatibility).
- `runtime` — the dockerised harness in `tests/runtime/`.

Run the static checks locally with `composer install`, then `composer analyse` and `composer lint`.
`composer.lock` is committed so CI and local runs use the same tool versions; change it only in a
pull request that is about the tools. When a finding is intentional, silence it as narrowly as
possible and say why: a scoped, commented entry in the baseline or the config file, or an inline
`phpcs:ignore` with a reason. Do not widen an exclusion to make a check pass.

Every PHP file in `includes/` and the main plugin file declares `strict_types=1`, and CI fails when
one does not. The declaration applies to calls made from that file, so a value that WordPress
returns as `mixed` (an option, request input, `file_get_contents()`) must be cast before it goes
into a typed parameter of a plugin class.

## Releasing

A release is the tag `vX.Y.Z` on `main` plus its GitHub Release. Every release PR updates all
of the following together; a release that misses one ships wrong information to merchants.

### Checked by CI

`tests/RepositoryChecksTest.php` runs in the `unit` job and fails the pull request when:

- `Version:` in the header of `woocommerce-oen-payment.php`, the `OEN_PAYMENT_VERSION` constant in the
  same file and `Stable tag:` in `readme.txt` are not the same version, or the example folder name
  `woocommerce-oen-payment-X.Y.Z` in `README.md` 安裝 and `docs/setup-guide.md` 步驟 2 is not that
  version.
- `Requires at least:`, `Requires PHP:`, `WC requires at least:` or `WC tested up to:` differ between
  the plugin header and `readme.txt`.
- `CHANGELOG.md` or `readme.txt` › `== Changelog ==` has no entry for the current version.
- `Project-Id-Version` in `languages/woocommerce-oen-payment.pot` or
  `languages/woocommerce-oen-payment-zh_TW.po` is not the current version.
- A tracked file the plugin does not need at runtime is not listed in `.gitattributes`, so it would
  ship in the "Source code (zip)" that merchants install. List it there with `export-ignore`; a file
  the plugin really needs at runtime goes on the list in the test. By hand, the same check is
  `git archive HEAD | tar -t`.

### Still manual, in every release PR

1. **Compatibility** — raise `WC tested up to:` (plugin header and `readme.txt`), `Tested up to:`
   (`readme.txt`), and the tested-versions sentence in `README.md` 系統需求 only to versions you
   actually tested this release on. When a minimum changes, update `Requires at least:`,
   `Requires PHP:` and `WC requires at least:` in the plugin header and `readme.txt`, and the
   `README.md` 系統需求 table, together. CI only checks that the header and `readme.txt` agree.
2. **Changelog** — write one entry for the new version in `CHANGELOG.md` (Traditional Chinese) and the
   same items in `readme.txt` › `== Changelog ==` (English). Write what changed for merchants and
   buyers; no internal references.
3. **Translations** — when user-facing strings change, regenerate
   `languages/woocommerce-oen-payment.pot` with
   `wp i18n make-pot . languages/woocommerce-oen-payment.pot --exclude=tests,docs --headers='{"Report-Msgid-Bugs-To":"https://github.com/OEN-Tech/woocommerce-oen-payment/issues"}'`,
   update and compile `languages/woocommerce-oen-payment-zh_TW.po` / `.mo`, and set
   `Project-Id-Version` to the new version. Then re-check the UI strings quoted in the docs.
4. **Docs** — if the release changes behaviour or a screen described in `README.md`,
   `docs/setup-guide.md`, or `docs/faq.md`, update the text and retake the affected screenshots in
   `docs/images/` in the same PR. When you retake screenshots, also update the
   screenshot-environment line in `docs/setup-guide.md` (including the plugin version).
5. **GitHub Release** — after tagging, publish the release notes from the `CHANGELOG.md` entry.

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
