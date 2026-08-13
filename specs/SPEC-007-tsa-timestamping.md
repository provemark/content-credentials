# SPEC-007: TSA timestamping (RFC 3161 trusted timestamps)

| Field      | Value                                   |
|------------|-----------------------------------------|
| Status     | implemented                             |
| Author     | Maurice van Loon (maintainer)           |
| Approved   | Maurice van Loon — 2026-07-28           |
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

## Decisions (resolved at approval, 2026-07-28)

The draft's open questions were resolved as recommended; recorded here so the
approved spec is self-contained.

- **D1 — timestamp field (was OQ1).** Verified against `@contentauth/c2pa-node`
  `dist/Reader.spec.js`: the manifest store's `signature_info` carries a `"time"`
  ISO-8601 string when the signature is timestamped (e.g.
  `"time": "2024-08-06T21:53:37+00:00"`), alongside the `alg` / `issuer` /
  `common_name` the reader already parses (SPEC-003). Without a trusted timestamp
  the field is absent or null. `hasTimestamp()` returns `true` iff
  `signature_info.time` is present and parses as a date-time.
- **D2 — boolean only (was OQ2).** v1 ships `hasTimestamp(): bool`. A
  `timestampedAt(): ?DateTimeImmutable` accessor is deferred to a follow-up spec.
- **D3 — present vs trusted (was OQ3).** `hasTimestamp()` means *present and
  structurally valid* (a parseable `time`). Trust of the TSA's own certificate is
  a separate verification concern (out of scope; mirrors signer trust, primer §5).
- **D4 — SPEC-003 amendment (sign-off).** Adding read-only
  `ManifestReport::hasTimestamp(): bool` to the approved SPEC-003 is approved with
  this spec; applied during implementation with its own test and a back-pointer
  recorded in SPEC-003. Additive — no existing accessor changes.
- **D5 — example TSA (was OQ4).** Document `http://timestamp.digicert.com` as the
  `CONTENTAUTH_TSA_URL` example. Documentation only; no code impact.
- **D6 — service verification (was OQ5).** AC4/AC5 are integration checks, run by
  extending `bin/e2e.php` with a timestamp assertion — the Pest suite cannot drive
  the service's signing path. AC1–AC3 (reading) are the Pest unit coverage.

No open questions remain.

## Traceability

Implemented 2026-07-28. AC1–AC3 are Pest unit tests in
`tests/Unit/Reading/ManifestTimestampTest.php` (`->group('SPEC-007')`, 7 tests);
`composer check` green (Pint + PHPStan level max + Pest + Deptrac 0). AC4/AC5 are
integration checks verified against a live service + DigiCert TSA via
`bin/e2e.php` and a direct `/v1/sign` call (see NOTES.md Step 6).

**Implementation note (does not change the contract):** timestamping requires
the **async** signing path. Sync `builder.sign()` with a TSA errors "the sync
http resolver is not implemented", so the service signs via `CallbackSigner` +
`builder.signAsync()` when `CONTENTAUTH_TSA_URL` is set, and keeps the sync
`LocalSigner` path unchanged when it is not. `reserveSize` 20000 (timestamp token
~7–8 KB). See NOTES.md Step 6.

| Acceptance criterion | Test (`it …` / check) | Source (file/symbol) |
|-----------------------|-----------------------|----------------------|
| AC1 | reports a timestamped manifest as timestamped | `SigningServiceReader::parseHasTimestamp()`, `ManifestReport::hasTimestamp()` |
| AC2 | reports a manifest without a timestamp field as not timestamped; reports no timestamp when there is no manifest | `SigningServiceReader::parseHasTimestamp()`, `ManifestReport::hasTimestamp()` |
| AC3 | treats a malformed timestamp as absent without throwing (4 datasets) | `SigningServiceReader::parseHasTimestamp()` |
| AC4 | `bin/e2e.php` timestamp assertion vs `/health` (integration, verified 2026-07-28) | `service/server.js` async `CallbackSigner` path; `GET /health` `timestamping` |
| AC5 | `tests/Integration/TimestampFailsClosedTest.php` :: "refuses to sign when the timestamp authority cannot be reached"; "reports the timestamp authority as configured on /health". Automated 2026-08-13 in the `tsa-unreachable` CI profile; verified by hand 2026-07-28 until then | `service/server.js` sign `catch` (no untimestamped fallback) |
