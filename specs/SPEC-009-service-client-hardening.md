# SPEC-009: Service & client hardening (auth, response bounds, error codes)

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon — 2026-07-28                     |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

The 2026-07-28 architecture review flagged three low-severity hardening items on
the trust boundary between the PHP client and the signing service. Each is low
risk on its own (the service is authenticated and normally co-located), but this
component **holds the signing key**, so defense-in-depth is warranted:

- **#4 — non-constant-time auth.** `service/server.js` compares the bearer token
  with `token !== API_KEY`, a byte-wise comparison whose timing can leak how much
  of the token matched.
- **#5 — unbounded response read.** `SigningServiceSigner` and
  `SigningServiceReader` do `(string) $response->getBody()` with no size cap. A
  compromised/MITM'd service (or a bug) could return an enormous body and exhaust
  memory — the security rule "size-limit and validate untrusted input"
  (CLAUDE.md) applies to the service *response*, not just request parsing.
- **#6 — coarse error codes.** The service returns `500` for client errors
  (invalid base64, unsupported `mime_type`), and does not validate `mime_type`
  against the supported set — so a bad request is indistinguishable from a server
  fault.

## Scope

**In scope**

- **#4:** constant-time bearer-token comparison in the service, length-safe (no
  early-exit on length mismatch, no exception on unequal lengths).
- **#5:** a bounded response read in `SigningServiceSigner` and
  `SigningServiceReader` — a configurable maximum (`SigningServiceConfig`), which
  when exceeded raises the existing typed response exception
  (`SigningResponseException` / `ReadResponseException`) **before** decoding.
- **#6:** the service returns **400** for client errors — content that is not
  valid base64, and a `mime_type` outside the supported set (allowlist
  `image/png`, `image/jpeg`, matching `MediaType`) — while genuine signing /
  infrastructure failures stay **500**.

**Out of scope** (each needs its own spec before it may be built)

- Rate limiting / brute-force lockout on the auth endpoint.
- TLS/transport hardening (a deployment concern, not code).
- Deep structural validation / fuzzing of the manifest store beyond the size
  bound (a parser-hardening spec, if ever needed).
- Minor unrelated review nits (e.g. `ManifestBuilder::withClaimGenerator`
  empty-name validation) — a small SPEC-001 amendment if wanted, not here.
- Any change to Core's public method signatures — `maxResponseBytes` is an
  additive, defaulted field on `SigningServiceConfig` (backwards-compatible).

## Behavior

Given/When/Then. **#5 is Pest-unit-testable** (mock PSR-18 client) tagged
`->group('SPEC-009')`. **#4 and #6 are service-side** and, like SPEC-007
AC4/AC5, are integration checks (curl/`bin`) — the Node service has no unit
suite. AC1 is the required malformed/oversized-input path.

- **AC1 — an over-limit response body is rejected before decoding** *(required)*
  - Given a `SigningServiceSigner` (or `SigningServiceReader`) whose
    `SigningServiceConfig.maxResponseBytes` is N, and a mock PSR-18 client
    returning a 2xx body longer than N bytes
  - When `sign()` (or `read()`) is called
  - Then a `SigningResponseException` (`ReadResponseException`) is thrown, the
    oversized body is **not** JSON-decoded, and no `SignedAsset`/report is
    produced.

- **AC2 — a within-limit response still works**
  - Given a normal response comfortably under `maxResponseBytes`
  - When `sign()` / `read()` is called
  - Then it succeeds exactly as before (no behavioural change under the limit).

- **AC3 — wrong bearer token is rejected (constant-time)** *(service, integration)*
  - Given the service running with an API key
  - When `/v1/sign` is called with a missing/incorrect token (including a token
    of a different length)
  - Then it responds `401` and performs the comparison in constant time
    (`crypto.timingSafeEqual` over fixed-length digests) — verified by code +
    the `401` behaviour; the correct token still yields `2xx`.

- **AC4 — client errors are 400, not 500** *(service, integration)*
  - Given the service running
  - When `/v1/sign` is called with `content` that is not valid base64, or a
    `mime_type` outside {`image/png`, `image/jpeg`}
  - Then it responds `400` with an error message (not `500`); a valid request
    still yields `2xx`, and a genuine signing failure still yields `500`.

## API sketch

Illustrative. Core stays framework-agnostic; the new field is additive.

```php
// SigningServiceConfig — additive, defaulted (backwards-compatible)
final readonly class SigningServiceConfig
{
    public function __construct(
        public string $baseUrl,
        public string $apiKey,
        public int $maxResponseBytes = 67_108_864, // 64 MiB; see OQ1
    ) {}
}
```

```php
// Bounded read shared by signer + reader (sketch — a Support helper or inline)
// Reject via Content-Length when known, then read at most maxResponseBytes+1 and
// throw the typed response exception if the limit is exceeded.
```

```js
// service/server.js — constant-time auth (sketch)
const crypto = require('crypto');
function tokenMatches(token, key) {
  const a = crypto.createHash('sha256').update(String(token)).digest();
  const b = crypto.createHash('sha256').update(String(key)).digest();
  return crypto.timingSafeEqual(a, b); // fixed length -> no throw, no length leak
}

// sign handler: 400 for client errors
// - content not valid base64  -> 400
// - mime_type not in {image/png, image/jpeg} -> 400
// - signing / TSA failure     -> 500 (unchanged)
```

## Decisions (resolved at approval, 2026-07-28)

Resolved as proposed in the draft.

- **D1 — max size (was OQ1).** `SigningServiceConfig.maxResponseBytes` defaults to
  **96 MiB** (`100_663_296`), configurable via Laravel config / env (D4). Headroom
  over the service's 50 MB request cap × the ~1.33 base64 inflation. Not
  hard-coupled to `MAX_BODY_SIZE` (independent knobs, documented).
- **D2 — allowlist (was OQ2).** The service hardcodes `image/png` + `image/jpeg`
  (mirrors `MediaType`), with a comment that it must track `MediaType` when asset
  types are added.
- **D3 — constant-time (was OQ3).** SHA-256 digest of both token and key, then
  `crypto.timingSafeEqual` — length-safe (no throw, no length leak).
- **D4 — Laravel config (was OQ4).** Add `content-credentials.service.max_response_bytes`
  (env `CONTENTAUTH_MAX_RESPONSE_BYTES`), wired into `SigningServiceConfig` by the
  provider, symmetric with SPEC-008's timeouts. Invalid (non-numeric / < 1) →
  `MissingConfigurationException`.

No open questions remain.

## Traceability

Implemented 2026-07-28. AC1/AC2 (#5) are Pest unit tests in
`tests/Unit/ResponseBoundsTest.php` (`->group('SPEC-009')`); the D4 config
validation has a provider test in
`tests/Unit/Laravel/ContentCredentialsServiceProviderTest.php`. `composer check`
green (Pint + PHPStan level max + Pest + Deptrac 0 — `ResponseBody` is Core →
PSR only). AC3/AC4 (#4/#6) are service-side and were verified against the running
service via `curl` (wrong/short token → 401 + valid → 200; invalid base64 → 400;
unsupported mime → 400; valid → 200).

| Acceptance criterion | Test (`it …` / check) | Source (file/symbol) |
|-----------------------|-----------------------|----------------------|
| AC1 (#5 over-limit) | rejects an over-limit signing/read response before decoding | `Core\Support\ResponseBody::readBounded()`, `SigningServiceSigner::sign()` / `SigningServiceReader::read()`, `SigningServiceConfig::$maxResponseBytes` |
| AC2 (#5 within-limit) | still signs when the response is within the limit | `ResponseBody::readBounded()` |
| — (D4 config) | throws MissingConfigurationException for an invalid max_response_bytes | `ContentCredentialsServiceProvider::maxResponseBytes()`, `config/content-credentials.php` |
| AC3 (#4 auth) | curl: wrong/short token → 401, valid → 200 (constant-time) | `service/server.js` `tokenMatches()` (SHA-256 + `timingSafeEqual`) |
| AC4 (#6 codes) | curl: invalid base64 → 400, unsupported mime → 400, valid → 200 | `service/server.js` `isValidBase64()` + `SUPPORTED_MIME` in `/v1/sign` and `/v1/read` |