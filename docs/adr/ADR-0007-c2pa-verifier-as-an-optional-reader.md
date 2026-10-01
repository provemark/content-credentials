# ADR-0007: provemark/c2pa-verifier as an optional reader backend

| Field    | Value                          |
|----------|--------------------------------|
| Status   | proposed                       |
| Date     | 2026-10-01                     |
| Spec     | SPEC-042                       |
| Deciders | Maurice van Loon (maintainer)  |

## Context

This package reads through `ReaderInterface`, with two backends:
`SigningServiceReader` (HTTP, the default) and `ExtC2paReader` (the native
extension, opt-in, ADR-0003). Neither reaches shared hosting (NOTES Step 24).

`provemark/c2pa-verifier` is a pure-PHP verifier by the same maintainer,
published separately, at v0.2.8. It needs PHP `^8.3`, `ext-openssl` and
`ext-mbstring`, nothing else. This package already names it in `suggest` and in
`docs/readers.md`. SPEC-042 would make it a third backend.

Its own README is explicit that it is a first version nobody has used, and that
everything under `src/` except a short list is `@internal` and may change in any
release.

## Decision

1. **Optional, never required.** The verifier stays in `suggest` and goes into
   `require-dev` for the tests. `require` does not change, so no consumer
   installs it without asking for it.
2. **Behind `ReaderInterface`, like the extension.** `C2paVerifierReader` is the
   containment: it touches only the verifier's documented public API
   (`Verifier::verify()`, `VerificationReport::toArray()`,
   `TrustSettings::fromJson()`, `TrustException`) and never an `@internal`
   class, so a change inside the verifier cannot reach this package's callers.
3. **One decoder.** The verifier's output goes through `ManifestStoreParser`,
   as the other two readers' does. No second definition of "trusted".
4. **Not the default.** Selection stays with the caller (SPEC-020). Nothing
   switches to it automatically.

## Consequences

- A third reader in the SPEC-019 equivalence check, which is the drift alarm
  for all three.
- A constraint in `require-dev` on a `0.x` package. Its README promises that an
  API break will be `0.3.0`, so `^0.2` is the range.
- Same maintainer on both sides. That makes coordination cheap and independence
  weaker: an agreement between this reader and the verifier is not two parties
  agreeing. The service and extension readers in AC2 are what keep it honest.
- Reach, for the first time, onto hosts with no second process and no native
  code — the claim NOTES Step 24 had to withdraw for `ext-c2pa`. That claim
  should be made only once a consumer has run it on such a host, not on the
  strength of the dependency list.
