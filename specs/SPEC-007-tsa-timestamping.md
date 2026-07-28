# SPEC-007: TSA timestamping (RFC 3161 trusted timestamps)

| Field      | Value                                   |
|------------|-----------------------------------------|
| Status     | draft                                   |
| Author     | Maurice van Loon (maintainer)           |
| Approved   | — (while draft)                         |
| Supersedes | —                                       |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A C2PA signature proves *who* signed and *that the bytes are intact*, but on its
own says nothing verifiable about *when*. Without a trusted timestamp, a
signature's validity is bound to the signing certificate's lifetime: once the
cert expires (or is revoked), a verifier can no longer establish that the
signature was made while the cert was valid, and the provenance claim weakens
over time. An **RFC 3161 trusted timestamp** from a Time Stamping Authority
(TSA) countersigns the signature at signing time, so the claim survives cert
expiry — the baseline expectation for production-grade provenance.

The capability already exists in the toolchain but is deliberately unused:
`LocalSigner.newSigner(certChainBuf, privateKeyBuf, alg [, tsaUrl])` takes an
optional TSA URL (primer §4; CLAUDE.md domain rules list TSA as "not yet
implemented, spec required before adding"). Today `service/server.js` calls the
three-argument form, so no signature carries a timestamp, and the library has no
way to report whether a read manifest is timestamped.

This spec adds timestamping as a **service-side operational capability** (like
the signing certificate and algorithm, which are already service env) and gives
the reader a way to verify a timestamp is present. It does **not** change the
`/v1/sign` contract or the Core signer — consistent with the isolated-service
model where the service owns all signing infrastructure and the client owns the
manifest/assertions (primer §3, SPEC-002).

## Scope

**In scope**

- **Service:** an optional env knob `CONTENTAUTH_TSA_URL`. When set,
  `service/server.js` passes it as the fourth argument to
  `LocalSigner.newSigner(...)`, so signatures carry an RFC 3161 timestamp. When
  unset, behaviour is byte-for-byte today's (no timestamp) — a pure,
  backwards-compatible addition.
- **Service fail-closed:** when `CONTENTAUTH_TSA_URL` is set but the TSA is
  unreachable or rejects the request, `/v1/sign` fails (non-2xx) and returns
  **no** signed asset. It never silently falls back to an untimestamped
  signature (that would defeat the operator's explicit intent).
- **Service health:** `GET /health` reports whether timestamping is enabled
  (e.g. `timestamping: true|false`), mirroring how it already reports
  `signing_alg`, so operators can confirm configuration without signing.
- **Reading:** a read-only accessor `ManifestReport::hasTimestamp(): bool`
  (amendment to the approved SPEC-003 — see Dependencies) reporting whether the
  active manifest's signature carries a timestamp, per the reader's parsed
  manifest store. Untrusted-input safe (security rule): a malformed or absent
  timestamp field yields `false`, never an exception.
- **Documentation:** `CONTENTAUTH_TSA_URL` documented in the service env example
  / compose, with a note that a timestamp's *trust* depends on the TSA's
  certificate chain, distinct from timestamp *presence*.

**Out of scope** (each needs its own spec before it may be built)

- Choosing, bundling, or validating TSA **trust anchors** during c2patool/reader
  verification (analogous to signer trust in the primer §5) — a verification /
  trust spec.
- A **per-request** TSA override on `/v1/sign` (a `tsa_url` body field) — TSA
  choice is operational, not per-asset; revisit only if a concrete need appears.
- Exposing the timestamp **time** or **authority identity** on `ManifestReport`
  (`timestampedAt()`, `timestampAuthority()`) — see OQ2; boolean presence first.
- TSA for a future in-process `ExtC2paSigner` (ADR-0003) — that adapter does not
  exist yet; its own Signing spec would cover timestamping there.
- Retry/backoff against a flaky TSA beyond surfacing the failure (SPEC-002 D7:
  retries are the client/operator's concern).

## Dependencies & amendments (require maintainer sign-off with this spec)

- **Amendment to SPEC-003 (approved):** add read-only
  `ManifestReport::hasTimestamp(): bool`. No behaviour change to existing
  accessors; additive only. To be applied during SPEC-007 implementation with
  its own test and a back-pointer recorded in SPEC-003 (same mechanism SPEC-002
  used to amend SPEC-001).
- **No new PHP runtime dependencies.** The accessor reads the manifest store the
  reader already parses (SPEC-003). If OQ2 later adds a timestamp *time*, it uses
  core `DateTimeImmutable` only.
- **No new npm dependency.** `tsaUrl` is a built-in `LocalSigner.newSigner`
  parameter. One new service env var (`CONTENTAUTH_TSA_URL`) and its
  documentation.

## Behavior

Given/When/Then; each maps to a test tagged `->group('SPEC-007')`. The reading
criteria (AC1–AC3) are **Pest unit tests against manifest-store fixtures** — no
network, no TSA. The service criteria (AC4–AC5) are **integration checks**
(a `bin/` e2e against a running service; a live or stubbed TSA), documented as
such because the behaviour lives in the Node service, not in Core. AC3 and AC5
are the required error / malformed-input paths.

- **AC1 — a timestamped manifest is reported as timestamped (reading happy path)**
  - Given a `ManifestReport` built (SPEC-003) from a manifest-store fixture whose
    active manifest's signature carries an RFC 3161 timestamp
  - When `hasTimestamp()` is called
  - Then it returns `true`.

- **AC2 — an untimestamped manifest is reported as not timestamped**
  - Given a `ManifestReport` from a fixture with no timestamp (e.g. the current
    es256, no-TSA signature)
  - When `hasTimestamp()` is called
  - Then it returns `false`.

- **AC3 — a malformed timestamp field does not crash reading** *(required malformed-input path)*
  - Given a manifest-store fixture in which the timestamp field is present but
    malformed / unparseable (untrusted input)
  - When the report is built and `hasTimestamp()` is called
  - Then no exception escapes the reader; `hasTimestamp()` returns `false`; and
    the other accessors (signer, validity) still behave per SPEC-003/005.

- **AC4 — the service timestamps when configured (service happy path, integration)**
  - Given `service/` running with a valid `CONTENTAUTH_TSA_URL` reachable, and
    the same running without it
  - When an asset is signed through `/v1/sign` in each case and read back
    (`/v1/read` or c2patool)
  - Then the configured signature carries a timestamp (`hasTimestamp()` /
    c2patool report `true`), and the unconfigured signature does not — and both
    remain signature-valid.

- **AC5 — an unreachable TSA fails closed** *(required error path)*
  - Given `service/` running with `CONTENTAUTH_TSA_URL` set to an unreachable or
    invalid endpoint
  - When `/v1/sign` is called
  - Then it responds non-2xx with an error and returns **no** `signed_content`;
    it never returns an untimestamped signature as if success.

## API sketch

Illustrative only. PHP files `declare(strict_types=1)`; public API `final` /
interfaces; PHPStan level max; the accessor lives in Core (`src/Core/Reading`).

```php
// namespace Provemark\ContentCredentials\Core\Reading;

final class ManifestReport
{
    // ...existing SPEC-003/005 accessors unchanged...

    /** True when the active manifest's signature carries an RFC 3161 timestamp. */
    public function hasTimestamp(): bool;
}
```

Service (`service/server.js`), the only signing-side change:

```js
// env, alongside SIGN_ALG / CERT_PATH (unset => no timestamp, today's behaviour)
const TSA_URL = process.env.CONTENTAUTH_TSA_URL || undefined;

// GET /health also reports: { ..., timestamping: Boolean(TSA_URL) }

// sign handler — pass the optional 4th arg; fail closed on TSA error
const signer = LocalSigner.newSigner(certificate, privateKey, SIGN_ALG, TSA_URL);
// builder.sign(...) throws if a configured TSA is unreachable -> existing
// catch returns 5xx (AC5); no untimestamped fallback.
```

Service env example (`service/.env.example` / compose), documented:

```bash
# Optional RFC 3161 Time Stamping Authority. Unset = no trusted timestamp.
# A timestamp's TRUST still depends on the TSA's certificate chain.
# CONTENTAUTH_TSA_URL=http://timestamp.digicert.com
```

## Open questions

- **OQ1 (blocker for AC1–AC3 field mapping).** Where does a timestamp surface in
  the c2pa-node / manifest-store JSON that SPEC-003 parses — a
  `signature_info.time` (or similar) field, a `validation_status` code (e.g.
  `timeStamp.validated` / `timeStamp.mismatch`), or both? Resolve by signing once
  against a real TSA during tests-first and capturing the fixture; the exact
  accessor predicate follows from what the reader actually exposes.
- **OQ2 (non-blocker).** Ship boolean `hasTimestamp()` only for v1, or also
  `timestampedAt(): ?DateTimeImmutable`? Recommendation: boolean first; add time
  in a follow-up if the field is trivially available (keeps this spec tight).
- **OQ3 (non-blocker).** Does `hasTimestamp()` mean *present* or *present and
  cryptographically valid*? Recommendation: *present and structurally valid per
  the reader*; trust of the TSA's own certificate is a verification/trust concern
  (out of scope, mirrors signer trust in primer §5). Note the distinction in docs.
- **OQ4 (non-blocker).** Which TSA to name as the documented example in
  `.env.example` (DigiCert `http://timestamp.digicert.com`, or a C2PA-recommended
  one). Documentation choice only; no code impact.
- **OQ5 (non-blocker).** Verification tooling for AC4/AC5: extend `bin/e2e.php`
  with a timestamp assertion, or add a small Node test in `service/`? The Pest
  suite cannot exercise the service's signing path directly.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | — | — |
| AC2 | — | — |
| AC3 | — | — |
| AC4 | — | — |
| AC5 | — | — |
