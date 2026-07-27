# SPEC-003: Reading & verification (ReaderInterface + SigningServiceReader)

| Field      | Value                                   |
|------------|-----------------------------------------|
| Status     | approved                                |
| Author     | Maurice van Loon (maintainer)           |
| Approved   | Maurice van Loon — 2026-07-27           |
| Supersedes | —                                       |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-001/002 build and sign an AI-marked manifest, but the library cannot yet
**read one back**. The core downstream question — "is this asset marked as
AI-generated (Article 50), and who signed it?" — has no typed, tested answer.
The spike only proved it ad hoc via `/v1/read` + c2patool (NOTES.md, primer §3,
§5).

This spec defines a Core reading seam: `ReaderInterface` and one v1 adapter,
`SigningServiceReader`, a PSR-18 client for the `service/` `/v1/read` endpoint
(same service, auth and injection model as SPEC-002 / ADR-0001). It parses the
c2pa-rs manifest store JSON into a typed `ManifestReport` and answers: is it
AI-generated, which assertions does it carry, who signed it, and what
validation-status codes did the reader report.

**Trust nuance (primer §5, carried forward).** "Valid signature" ≠ "trusted
cert". Our service's reader is not configured with trust anchors, so test-cert
manifests report `signingCredential.untrusted`. This library **reports** the
validation-status codes faithfully; it does not itself decide trust. c2patool
(`bin/verify.sh`, with the test trust settings) remains the authoritative
trust-list verifier and is complementary, not replaced.

## Scope

**In scope**

- `ContentCredentials\Core\Reading\ReaderInterface`:
  `read(Asset $asset): ManifestReport`.
- `SigningServiceReader implements ReaderInterface` — PSR-18 client POSTing to
  `{baseUrl}/v1/read` with `Authorization: Bearer <apiKey>` and
  `Content-Type: application/json`, body `{content: base64, mime_type}`.
- `ManifestReport` (immutable) over the **active** manifest, exposing:
  `hasManifest()`, `activeManifestLabel()`, `signer(): ?SignerInfo`,
  `assertions()`, `validationStatusCodes()`, `isTrusted()`,
  `isAiGenerated()`, `digitalSourceTypes()`.
- `SignerInfo` (immutable): issuer, common name, algorithm (from
  `signature_info`; fields optional/nullable).
- Defensive parsing: absence of C2PA data (`{}`), and manifest stores missing
  optional fields, are handled without error; only transport, non-2xx, and
  unparseable bodies raise typed exceptions.
- Typed exceptions implementing `Support\ContentCredentialsException`; the API
  key never appears in messages/logs.

**Out of scope** (each needs its own spec)

- Configuring the service reader with trust anchors so it can report
  `signingCredential.trusted` in-band — a service change / later spec. c2patool
  `bin/verify.sh` remains the trust authority.
- A strict cryptographic-validity verdict (`isSignatureValid()`) — deferred
  until the service's validation output shape is pinned (Open Q2).
- Deep ingredient/parent-chain traversal; reporting non-active manifests beyond
  listing their labels.
- Reading via c2patool/FFI instead of the service.
- Laravel wiring (binding `ReaderInterface`) — a Laravel spec.

## Reuse & dependencies

- Reuses the SPEC-002 value objects `ContentCredentials\Core\Signing\Asset`
  (bytes + `MediaType`) and `SigningServiceConfig` (base URL + API key) — same
  service and auth. Whether these should move to a shared Core location is
  Open Q1 (no relocation proposed for v1).
- Reuses `ContentCredentials\Core\Manifest\DigitalSourceType` for the AI marking
  URI, and the PSR-18/PSR-17 deps already added in SPEC-002 (no new deps, no new
  ADR). Test doubles: the existing `nyholm/psr7` + `php-http/mock-client`.

## Behavior

Given/When/Then; each maps to a Pest test tagged `->group('SPEC-003')`, driven by
a **mock PSR-18 client** (no live network). AC5–AC7 are the required
error/malformed-input paths. The happy-path fixtures mirror the real `/v1/read`
shape observed in the spike:

```json
{
  "active_manifest": "urn:c2pa:…",
  "manifests": {
    "urn:c2pa:…": {
      "claim_generator_info": [{ "name": "…", "version": "…" }],
      "assertions": [
        { "label": "c2pa.actions.v2",
          "data": { "actions": [
            { "action": "c2pa.created",
              "digitalSourceType": "http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia" } ] } }
      ],
      "signature_info": { "alg": "Es256", "issuer": "C2PA Test Signing Cert", "common_name": "C2PA Signer" }
    }
  },
  "validation_status": [ { "code": "signingCredential.untrusted", "explanation": "…" } ]
}
```

- **AC1 — reads an AI-generated PNG manifest**
  - Given a mock client returning `200` with the manifest store above
  - When `read($asset)` is called
  - Then `hasManifest()` is true; `activeManifestLabel()` is the active label;
    `signer()` reports issuer `"C2PA Test Signing Cert"`, common name
    `"C2PA Signer"`, algorithm `"Es256"`; `assertions()` contains the
    `c2pa.actions.v2` assertion; `isAiGenerated()` is true; and
    `digitalSourceTypes()` is exactly
    `["http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia"]`.

- **AC2 — request maps asset onto /v1/read**
  - Given the AC1 setup
  - When the request is inspected
  - Then exactly one `POST {baseUrl}/v1/read` is made with headers
    `Authorization: Bearer <key>` and `Content-Type: application/json`, and JSON
    body `{content: base64(asset bytes), mime_type: <asset media type>}`.

- **AC3 — no C2PA data is not an error**
  - Given a mock client returning `200` with body `{}`
  - When `read($asset)` is called
  - Then `hasManifest()` is false, `signer()` is null, `assertions()` is `[]`,
    `isAiGenerated()` is false — and no exception is thrown.

- **AC4 — non-AI content reports false**
  - Given a `200` manifest store whose active manifest's actions contain no AI
    `digitalSourceType` (e.g. a `c2pa.created` with
    `digitalSourceType` = `…/digitalCapture`, or none)
  - When `read($asset)` is called
  - Then `isAiGenerated()` is false and `digitalSourceTypes()` does not contain
    the trainedAlgorithmicMedia URI.

- **AC5 — non-2xx response is a typed failure** *(required error path)*
  - Given a mock client returning `500` with `{"error":"boom"}` (equally `401`)
  - When `read($asset)` is called
  - Then a `ReadFailedException` (implements `ContentCredentialsException`) is
    thrown carrying the HTTP status and the service `error` message.

- **AC6 — transport failure is wrapped** *(required error path)*
  - Given a mock PSR-18 client that throws `ClientExceptionInterface`
  - When `read($asset)` is called
  - Then a `ReadTransportException` (implements `ContentCredentialsException`)
    is thrown, wrapping the PSR-18 exception as `previous`.

- **AC7 — unparseable 2xx body is rejected** *(required malformed-input path)*
  - Given a mock client returning `200` with a body that is not JSON
  - When `read($asset)` is called
  - Then a `ReadResponseException` (implements `ContentCredentialsException`)
    is thrown.

- **AC8 — defensive parse of a partial manifest store**
  - Given a `200` manifest store whose active manifest has **no**
    `signature_info` and there is **no** `validation_status`
  - When `read($asset)` is called
  - Then no exception is thrown; `signer()` is null; `validationStatusCodes()`
    is `[]`; `hasManifest()` is true.

- **AC9 — surfaces validation-status codes and trust**
  - Given a `200` manifest store whose `validation_status` includes
    `signingCredential.untrusted`
  - When `read($asset)` is called
  - Then `validationStatusCodes()` contains `"signingCredential.untrusted"` and
    `isTrusted()` is false. (Given a store with no `signingCredential.untrusted`
    code, `isTrusted()` is true.)

- **AC10 — the API key never leaks**
  - Given the failure paths AC5–AC7
  - When the thrown exception's message and string cast are inspected
  - Then the API key value does not appear.

## API sketch

Illustrative only. `declare(strict_types=1)`; `final` + interfaces; value objects
`readonly`; PHPStan level max. Lives in `src/Core/Reading` (Core layer).

```php
namespace ContentCredentials\Core\Reading;

use ContentCredentials\Core\Signing\Asset;              // reused (Open Q1)
use ContentCredentials\Core\Support\ContentCredentialsException;

interface ReaderInterface
{
    /** @throws ContentCredentialsException */
    public function read(Asset $asset): ManifestReport;
}

final readonly class SignerInfo
{
    public function __construct(
        public string $issuer,
        public ?string $commonName = null,
        public ?string $algorithm = null,
    ) {}
}

final readonly class ManifestReport
{
    public function hasManifest(): bool;
    public function activeManifestLabel(): ?string;
    public function signer(): ?SignerInfo;

    /** @return list<array{label: string, data: array<string, mixed>}> */
    public function assertions(): array;

    /** @return list<string> */
    public function validationStatusCodes(): array;

    /** True unless a signingCredential.untrusted code is present. */
    public function isTrusted(): bool;

    /** True if the active manifest carries the trainedAlgorithmicMedia marking. */
    public function isAiGenerated(): bool;

    /** @return list<string> distinct digitalSourceType URIs on the active manifest's actions */
    public function digitalSourceTypes(): array;
}
```

```php
namespace ContentCredentials\Core\Reading\Exception;

use ContentCredentials\Core\Support\ContentCredentialsException;

final class ReadFailedException    extends \RuntimeException implements ContentCredentialsException {}
final class ReadTransportException extends \RuntimeException implements ContentCredentialsException {}
final class ReadResponseException  extends \RuntimeException implements ContentCredentialsException {}
```

`SigningServiceReader` mirrors `SigningServiceSigner`'s constructor (PSR-18
client + PSR-17 factories + `SigningServiceConfig`) and endpoint joining
(`rtrim(baseUrl,'/').'/v1/read'`).

## Decisions (resolved at approval, 2026-07-27)

The draft's open questions were resolved as proposed; recorded here so the
approved spec is self-contained.

- **D1 — shared value objects.** Reuse `Signing\Asset` and
  `SigningServiceConfig` from the Signing namespace as-is for v1. No relocation
  or rename now; revisit if a third consumer appears.
- **D2 — cryptographic-validity verdict.** **Deferred.** Expose
  `validationStatusCodes()` + `isTrusted()` only; a strict `isSignatureValid()`
  verdict is a follow-up once the service reader's validation output shape is
  pinned against the running service.
- **D3 — trust scoping.** In-band trust (`signingCredential.trusted`) is out of
  scope for v1 (the service reader has no trust anchors); c2patool
  `bin/verify.sh` remains the trust authority. The library reports codes only.
- **D4 — active vs. all manifests.** Report the active manifest's
  signer/assertions only for v1; deep ingredient/parent traversal is deferred.
- **D5 — actions label matching.** When scanning for `digitalSourceType`, match
  any assertion whose label starts with `c2pa.actions` (covers `c2pa.actions`
  and `c2pa.actions.v2`).
- **D6 — read input type.** `read(Asset $asset)` reusing `Signing\Asset`
  (ties to D1).

No open questions remain.

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
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
| AC10                 | —                           | —                    |
