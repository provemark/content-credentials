# Step 84 — 0.22.0 prepared and released

*2026-11-14.* Maurice: "merge #217 en bereid library 0.22.0 voor". A minor,
as SPEC-046 Amendment 1's open question 3 settled: one public class
(`VerifierRefusalException`) and `ReadFailedException` no longer `final`.

## Done

- PR #217 (SPEC-046 Amendment 1, step 83) squash-merged as `475daa4`; its
  20 checks were green.
- Branch `release-0.22.0`: `CHANGELOG.md` *Unreleased* becomes **0.22.0 —
  2026-11-14**, with an introduction; the compare links move on.

## Measured

- Verifier 0.11.2 is installed locally and is the newest release.
- `composer check` on the amendment's code (step 83): exit 0, 545 passed,
  7 skipped, 18 deprecated (the same 18); spec-check 0 errors, 0
  unresolved. The release branch changes the CHANGELOG and the notes only.

## Released

All on Maurice's word, on 2026-11-14 ("commit en push maar"; "merge #218
en tag en release v0.22.0 maar"; recorded at "leg de 0.22.0-release
vast"):

- **#218**, *Release 0.22.0: a refusal on the verifier route carries the
  verifier's report*: CI 20/20, squash-merged as `d48b650`.
- **CI on `main`** at `d48b650` (run 43577639085): 20 of 20 jobs `success`.
- **Tag `v0.22.0`**, annotated, on `d48b650`. GitHub Release `v0.22.0`,
  with the CHANGELOG section as its text:
  https://github.com/provemark/content-credentials/releases/tag/v0.22.0
- **Packagist** lists `v0.22.0`, source `d48b650`.
- **A fresh install** in an empty directory of
  `provemark/content-credentials:^0.22` with `provemark/c2pa-verifier:^0.11`
  resolved 0.22.0 and verifier 0.11.2. The package holds 75 files, equal to
  the tag's `git archive` (0.21.0's 74 plus `VerifierRefusalException.php`).
  `readVerified()` on `spec046-child-overruns.png` at
  2026-11-14T00:00:00Z throws `VerifierRefusalException`. Its
  `verification` crJSON holds `manifests: []` and a root failure
  `general.error`, and keeps the given time.

## Left

Each on Maurice's word:
1. the `dev-main` branch-alias to `0.22-dev`;
2. the Drupal module asks for `^0.22` in the issue that builds its harness
   (its SPEC-046).
