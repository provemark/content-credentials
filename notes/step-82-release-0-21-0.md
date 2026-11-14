# Step 82 — 0.21.0 prepared and released

*2026-11-14.* Maurice: "merge PR #213 en bereid library 0.21.0 voor". A
minor, as SPEC-047 open question 3 settled: one public class
(`InvalidValidationTimeException`) and one optional parameter on
`readVerified()`, nothing broken.

## Done

- PR #213 (SPEC-047) squash-merged as `402be51`; its 20 checks were green.
- Branch `release-0.21.0`:
  - `CHANGELOG.md`: *Unreleased* becomes **0.21.0 — 2026-11-14**, with an
    introduction; the compare links move on.
  - SPEC-047's Traceability: the re-measurement below.

## Measured

- **Against verifier 0.11.2** (released 2026-11-13, its SPEC-073
  amendment 3): `composer update provemark/c2pa-verifier` locally from
  0.11.0 to 0.11.2, then `composer check`: exit 0, 539 passed, 7 skipped,
  18 deprecated (the same 18 as step 80). spec-check: 0 errors, 23
  warnings, 0 unresolved.
- **SPEC-047's surviving mutation** (the time checked after `verify()`):
  on 0.11.2 it fails 2 of 9, because the verifier now refuses the time
  itself and the library maps that to `ReadFailedException`. On 0.11.0
  and 0.11.1 it survives, as step 81 recorded. The library's own check
  stays, since `^0.11` still allows those two.

## Released

All on Maurice's word, on 2026-11-14 ("commit en push maar"; "merge #214
en tag en release v0.21.0 maar"; recorded at "leg de 0.21.0-release
vast"):

- **#214**, *Release 0.21.0: a validation time for the verifier read*:
  CI 20/20, squash-merged as `a155ddd`.
- **CI on `main`** at `a155ddd` (run 43573074843): 20 of 20 jobs `success`.
- **Tag `v0.21.0`**, annotated, on `a155ddd`. GitHub Release `v0.21.0`,
  with the CHANGELOG section as its text:
  https://github.com/provemark/content-credentials/releases/tag/v0.21.0
- **Packagist** lists `v0.21.0`, source `a155ddd`.
- **A fresh install** in an empty directory of
  `provemark/content-credentials:^0.21` with `provemark/c2pa-verifier:^0.11`
  resolved 0.21.0 and verifier 0.11.2. The package holds 74 files, equal to
  the tag's `git archive`. On `spec046-cs-parent.png`:
  - `readVerified()` at `@253402300800` throws
    `InvalidValidationTimeException`;
  - at `2030-01-01T05:00:00+05:00` the `VerifiedRead` keeps
    `2030-01-01T00:00:00Z`.

## Left

Each on Maurice's word:
1. the `dev-main` branch-alias to `0.21-dev` (as #201 did after 0.20.0);
2. the Drupal module moves to `^0.21` in the issue that builds its
   harness.
