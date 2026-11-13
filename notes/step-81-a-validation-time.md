# Step 81 — A validation time for the verifier read (SPEC-047)

*2026-11-13.* Maurice: "schrijf een spec voor de validatietijd in de
library", then "goedgekeurd, open vragen zoals voorgesteld". For the Drupal
module's conformance harness (verifier steps 535–540): the C2PA Conformance
Program gives a validation time per asset, and the harness should take the
path an upload takes.

## Measured before the spec (verifier 0.11.1)

- `spec046-cs-parent.png` under its throw-away root, no time-stamp:
  `Trusted` at 2026-11-13 and at 2030-01-01; `Invalid` with
  `signingCredential.expired` ("checked at the given validation time (no
  timestamp)") and `signingCredential.untrusted` at 2037-01-01 and at
  2020-01-01.
- A time with `+05:00` is written in crJSON in UTC.
- The verifier's API accepts a UTC year outside 0001–9999 and writes it as
  it is: `@253402300800` gives `validationTime` `10000-01-01T00:00:00Z`. Its
  command line refuses it (verifier SPEC-073 amendment 2).

## Built

- `readVerified(Asset $asset, ?\DateTimeInterface $validationTime = null)`,
  passed to `Verifier::verify()`.
- `InvalidValidationTimeException` (an `\InvalidArgumentException` and a
  `ContentCredentialsException`) for a UTC year outside 0001–9999, thrown
  before the asset is read.
- `docs/readers.md`, `CHANGELOG.md` *Unreleased* (*Added*).

The open questions, as proposed: per call, not per reader; refuse here and
name the verifier's API as a candidate (recorded on the verifier's side);
the release is 0.21.0, and the module moves to `^0.21` in the issue that
builds its harness.

## Verified

- Red first: 8 of 9, because PHP ignores an extra argument to a
  user function; AC4 green, as a guard.
- Five mutations: four red (time not passed, no check, upper edge 10000,
  check after mapping). One survived, the check after `verify()` but before
  mapping, because the verifier answers a truncated file with
  `general.error` and the library refuses it only in the mapping. Recorded
  in the spec's Traceability; `Verifier` is final, so "not called at all" is
  not observable here.
- `composer check`: exit 0, 539 passed, 7 skipped, 18 deprecated (as in
  step 80).

## Left

Each on Maurice's word: commit on `spec-047-validation-time`, a PR, CI,
merge; then release 0.21.0.
