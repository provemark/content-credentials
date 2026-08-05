# SPEC-013: `isTrusted()` fails closed

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon — 2026-08-05                     |
| Supersedes | — (amends SPEC-003 D3 and SPEC-005)               |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`ManifestReport::isTrusted()` is defined negatively — true unless the reader
reported `signingCredential.untrusted` (SPEC-003 D3):

```php
return ! in_array(self::UNTRUSTED_CODE, $this->validationStatusCodes, true);
```

An absence of evidence is therefore read as evidence of trust. Verified against
the current implementation (2026-08-05):

```
Asset with NO C2PA data at all:
  hasManifest()      : false
  isTrusted()        : true      ← 
  isSignatureValid() : false
```

A caller writing `if ($report->isTrusted())` as a gate admits **every file that
carries no Content Credential at all**. That is the worst possible failure
direction for this method: an unsigned file is the normal case for hostile or
merely unmarked input, and it sails through.

The empty report is the clearest instance, not the only one. The definition
fails open for any outcome the single hard-coded code does not name — a revoked,
expired or otherwise unacceptable signing certificate produces a different
`signingCredential.*` status, and `isTrusted()` would answer `true` for those
too.

The method is also answering a question the current pipeline cannot honestly
answer. Per the `ValidationState` docblock, `Trusted` means the certificate is on
a trust list, and `Valid` means integrity passed but it is not. The signing
service's `/v1/read` performs no trust-list verification, so a genuinely signed
asset comes back `Valid` with `signingCredential.untrusted` present — today's
`false` is right, but by accident of that one code being emitted rather than
because trust was positively established. `bin/verify.sh` is where real trust
verification happens, via c2patool and `certs/c2pa-trust.settings.json`.

This is the one finding from the 2026-08-05 review on which a user can make a
wrong security decision **without doing anything wrong themselves** — the method
name promises a verdict and the implementation supplies a default.

## Scope

**In scope**

- Redefining `isTrusted()` positively: trusted only when the reader positively
  established trust.
- Making the same guarantee explicit for a report with no active manifest.
- Updating SPEC-003 D3 and SPEC-005's wording to match (amendment, not a new
  approval of those specs' other content).
- A convenience predicate combining verification and the Article 50 marking, so
  the safe check is the short one to write.
- README and docblock changes so the distinction between *claim* and *verdict*
  is stated where callers look.
- A CHANGELOG entry under `Changed` marking this a behaviour change, and the
  release being a minor bump.

**Out of scope** (each needs its own spec before it may be built)

- Enabling trust-list verification in the signing service's `/v1/read` (trust
  anchors, EKU config, settings plumbing). This spec makes `isTrusted()` honest
  about what the pipeline reports; making the pipeline able to report trust at
  all is a separate, larger change to `service/`.
- Any change to `isSignatureValid()`, `validationState()` or `hasTimestamp()`.
- Changes to the signing path or to `service/`.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-013')`.

- **AC1 — no manifest is not trusted** *(the reported defect)*
  - Given a `ManifestReport` with no active manifest (an asset carrying no C2PA
    data, i.e. the SPEC-010 empty store)
  - When `isTrusted()` is called
  - Then it returns **false**
  - And `hasManifest()`, `isSignatureValid()` and `isAiGenerated()` are
    unchanged at `false`

- **AC2 — trust must be positively established**
  - Given a report whose `validation_state` is `Trusted`
  - When `isTrusted()` is called
  - Then it returns **true**

- **AC3 — a valid but untrusted signature is not trusted**
  - Given a report whose `validation_state` is `Valid` (integrity passed, the
    certificate is not on a trust list — the current test-certificate case,
    with or without `signingCredential.untrusted` present)
  - When `isTrusted()` is called
  - Then it returns **false**, while `isSignatureValid()` remains **true**

- **AC4 — an unrecognised or absent state is not trusted** *(error path)*
  - Given a report whose `validation_state` is absent, empty, or a string the
    `ValidationState` enum does not recognise
  - When `isTrusted()` is called
  - Then it returns **false**, and no exception is raised

- **AC5 — other credential failures are not trusted** *(error path)*
  - Given a report carrying a `signingCredential.*` status other than
    `untrusted` (e.g. revoked, expired, invalid) and no `Trusted` state
  - When `isTrusted()` is called
  - Then it returns **false**

- **AC6 — the safe check is the short one to write**
  - Given a report
  - When `isVerifiedAiGenerated()` is called
  - Then it returns true only if `isSignatureValid()` **and** `isAiGenerated()`
    are both true — so a caller acting on the Article 50 marking cannot act on
    an unverified claim by writing the obvious thing
  - And the method documents that it deliberately does **not** require
    `isTrusted()`, because trust depends on deployment configuration the library
    cannot see (see Out of scope), and callers who need it must add it

- **AC7 — the claim/verdict distinction is documented**
  - Given the README read example and the `ManifestReport` docblocks
  - When a reader looks for how to act on a credential
  - Then it is stated that `isAiGenerated()`, `signer()` and
    `digitalSourceTypes()` report what a manifest *claims*, and that acting on
    them requires a verdict from `isSignatureValid()` / `isTrusted()`

- **AC8 — a constant `false` reads as configuration, not as a bug**
  - Given a deployment whose signing service performs no trust-list
    verification, i.e. every read returns `validation_state: "Valid"`
  - When a developer consults the `isTrusted()` docblock or the README
  - Then both state that trust requires the *service* to be configured with
    trust anchors, that without that configuration `isTrusted()` is `false` by
    design and not by failure, and that `isSignatureValid()` is the check that
    is meaningful in that configuration
  - And `bin/verify.sh` is named as where authoritative trust verification
    happens today

## API sketch

Illustrative only. The change is confined to `src/Core/Reading/ManifestReport.php`;
no constructor or signature changes, so no caller has to be rewritten to compile.

```php
/**
 * True only when the reader positively established trust: the c2pa-rs
 * validation_state is Trusted, meaning integrity passed AND the signing
 * certificate chained to a configured trust list. Absence of evidence is not
 * trust — a report with no manifest, an unrecognised state, or any other
 * credential failure yields false.
 */
public function isTrusted(): bool
{
    return $this->validationState === ValidationState::Trusted;
}

/** Verified AND marked as AI-generated. See AC6 on why trust is not included. */
public function isVerifiedAiGenerated(): bool
{
    return $this->isSignatureValid() && $this->isAiGenerated();
}
```

`UNTRUSTED_CODE` and the `validationStatusCodes()` accessor stay: the codes
remain useful diagnostics, they simply stop being the definition of trust.

## Open questions

- ~~**Is the positive definition too strict for current deployments?**~~
  **RESOLVED (2026-08-05): the positive definition.** The objection — that
  `isTrusted()` then returns `false` for every asset — is a property of the
  *deployment*, not of the API. `ValidationState` already distinguishes the two
  cases correctly: `Valid` means integrity passed but the certificate is not on
  a trust list, `Trusted` means it is. So `state === Trusted` is not merely safe,
  it is the accurate reading of what the reader reported, and it becomes `true`
  by itself the moment a deployment configures trust anchors — no further code
  change needed.

  The minimal alternative (`hasManifest() && ! untrusted-code`) was rejected: it
  preserves a definition that still fails open for every credential failure the
  one hard-coded code does not name (AC5), and it would leave the method's name
  promising more than its implementation delivers. Fixing the reported instance
  while leaving the shape of the defect intact is the worse outcome for a method
  callers use as a security gate.

  Consequence to carry: until the service verifies trust, `isTrusted()` is
  *safe but not yet useful*. AC8 requires that this is stated where callers
  look, so the constant `false` reads as configuration rather than as a bug.
  Service-side trust verification is raised as a follow-up spec.
- **Should `isVerifiedAiGenerated()` include `isTrusted()`?** Including it is
  the stricter reading, but with the point above unresolved it would make the
  helper always false, which teaches callers to avoid it. AC6 proposes excluding
  it and documenting why. *Non-blocker*, but revisit once trust verification
  exists.
- **Deprecation path.** `isTrusted()` changes meaning rather than disappearing,
  so a rename (`isTrustListVerified()`) would be louder but breaks callers.
  *Non-blocker*, leaning keep the name and make the CHANGELOG entry explicit.

## Release impact

This is the first `src/` change since v0.4.0 and a **behaviour change to a public
API**: callers relying on `isTrusted()` returning `true` for an unsigned asset —
that is, callers relying on the defect — will see `false`. Under semver for a 0.x
line this is a **minor bump to v0.5.0**, and the CHANGELOG entry belongs under
`Changed` with the failure direction spelled out, not under `Fixed` where it
would be easy to miss.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/Reading/ManifestReportTrustTest.php` :: "does not trust an asset that carries no C2PA data at all" | `ManifestReport::isTrusted()` |
| AC2 | `tests/Unit/Reading/ManifestReportTrustTest.php` :: "trusts a report whose validation state is Trusted"; `tests/Unit/Reading/SigningServiceReaderTest.php` :: "reports trusted when the store carries the Trusted verdict" | `ManifestReport::isTrusted()` |
| AC3 | `tests/Unit/Reading/ManifestReportTrustTest.php` :: "does not trust a valid signature whose certificate is not on a trust list"; `tests/Unit/Reading/SigningServiceReaderTest.php` :: "does not report trusted merely because no untrusted code is present" | `ManifestReport::isTrusted()` |
| AC4 | `tests/Unit/Reading/ManifestReportTrustTest.php` :: "does not trust a report whose validation state is absent or unrecognised" | `ManifestReport::isTrusted()` |
| AC5 | `tests/Unit/Reading/ManifestReportTrustTest.php` :: "does not trust other signingCredential failures"; "does not trust an invalid manifest"; `tests/Unit/Property/ManifestReportPropertyTest.php` :: "decides trust by the Trusted verdict alone, whatever codes accompany it" | `ManifestReport::isTrusted()` |
| AC6 | `tests/Unit/Reading/ManifestReportTrustTest.php` :: "reports a verified AI marking only when the signature checked out"; "does not report a verified AI marking for a valid asset without the marking"; `tests/Unit/Property/ManifestReportPropertyTest.php` :: "never reports a verified AI marking without both halves holding" | `ManifestReport::isVerifiedAiGenerated()` |
| AC7 | `tests/Unit/Reading/ManifestReportTrustTest.php` :: "documents that the marking accessors report claims, not verdicts" | `ManifestReport::isAiGenerated()` docblock; README quick start |
| AC8 | `tests/Unit/Reading/ManifestReportTrustTest.php` :: "documents that trust depends on service configuration, not on failure" | `ManifestReport::isTrusted()` docblock; README |
