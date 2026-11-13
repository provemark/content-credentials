# Step 80 — The verifier range moves to 0.11 (SPEC-042 Amendment 12), 0.20.1 prepared

*2026-11-13.* Maurice: "bereid library 0.20.1 voor", after the verifier
released 0.11.0 the same day. The goal is that the Drupal module can take
verifier 0.11 without a change of its own: its `^0.20` accepts a 0.20.1,
not a 0.21.0.

## What changes

- **`composer.json`.** `require-dev` `"provemark/c2pa-verifier": "^0.11"`,
  `conflict` `"<0.11 || >=0.12"`. One minor, as before:
  `VerifierVersionRangeTest`'s shape test requires that form. A two-minor
  range (`<0.10 || >=0.12`) was considered and not used, because that test
  is the rule. A host that asks for verifier `^0.10` still resolves:
  Composer then picks this package's 0.20.0.
- **`VerifierVersionRangeTest`.** The pin "tests against the verifier that
  verifies the box hash in a JPEG" replaces Amendment 11's pin. It keeps
  both groups, SPEC-042 and SPEC-046.
- **`specs/SPEC-042`.** Amendment 12, approved by Maurice the same day
  ("goedgekeurd, maak de PR's en tag maar"). The traceability rows of SPEC-042 AC9 and
  SPEC-046 AC6 name the new pin.
- **`CHANGELOG.md`.** The range entry under `[Unreleased]`; the release PR
  turns it into `[0.20.1] - 2026-11-13`, together with the entries already
  there under their own headings ("in the signing service, not in a tag"):
  - c2pa-node 0.10.1;
  - the `Context`;
  - `ftyp`;
  - the actions assertion;
  - the lockfile.

  The compare link for the release is added.

## Measured

- **Verifier 0.11.0 installed** (`composer update -W
  provemark/c2pa-verifier`), before any other change. `composer check`:
  Pint, PHPStan and Deptrac clean; 529 passed, 7 skipped, 1 failed. The
  failure is Amendment 11's pin, as it should be. With the new pin,
  `composer check` exits 0: 530 passed, 7 skipped, 18 deprecated.
- **The integration tests that use the verifier**, against the service
  (colima, `RATE_LIMIT_REQUESTS=1000 docker compose up -d`):
  `VerifierReaderEquivalenceTest`, `IngredientEquivalenceTest`,
  `RemoteManifestFetchTest`. 52 passed, 4 skipped, as with 0.10.0.
- **`bin/spec-check.php`:** 3 errors, 23 warnings, 0 unresolved, the same
  as on `main` before this step. The three are SPEC-014 AC9–AC11 rows
  without a Behavior entry. They come from SPEC-014 Amendment 1 (step 79)
  and are not touched here. The two errors this step first caused (the
  old test name in the traceability rows) are fixed.

## Released

All on Maurice's word ("goedgekeurd, maak de PR's en tag maar"), on
2026-11-13:

- **Amendment 12** approved.
- **#209**, *SPEC-042 Amendment 12: the verifier range at 0.11*: CI 20/20,
  squash-merged as `b0caa54`.
- **#210**, *Release 0.20.1: the verifier range at 0.11* (the CHANGELOG
  heading and links only): CI 20/20, merged as `e602dfc`.
- **Tag `v0.20.1`**, annotated, on `e602dfc`. GitHub Release `v0.20.1`,
  with the CHANGELOG section as its text.
- **Packagist** lists `v0.20.1` with `conflict` `<0.11 || >=0.12`.
- **No branch-alias PR.** `dev-main` stays `0.20-dev`.

## Afterwards, in the Drupal module

- **A fresh `drupal/recommended-project`** with
  `provemark/c2pa-verifier:^0.11` resolved this package's 0.20.1 and
  verifier 0.11.0. The module's release smoke test passed on its
  1.0.0-alpha6 with no change to the module.
- **The module asks for `^0.11`** (its #3637949, `8b1b2cd` on `1.0.x`,
  its SPEC-013 Amendment 7 and SPEC-016 Amendment 6, CI 9/9). The
  install sentence, the missing-package message, the README,
  `require-dev` and the project page changed. Its requirement on this
  package stays `^0.20`. A site that asks for verifier `^0.10` keeps
  0.20.0.
