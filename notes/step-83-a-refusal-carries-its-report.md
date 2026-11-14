# Step 83 — A refusal carries the verifier's report (SPEC-046 Amendment 1)

*2026-11-14.* Asked by the Drupal module's SPEC-046, *A conformance
harness*, open question 1 (Maurice: "goedgekeurd, open vragen zoals
voorgesteld", for the module spec and then for this amendment).

## Why

Measured with 0.21.0 and verifier 0.11.2: where the verifier refuses a
store, `readVerified()` threw `ReadFailedException` and dropped the
verifier's report. Three of the verifier's fixtures show it
(`avi/lbox-differs.avi`, `jumbf/child-overruns.png`,
`absence/created-empty.png`). The verifier writes crJSON for each, with
the failure at document level (its SPEC-075 amendment 4, 0.11.1). The
module's harness reads through this package, so it had nothing to write
for those files.

## What changed

- `VerifierRefusalException extends ReadFailedException`, carrying
  `public readonly VerificationReport $verification`. It is thrown where
  `verify()` returned a report and `VerifierOutcome::toManifestReport()`
  refused it, with the mapping's message word for word.
- `ReadFailedException` is no longer `final`.
- Every other refusal stays a plain `ReadFailedException`. That covers a
  refused media type, no temporary stream and an exception out of
  `verify()`.
- Fixture `spec046-child-overruns.png`, copied from verifier v0.11.2, is
  documented in `tests/Fixtures/spec046-README.md`.
- `docs/readers.md` and the CHANGELOG (*Unreleased*: *Added*, *Changed*)
  are updated.

## Measured

- Tests red first: 4 of 6 new cases. TIFF and SVG were green, as guards.
- Mutations, each red: the refusal rethrown without its report (4), the
  message changed (2).
- `composer check`: exit 0, 545 passed, 7 skipped, 18 deprecated (the same
  18). spec-check: 0 errors, 0 unresolved.
- The assertion count is not a measure here: the same 539 tests gave 6,773
  and 7,500 assertions in two runs on 2026-11-13.

## Left

Each on Maurice's word: commit, PR and merge; release 0.22.0 (open
question 3); then the module's harness asks for `^0.22`.
