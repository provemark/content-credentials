# SPEC-005: Signature-validity verdict (`isSignatureValid` / `validationState`)

| Field      | Value                                   |
|------------|-----------------------------------------|
| Status     | draft                                   |
| Author     | Maurice van Loon (maintainer)           |
| Approved   | — (pending maintainer approval)         |
| Supersedes | —                                       |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-003's `ManifestReport` exposes `validationStatusCodes()` and `isTrusted()`,
but a **cryptographic-validity verdict** (`isSignatureValid()`) was deferred
(SPEC-003 D2) because the service's validation output shape had not been pinned
against a running service. It now has been — see the evidence below — so we can
add a robust, correctly-scoped verdict.

### Grounding (observed against the running service, `/v1/read`)

`/v1/read` returns `active_manifest`, `manifests`, `validation_status`,
**`validation_results`** and a top-level **`validation_state`** string. Two real
cases from `bin/e2e.php`:

| Case | `validation_state` | `validation_results.activeManifest` |
|------|--------------------|-------------------------------------|
| Intact asset, **untrusted** test cert | `"Valid"` | success incl. `claimSignature.validated`; failure `[signingCredential.untrusted]` |
| **Tampered** asset (one byte flipped) | `"Invalid"` | success **still** incl. `claimSignature.validated`; failure `[signingCredential.untrusted, assertion.hashedURI.mismatch]` |

Two facts drive the design:

1. **`validation_state` separates integrity from trust.** An intact but
   untrusted manifest is `"Valid"` (not `"Invalid"`); a trusted one would be
   `"Trusted"`. Tampering flips it to `"Invalid"`. (c2pa-rs order: Invalid <
   Valid < Trusted.)
2. **`claimSignature.validated` alone is insufficient** — it stays present on a
   tampered asset (the *claim* signature is intact; the *asset* binding breaks
   via a hash mismatch). So the verdict must use `validation_state`, not that
   single code.

## Scope

**In scope**

- Extend `SigningServiceReader` (SPEC-003) to parse the top-level
  `validation_state` string from `/v1/read`.
- Add to `ManifestReport` (SPEC-003):
  - `validationState(): ?ValidationState` — the raw c2pa-rs verdict (nullable);
  - `isSignatureValid(): bool` — true iff the state is `Valid` or `Trusted`.
- A `ValidationState` enum (`Invalid` / `Valid` / `Trusted`).
- Recorded as an **amendment to SPEC-003** (it grows SPEC-003's `ManifestReport`
  and reader; back-pointer added to SPEC-003, like SPEC-002 → SPEC-001).

**Out of scope** (each needs its own spec)

- Trust configuration so the service reports `Trusted` in-band (still SPEC-003
  D3; c2patool `bin/verify.sh` remains the trust authority).
- Exposing the full `validation_results` success/failure code lists, per-assertion
  detail, or ingredient/parent-chain validity.
- Redefining `isTrusted()` in terms of `validation_state` (Open Q3).

## Behavior

Given/When/Then; each maps to a Pest test tagged `->group('SPEC-005')`, driven by
a **mock PSR-18 client** with manifest-store fixtures carrying `validation_state`
(the real shapes above). AC4 is the required malformed/edge path.

- **AC1 — intact, untrusted manifest is signature-valid**
  - Given a `200` store with `validation_state: "Valid"` and a
    `signingCredential.untrusted` code
  - When `read($asset)` is called
  - Then `isSignatureValid()` is **true**, `validationState()` is
    `ValidationState::Valid`, and `isTrusted()` is **false** (unchanged) — the
    two verdicts are independent.

- **AC2 — tampered manifest is not signature-valid**
  - Given a `200` store with `validation_state: "Invalid"` (and an
    `assertion.hashedURI.mismatch` failure)
  - When `read($asset)` is called
  - Then `isSignatureValid()` is **false** and `validationState()` is
    `ValidationState::Invalid`.

- **AC3 — trusted manifest is signature-valid**
  - Given a `200` store with `validation_state: "Trusted"`
  - When `read($asset)` is called
  - Then `isSignatureValid()` is **true** and `validationState()` is
    `ValidationState::Trusted`.

- **AC4 — missing/unknown state does not assert validity** *(edge/malformed path)*
  - Given a `200` store with **no** `validation_state` (or an unrecognised
    value such as `"Weird"`)
  - When `read($asset)` is called
  - Then `validationState()` is `null` and `isSignatureValid()` is **false**
    (the reader never claims validity it cannot confirm); no exception is thrown.

- **AC5 — no manifest → not signature-valid**
  - Given a `200` body of `{}` (no C2PA data)
  - When `read($asset)` is called
  - Then `isSignatureValid()` is **false** and `validationState()` is `null`.

## API sketch

Illustrative only. `declare(strict_types=1)`; `final`; value objects `readonly`;
PHPStan level max.

```php
namespace ContentCredentials\Core\Reading;

enum ValidationState: string
{
    case Invalid = 'Invalid';
    case Valid   = 'Valid';
    case Trusted = 'Trusted';
}

final readonly class ManifestReport
{
    // ... existing SPEC-003 methods ...

    public function validationState(): ?ValidationState;

    /** True iff the C2PA validation state is Valid or Trusted. */
    public function isSignatureValid(): bool;
}
```

`SigningServiceReader::parse()` gains: read `$store['validation_state']` (a
string), map via `ValidationState::tryFrom()` (null on absent/unknown), and pass
it into the `ManifestReport`. `isSignatureValid()` returns
`in_array($state, [ValidationState::Valid, ValidationState::Trusted], true)`.

## Open questions

- **Q1 — enum vs. raw string.** Model the state as a `ValidationState` enum
  (proposed; `tryFrom` yields `null` for absent/unknown), or expose the raw
  string? Proposed: enum, with `validationState(): ?ValidationState`.
- **Q2 — behaviour on absent/unknown state.** Return `false` from
  `isSignatureValid()` and `null` from `validationState()` (proposed,
  conservative), rather than deriving a verdict from the `validation_results`
  success/failure codes. Confirm.
- **Q3 — align `isTrusted()`?** Keep SPEC-003's `isTrusted()` as "no
  `signingCredential.untrusted` code" (proposed), or redefine it as
  `validationState() === Trusted`? They agree for the test certs; a redefinition
  is a behaviour change to SPEC-003. Proposed: leave as-is for now; revisit.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
