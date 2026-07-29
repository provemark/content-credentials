# SPEC-010: Reading an asset with no C2PA manifest

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | maurice                                           |
| Approved   | maurice, 2026-07-29                                |
| Supersedes | — (amends the read contract of SPEC-003)          |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Reading an asset that carries no C2PA manifest must be reported as *absence* — an
empty report — not as an error. SPEC-003 already encodes this on the PHP side:
`ManifestReport` returns `hasManifest() === false` / `isAiGenerated() === false`
for an empty manifest store, and the unit test
`tests/Unit/Reading/SigningServiceReaderTest.php:142` pins it against a mocked
HTTP 200 with an empty store body.

The **running signing service does not honour this contract.** `POST /v1/read`
in `service/server.js` does:

```js
const reader = await Reader.fromAsset({ buffer: fileBuffer, mimeType: mime_type });
const json = reader.json();   // reader is null for a manifest-less asset → throws
```

`Reader.fromAsset()` returns `null` when the asset has no C2PA data, so
`reader.json()` throws and the handler returns **HTTP 500**
`{"error":"Cannot read properties of null (reading 'json')"}`. The PHP client
faithfully turns that 500 into a `ReadFailedException`.

This was found by the integration property test
`tests/Integration/Property/ProvenanceChainPropertyTest.php` ("keeps the Article
50 marking intact across any chain of signings and reads"): a generated command
sequence that begins with a `read` on the unsigned fixture triggers the 500. The
unit test missed it because its mock was more forgiving than the real service.
See NOTES.md, Step 7.

Reading unsigned/untagged assets is a first-class path: a caller checks *whether*
an asset is marked before deciding what to do. It must not fault.

## Scope

**In scope**

- `service/server.js` `POST /v1/read`: when `Reader.fromAsset()` yields no reader
  (manifest-less asset), respond **HTTP 200** with an empty manifest store that
  the existing client parses into an empty `ManifestReport`.
- A test that drives this against the real service (integration group), and/or a
  service-level assertion, proving no 500 on a manifest-less read.

**Out of scope** (each needs its own spec before it may be built)

- Any change to the PHP client `SigningServiceReader` or to `ManifestReport`
  (they already implement the empty-store contract correctly; this spec brings
  the service in line with them).
- Behaviour for malformed/corrupt (not merely absent) manifest data.
- Non-PNG/JPEG asset types.

## Behavior

- **AC1 — manifest-less read is an empty report**
  - Given a valid PNG/JPEG asset that has never been signed
  - When it is read via `POST /v1/read`
  - Then the service responds HTTP 200 with a body the client parses into a
    report where `hasManifest() === false`, `isAiGenerated() === false`,
    `digitalSourceTypes() === []`, and no exception is thrown.

- **AC2 — a signed asset still reads back its manifest** *(regression guard)*
  - Given an asset signed with the AI-generated marking
  - When it is read via `POST /v1/read`
  - Then the active manifest and its `c2pa.actions.v2` marking are returned as
    before (SPEC-003 behaviour is unchanged).

- **AC3 — malformed input still fails cleanly** *(required: error path)*
  - Given a request whose `content` is not valid base64, or whose `mime_type`
    is unsupported
  - When it is read via `POST /v1/read`
  - Then the service responds HTTP 400 (unchanged), never a 500 — the empty-read
    path must not swallow genuine input errors.

## API sketch

Illustrative service-side guard (not binding):

```js
const reader = await Reader.fromAsset({ buffer: fileBuffer, mimeType: mime_type });
if (!reader) {
    return res.json({});      // no C2PA data → empty manifest store, HTTP 200
}
const json = reader.json();
res.json(typeof json === 'string' ? JSON.parse(json) : json);
```

The exact empty-store shape must be whatever `SigningServiceReader::parse()`
already treats as "no active manifest" (an object with no `active_manifest` /
`manifests`) — confirm against `readStoreResponse([])` in the unit test so the
service and client agree byte-for-byte.

## Open questions

- ~~Does `Reader.fromAsset()` return `null` or throw for a manifest-less asset?~~
  **Resolved:** it resolves to `null` (the 500 was `reader.json()` on null). A
  `if (!reader)` guard is the correct and minimal fix; no try/catch change needed.
- ~~Empty-store shape: `{}` vs explicit `{ manifests: {}, active_manifest: null }`?~~
  **Resolved:** `{}`. `SigningServiceReader::parse()` decodes it to an empty
  array with no `active_manifest`/`manifests`, yielding an empty `ManifestReport`
  (`hasManifest() === false`) — the same shape the unit test mocks with
  `readStoreResponse([])`.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 — manifest-less read is empty | `tests/Integration/ReadEmptyManifestTest.php` :: "reads an unsigned asset as an empty report, not an error" (`SPEC-010`, `integration`); also `tests/Integration/Property/ProvenanceChainPropertyTest.php` :: "keeps the Article 50 marking intact…" (`provenance`) | `service/server.js` `POST /v1/read` — `if (!reader) return res.json({})` |
| AC2 — signed asset still reads back | `tests/Integration/ReadEmptyManifestTest.php` :: "still reads back the AI marking from a signed asset" (`SPEC-010`, `integration`) | `service/server.js` `POST /v1/read` — unchanged reader path |
| AC3 — malformed input still 400 | `tests/Integration/ReadEmptyManifestTest.php` :: "rejects malformed read input with 400, not 500" (`SPEC-010`, `integration`) | `service/server.js` `POST /v1/read` — base64/mime 400 guards precede the try block |

Test support: `tests/Integration/ServiceHarness.php` (shared HTTP wiring). The
`integration` group is excluded from `composer check`; verify with
`vendor/bin/pest --group=SPEC-010` against a running service
(`docker-compose up -d --build`).