# Step 82 — 0.21.0 prepared

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

## Left

Each on Maurice's word:
1. the release PR merged, then CI on `main`;
2. tag `v0.21.0`, the GitHub release, Packagist;
3. afterwards, the `dev-main` branch-alias to `0.21-dev` (as #201 did
   after 0.20.0);
4. the Drupal module moves to `^0.21` in the issue that builds its
   harness.
