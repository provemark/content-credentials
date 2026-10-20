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

## Amendment 1: the service checks the timestamp it promised (approved 2026-10-20)

Approved by Maurice van Loon on 2026-10-20, with both open questions resolved
as leaned (see *Decisions* below). Source: NOTES Step 74.

**Why.** AC5 assumed that a configured TSA is either used or the signature
fails. c2pa-node 0.9.8 broke that assumption without an error. On 0.9.8 and
0.9.10 a `CallbackSigner` with a `tsaUrl` signs through `signAsync` without
contacting the TSA. A working TSA gives a signature without a timestamp, and so
does an unreachable one: HTTP 200, and the audit record says
`timestamped: true` *(measured per version in fresh containers, NOTES Step 74;
reported as contentauth/c2pa-js#244)*.

The pin stayed on 0.9.5, and since #184 the AC5 test turns red on such an
engine. But that test only guards CI. A service built from a lockfile with a
broken engine, or a future engine that drops the timestamp some other way,
would hand out untimestamped signatures as success. Nothing in the service
checks the result today. `timestamped` in the audit record is
`Boolean(TSA_URL)`, the configuration rather than the outcome.

### Changes

**AC5 is unchanged.** It is now enforced at two points: when the TSA refuses
(as today), and after signing (AC6).

**New criteria:**

- **AC6: a signature without a validated timestamp is refused**
  - Given `CONTENTAUTH_TSA_URL` is set
  - When signing returns an asset whose active manifest, read back by the
    service, does not carry a timestamp
  - Then `/v1/sign` responds non-2xx with the generic signing error and a
    `cid`, returns no `signed_content`, and the audit record has outcome
    `failed` with a reason that says the timestamp is missing
  - "Carries a timestamp" means both of these: `signature_info.time` is present
    and parses as a date-time (D1), and `timeStamp.validated` is among the
    active manifest's `success` codes
  - Rationale: the second condition is what the engine reports for a token it
    could check *(measured on 0.9.5: `timeStamp.validated` in `success`, and
    `timeStamp.untrusted` informational without TSA anchors)*. D3 stands: the
    TSA's own trust is not required, and `timeStamp.untrusted` does not refuse.

- **AC7: the check is a pure function, tested without an engine**
  - Given manifest-store JSON in the shape `/v1/read` returns
  - When the service's exported check is applied
  - Then it accepts a store whose active manifest has `time` and
    `timeStamp.validated`. It refuses a store with no `time`, a store with a
    malformed `time`, a store whose `timeStamp.validated` is missing, and a
    store with no active manifest
  - Rationale: the engines that actually drop the timestamp (0.9.8+) are not
    the ones CI runs. A test on JSON fixtures goes red before the function
    exists, and stays meaningful on any engine. Exported like the SPEC-029
    helpers, so requiring `server.js` must still not start a server.

- **AC8: the audit record states the outcome**
  - `timestamped` in a `signed` record is `true` only when AC6's check passed,
    and absent or `false` when no TSA is configured
  - Rationale: with AC6 a `signed` record with a TSA configured always means a
    checked timestamp. Today the field repeats the configuration, which is how
    it said `true` on 0.9.10 for assets without one. This touches SPEC-012's
    record. The field keeps its name and its type.

**Unchanged:** AC1–AC5, D1–D6, the `/v1/sign` contract, `/health`, and the
no-TSA path, which gets no read-back.

### Implementation sketch

```js
// service/server.js, after signAsync() and readFileSync(tmp)
if (TSA_URL) {
  const reader = await Reader.fromAsset({ buffer: signedAsset, mimeType: mime_type }, NO_REMOTE_FETCH);
  const store = reader ? reader.json() : null;
  if (!carriesValidatedTimestamp(store)) {          // AC7, exported
    throw new Error('signed without a validated timestamp although CONTENTAUTH_TSA_URL is set');
  }                                                  // -> existing catch: 500, audit "failed" (AC6)
}
```

The read uses the same settings as every other Reader (SPEC-043: no remote
fetch). It runs inside the signing slot (SPEC-015), not against the read
budget (SPEC-024), because it is part of signing.

### Cost

Measured on c2pa-node 0.9.5 in the service container, WAV assets, DigiCert
TSA:

| asset | signing (incl. TSA) | read-back |
|---|---|---|
| 0.1 MB | 476 ms | 5 ms |
| 5 MB | 233 ms | 13 ms |
| 14 MB | 264 ms | 15 ms |

**Memory, measured 2026-10-20** on c2pa-node 0.9.5 in the service container.
Each run is one fresh Node process doing what the sign handler does: a base64
body string decoded to a Buffer, `Builder.withJson`, `CallbackSigner` with the
DigiCert TSA, `signAsync` to a temp file, `readFileSync`, and then, in half the
runs, the read-back. The peak is the process's `VmHWM` (highest RSS, native
memory included). The assets are the largest that fit the 20 MB body limit
after base64: a 14.5 MiB WAV and a 13.7 MiB PNG. Three runs per row:

| asset | before signing | peak, no read-back | peak, with read-back | read-back |
|---|---|---|---|---|
| WAV 14.5 MiB | 100 MiB | 171 / 171 / 171 MiB | 186 / 186 / 186 MiB | 16–22 ms |
| PNG 13.7 MiB | 97 MiB | 162 / 148 / 161 MiB | 176 / 176 / 176 MiB | 21 ms |

**The read-back raises the peak by 15 MiB, about one asset's size**, in every
run and for both types. So it does not hide under the signing peak, as the
draft first reasoned. Measured per process, not per container, so these
figures are not comparable with SPEC-024's multipliers, which came from
container measurements.

What it means for `mem_limit: 1g` is reasoned, not measured: SPEC-024 sizes a
saturated instance at ~650 MiB (four signs, four reads). If all four signing
slots read back at once, that adds up to 4 × 15 = 60 MiB, about 710 MiB in
total. Inside the limit, with less headroom.

### What this does not solve

- **The reader is the same engine as the signer.** An engine that drops the
  timestamp *and* reports one on read would pass. Nothing has shown that, and a
  second engine for this one check (ext-c2pa, the verifier) is out of
  proportion. Named so it is not mistaken for independence.
- It does not choose or check TSA trust anchors (out of scope, as before).

### Decisions (resolved at approval, 2026-10-20)

- **A1: `/health` reports the check.** `timestamp_check` is `true` when the
  service checks a timestamp after signing, which is exactly when
  `CONTENTAUTH_TSA_URL` is set, and `false` otherwise. A rebuild that carries
  the check is then confirmable without spending a signature, as with
  `remote_manifest_fetch` (SPEC-043).
- **A2: AC7 fixtures are trimmed.** One store taken from a real `/v1/read`
  response, cut down to the fields the check reads; the refusal cases are
  derived from it in the test. AC7 is also run over the engine's own output for
  two committed signed assets, one timestamped and one not, so the trimmed
  shape cannot drift from what the reader returns.

### Verification before merge

AC6–AC8 green. On c2pa-node 0.9.5: AC4 and AC5 still pass, and `bin/e2e.php`
is trusted with `hasTimestamp` true. On a 0.9.10 build (local, not merged):
every sign with a TSA configured is refused under AC6 rather than signed. That
run is the proof the check catches the failure it exists for.

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
