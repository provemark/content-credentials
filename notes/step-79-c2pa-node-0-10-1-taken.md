# Step 79 — c2pa-node 0.10.1 taken: the time-stamp is back (2026-11-11)

Service-side. Closes the third watch item (Step 67) and the c2pa-js#244 block
(Steps 74 and 76). Branch `c2pa-node-0.10`: the WIP of
`spec-014-am1-context` rebased onto `main` (no conflicts; the read-back
already used `builderContext`, so there was no `NO_REMOTE_FETCH` left to
replace), then the bump.

## Which release

c2pa-js #251 (the fix for #244) was merged on 2026-11-03 and first released
in **c2pa-node 0.9.12** the same day. 0.10.0 (2026-11-04) adds
`encodeSignerPayload`; 0.10.1 (2026-11-10) only moves `c2pa-utilities`. All
three pin the workspace engine at **c2pa-rs `=0.91.10`** (read from the
repository's `Cargo.toml` at each tag), so **0.10.1** was taken: same engine,
newest release. Dependabot's #206 (0.9.11, without the fix) is superseded.

## Measured

- **The time-stamp.** `php bin/e2e.php` against the rebuilt service:
  `hasTimestamp: true`, SPEC-007 AC4 ✓, `bin/verify.sh` trusted with the
  Article 50 mark. With `CONTENTAUTH_TSA_URL=http://127.0.0.1:9/tsa` and
  `CC_TSA_EXPECT_UNREACHABLE=1`, `TimestampFailsClosedTest` 2/2, and the
  audit line shows **the engine** failing (`error sending request for url
  (http://127.0.0.1:9/tsa)`), not only SPEC-007 Am. 1's read-back. So #251
  does what it says.
- **SPEC-035 re-audit** (c2patool 0.27.22 on a file the service signed):
  `specVersion` `2.4.0` in `claim_generator_info` beside
  `org.contentauth.c2pa_rs` `0.91.10`; `c2pa.actions.v2` in
  `created_assertions` and read back `created: true`;
  `c2pa.thumbnail.claim` in `gathered_assertions`. Then the AC7 pin moved
  to `0.10.1`.
- **Trust on** (`CONTENTAUTH_TRUST_SETTINGS` set): e2e `isTrusted: true`.
  It also printed `readers disagree on: validationState` (SPEC-019 AC2).
  The same run against a `main` build (0.9.5) prints the same line, so it
  predates this bump: the service has anchors and the in-process reader has
  none, so `Trusted` against `Valid` is configuration, not engine. With trust
  off the two readers agree.
- **SPEC-039 AC5 contradicted.** `fixture.mov` without its `ftyp` box: 0.90.22
  refused (`type is unsupported`, 500); 0.91.10 signs it (200) into a file that
  starts with a `uuid` box and still has no `ftyp`. c2patool 0.28.6 reads it
  `Valid` (`assertion.bmffHash.match`); c2patool 0.27.22 (`expected box type
  "ftyp" at offset 4, found uuid`) and `provemark/c2pa-verifier` 0.10.0+6
  (`unsupported file type`) cannot open it. The parent case signed too.
  Maurice chose to refuse it: SPEC-039 Amendment 1, the service answers 400
  `… has no ftyp box`. Both new tests red (200) before, 15/15 after.
- `composer check` green after the pin (530 passed / 7 skipped); the
  integration group against the final build **230 passed / 25 skipped** (the
  first run, before Amendment 1: 228 passed, 1 failed — the old AC5 test);
  `npm audit --omit=dev` 0.

## Reasoned

- c2pa-rs 0.92 (expected mid-November) removes the flat
  `trust.trust_anchors`; the service now passes trust through a `Context`,
  which emits `anchors[]`. Not measured on 0.92.
- The `Valid` verdict c2patool 0.28.6 gives the ftyp-less file is not a reason
  to accept it: the package's own pure-PHP reader is one of the readers it
  fails, and the engine refused the same input one release earlier.

## Left

- A tag delivers none of this: `service/` is `export-ignore`d. The CHANGELOG
  entries sit under `[Unreleased]`, "in the signing service, not in a tag";
  the `docs/production.md` paragraph does reach the next tag.
- The Drupal module needs nothing (it reads through `ReaderInterface`).
