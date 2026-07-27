# SPEC-002: Signing (SignerInterface + SigningServiceSigner)

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

SPEC-001 produces a `Manifest` (the claim-v2 AI-generated marking) but nothing
signs it. The spike proved the end-to-end chain (`bin/spike.php`) — base64 the
asset, POST it with the manifest's assertions to the service `/v1/sign`, write
back the signed bytes — but as throwaway inline `curl`, untyped and untested.

This spec defines the framework-agnostic Core signing seam: an interface
`SignerInterface` and one v1 adapter, `SigningServiceSigner`, a **PSR-18** HTTP
client for the `service/` `/v1/sign` contract (primer §3). Core must not depend
on Laravel/illuminate (CLAUDE.md); the concrete HTTP client is injected.

**Impedance mismatch to handle (from NOTES.md / primer §3–4).** The `/v1/sign`
contract does **not** accept a full manifest definition. It accepts
`content`, `mime_type`, `creator_name`, `extra_assertions[]` and rebuilds
`claim_generator_info` + `format` server-side. So a `Manifest`'s
`claim_generator_info` and `format` are **not** sent verbatim — the signer maps
them onto the service's field set. This is exactly the D1 mapping decision
recorded in SPEC-001.

## Scope

**In scope**

- `ContentCredentials\Core\Signing\SignerInterface`:
  `sign(Asset $asset, Manifest $manifest): SignedAsset`.
- `SigningServiceSigner implements SignerInterface` — a PSR-18 client that POSTs
  to `{baseUrl}/v1/sign` with `Authorization: Bearer <apiKey>` and
  `Content-Type: application/json`, then returns the signed bytes.
- Value objects: `Asset` (raw bytes + `MediaType`) and `SignedAsset` (signed
  bytes + `MediaType`); `SigningServiceConfig` (base URL + API key).
- Request mapping (see Behavior): `content` = base64(asset bytes),
  `mime_type` = asset media type, `creator_name` from the manifest's claim
  generator (D1), `extra_assertions` = `Manifest::assertions()`. `signature_type`
  is left unset (service default `product`).
- Typed error handling implementing `Support\ContentCredentialsException`:
  transport failure, non-2xx service responses, and malformed/negative 2xx
  bodies. The API key is never placed in messages/logs.
- Immutability (primer §6): `SignedAsset.bytes` are the exact bytes to persist;
  the signer never re-encodes them.

**Out of scope** (each needs its own spec before it may be built)

- The Node service implementation itself (already in `service/`).
- Reading / verification — `/v1/read`, c2patool, trust — a Reading spec
  (SPEC-003). `sign()` does not round-trip-verify its own output.
- CAWG signatures (`signature_type` `cawg_org`/`both`, org identity) — v1 sends
  only the default `product`.
- TSA timestamping; retries/backoff; explicit 429 rate-limit handling beyond
  surfacing it as a failure.
- Local/FFI signing (no service) — CLAUDE.md keeps FFI out until a spec says so.
- Laravel wiring (provider/facade/config binding the signer) — a Laravel spec.

## Dependencies & amendments (require maintainer sign-off with this spec)

- **New runtime dependencies** (interfaces only): `psr/http-client` (PSR-18),
  `psr/http-factory` (PSR-17), `psr/http-message` (PSR-7). Per CLAUDE.md a new
  runtime dependency needs an **ADR** — this spec calls for
  `docs/adr/ADR-0001-psr18-http-client.md`, authored with the implementation.
  No concrete client (Guzzle/Symfony) is required by Core; it is injected.
- **require-dev** for tests: a PSR-17 implementation and a mockable PSR-18
  client (proposed `nyholm/psr7` + `php-http/mock-client`) — see Open questions.
- **Proposed amendment to SPEC-001** (approved): add a read-only accessor
  `Manifest::mediaType(): MediaType` (no behavior change) so the signer can
  assert asset/manifest format agreement (AC6) without re-parsing `toArray()`.
  Presented as a diff for approval:

  ```diff
   final readonly class Manifest
   {
  +    public function mediaType(): MediaType { /* returns the declared format */ }
  ```

## Behavior

Given/When/Then; each maps to a Pest test tagged `->group('SPEC-002')`. AC3–AC5
are the required error / malformed-input paths. Tests use a **mock PSR-18
client** — no live network, no real signing service.

- **AC1 — signs a PNG AI manifest against the service (happy path)**
  - Given a `SigningServiceSigner` configured with base URL `https://sign.test`
    and API key `secret`, a mock PSR-18 client returning `200` with
    `{"signed_content": base64("SIGNED-BYTES"), "manifest_url": null}`, an
    `Asset` of PNG bytes, and a SPEC-001 `Manifest` for an AI-generated PNG
  - When `sign($asset, $manifest)` is called
  - Then exactly one HTTP request is made: `POST https://sign.test/v1/sign`
    with headers `Authorization: Bearer secret` and
    `Content-Type: application/json`; and the returned `SignedAsset.bytes` are
    exactly `"SIGNED-BYTES"` with `mediaType` PNG.

- **AC2 — request body maps the manifest and asset correctly**
  - Given the AC1 setup
  - When the request is inspected
  - Then the JSON body has `content` = base64 of the asset bytes,
    `mime_type` = `"image/png"`, `extra_assertions` **equal to**
    `Manifest::assertions()` (the single `c2pa.actions.v2` / `c2pa.created`
    assertion), `creator_name` = the manifest's claim-generator name when set
    (D1), and **no** `signature_type` key (service default applies).

- **AC3 — non-2xx service response is a typed failure** *(required error path)*
  - Given a mock client returning `401` with `{"error":"Unauthorized"}`
    (equally `400`/`500`)
  - When `sign()` is called
  - Then a `SigningFailedException` (implements `ContentCredentialsException`)
    is thrown carrying the HTTP status and the service `error` message; and no
    `SignedAsset` is produced.

- **AC4 — transport failure is wrapped** *(required error path)*
  - Given a mock PSR-18 client that throws `ClientExceptionInterface`
  - When `sign()` is called
  - Then a `SigningTransportException` (implements
    `ContentCredentialsException`) is thrown, wrapping the PSR-18 exception as
    `previous`.

- **AC5 — malformed 2xx body is rejected** *(required malformed-input path)*
  - Given a mock client returning `200` with a body that is not JSON, or JSON
    missing `signed_content`, or a `signed_content` that is not valid base64
  - When `sign()` is called
  - Then a `SigningResponseException` (implements `ContentCredentialsException`)
    is thrown; and no partially-written or corrupt `SignedAsset` is returned.

- **AC6 — asset/manifest media-type must agree**
  - Given an `Asset` whose `MediaType` is JPEG and a `Manifest` whose declared
    media type is PNG
  - When `sign()` is called
  - Then an `InvalidArgumentException`-family typed error (implementing
    `ContentCredentialsException`) is thrown **before** any HTTP request is
    made (a mismatch is a programming error, not a service call).

- **AC7 — the API key never leaks**
  - Given any of the failure paths AC3–AC5
  - When the thrown exception's message and string representation are inspected
  - Then the API key value does **not** appear in them.

## API sketch

Illustrative only. All files `declare(strict_types=1)`; public API `final` +
interfaces; value objects `readonly`; PHPStan level max. Lives in
`src/Core/Signing` (Core layer — no Laravel/illuminate).

```php
namespace ContentCredentials\Core\Signing;

use ContentCredentials\Core\Manifest\Manifest;
use ContentCredentials\Core\Manifest\MediaType;
use ContentCredentials\Core\Support\ContentCredentialsException;
use Psr\Http\Client\ClientInterface;              // PSR-18
use Psr\Http\Message\RequestFactoryInterface;     // PSR-17
use Psr\Http\Message\StreamFactoryInterface;      // PSR-17

final readonly class Asset
{
    public function __construct(public string $bytes, public MediaType $mediaType) {}
}

final readonly class SignedAsset
{
    public function __construct(public string $bytes, public MediaType $mediaType) {}
}

final readonly class SigningServiceConfig
{
    public function __construct(public string $baseUrl, public string $apiKey) {}
}

interface SignerInterface
{
    /** @throws ContentCredentialsException */
    public function sign(Asset $asset, Manifest $manifest): SignedAsset;
}

final class SigningServiceSigner implements SignerInterface
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private SigningServiceConfig $config,
    ) {}

    public function sign(Asset $asset, Manifest $manifest): SignedAsset;
}
```

```php
namespace ContentCredentials\Core\Signing\Exception;

use ContentCredentials\Core\Support\ContentCredentialsException;

/** Service returned a non-2xx response. */
final class SigningFailedException extends \RuntimeException implements ContentCredentialsException {}

/** The PSR-18 client failed to complete the request (network/DNS/TLS). */
final class SigningTransportException extends \RuntimeException implements ContentCredentialsException {}

/** A 2xx response whose body could not be understood. */
final class SigningResponseException extends \RuntimeException implements ContentCredentialsException {}
```

Request body shape sent to `/v1/sign` (primer §3):

```json
{
  "content": "<base64 asset bytes>",
  "mime_type": "image/png",
  "creator_name": "Content Credentials",
  "extra_assertions": [ { "label": "c2pa.actions.v2", "data": { "actions": [ /* c2pa.created */ ] } } ]
}
```

## Open questions

- **Q1 — `creator_name` mapping.** Map from `Manifest`'s claim generator: send
  `creator_name` = claim-generator **name** when set, omit it otherwise (service
  supplies its default); drop the version on the wire (the service composes its
  own `claim_generator_info`). Confirm.
- **Q2 — media-type agreement (AC6).** Enforce `Asset.mediaType ===
  Manifest.mediaType` with a pre-flight typed error, vs. letting the asset
  silently drive `mime_type`. Proposed: enforce. Confirm.
- **Q3 — SPEC-001 amendment.** Add `Manifest::mediaType(): MediaType`
  (read-only) to support Q2/AC6. Proposed: yes. Confirm.
- **Q4 — test doubles / ADR.** `nyholm/psr7` + `php-http/mock-client` in
  require-dev; `docs/adr/ADR-0001-psr18-http-client.md` recording the PSR-18
  choice. Confirm the packages.
- **Q5 — base URL joining & trailing slash.** Normalise `baseUrl` (strip
  trailing `/`) and append `/v1/sign`, mirroring the spike. Confirm.
- **Q6 — response `manifest_url`.** Our service returns `null`; ignore it in v1
  (don't surface). Confirm.
- **Q7 — no retries/timeout policy in Core.** Single attempt; timeouts and
  retries are the injected client's concern (and a later spec). Confirm.

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
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
