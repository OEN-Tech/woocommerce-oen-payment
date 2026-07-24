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

Helper/E2E scripts must read environment endpoints, keys, and credentials from **environment
variables** — never hard-code internal defaults.
