# Step 84 — 0.22.0 prepared

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

## Left

Each on Maurice's word:
1. the release PR merged, then CI on `main`;
2. tag `v0.22.0`, the GitHub release, Packagist;
3. the `dev-main` branch-alias to `0.22-dev`;
4. the Drupal module asks for `^0.22` in the issue that builds its harness
   (its SPEC-046).
