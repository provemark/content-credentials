# Step 85 — c2pa-node 0.12.2 taken: the thumbnail moved (2026-11-27)

Service-side, plus a docs correction that reaches the next tag. Branch
`c2pa-node-0.12.2`. Supersedes Dependabot's #222 (0.12.1).

## Why this release, and why not #222 as it was

#222 (0.10.1 → 0.12.1) went red in two places, and in neither was the
service at fault:

- all nine `composer check` legs: SPEC-035 AC7, the engine pin. This was the
  tripwire doing its job.
- `integration (defaults|hardened|tsa-unreachable)`: one test,
  `TimestampCheckTest`, failed with `TypeError: context.toJson is not a
  function`. Its inline Node script passed a raw settings object to
  `Reader.fromAsset()`. c2pa-node 0.11.0 removed raw settings in favour of
  `Context`. `server.js` already passed a `Context` everywhere, so only the
  test broke.

0.12.1 bumps c2pa-rs to 0.92.0 and changes trust handling. 0.12.2 only reuses
native context handles per `Context`. **0.12.2** was taken. The engine it
actually ships is **c2pa-rs 0.92.2**, read from `org.contentauth.c2pa_rs` in a
signed manifest.

## Measured

- **The time-stamp.** `php bin/e2e.php` against the rebuilt service with
  trust settings: `validationState: Trusted`, `hasTimestamp: true`,
  `bin/verify.sh` clean with the Article 50 mark. With
  `CONTENTAUTH_TSA_URL=http://127.0.0.1:9/tsa` and
  `CC_TSA_EXPECT_UNREACHABLE=1`, the time-stamp tests pass (5 passed,
  1 skipped), and the audit line shows the engine failing
  (`error sending request for url (http://127.0.0.1:9/tsa)`).
- **Trust after the removal of the flat `trust.trust_anchors`.** Step 79 only
  reasoned that the `Context` path would survive c2pa-rs 0.92. Now measured:
  trusted with the test anchors. Integration **231 passed / 24 skipped** with
  trust on, **230 / 25** with trust off.
- **SPEC-035 re-audit** (c2patool 0.27.22 `--detailed`):
  - `specVersion` `2.4.0`;
  - `c2pa.actions.v2` in `created_assertions`, and `/v1/read` returns it
    with `created: true`;
  - **`c2pa.thumbnail.claim` now in `created_assertions`**, and
    `gathered_assertions` is empty. On 0.10.1 it was still in `gathered`.

  c2pa-rs #2106 is still open, and the upstream change that moved the
  thumbnail was not found.
- `composer check` green after the pin: 545 passed / 7 skipped, PHPStan 0.
  `npm audit --omit=dev`: 0.
- `readers disagree on: validationState` with trust on: the same line as in
  Step 79. Configuration, not engine.

## Decided

SPEC-036 AC5 required the docs to record the thumbnail exception. SPEC-036
Amendment 1 (approved 2026-11-27) keeps the note in the past tense:

- it says up to which engine the exception held, and with which it ended;
- the AC5 test now checks for the phrase "ended with c2pa-rs 0.92.2", and
  failed against the old note (seen red);
- primer §1 and §11 are corrected the same way.

The note stays because a service that has not been rebuilt, and assets signed
earlier, still carry the old placement.

## Left

- A tag delivers the service bump to nobody: `service/` is `export-ignore`d.
  The docs half (`docs/readers.md`, the primer) reaches the next tag and sits
  under `[Unreleased]` until then.
- The Drupal module needs nothing (it reads through `ReaderInterface`).
