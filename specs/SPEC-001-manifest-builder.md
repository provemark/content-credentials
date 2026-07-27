# SPEC-001: Core manifest builder (AI-generated image)

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

The library's primary job (CLAUDE.md) is machine-readable marking of
AI-generated content under EU AI Act, Article 50. That marking is a single C2PA
actions assertion, and getting its shape exactly right is a **critical**
concern — the domain rules call regressions here critical, and the same
assertion doubles as the claim-v2 well-formedness requirement.

Today only the throwaway spike (`bin/spike.php`) knows how to build that
structure, as an inline array literal. Nothing in `src/` produces it, nothing is
type-checked, and nothing guarantees the invariants from
`docs/c2pa-primer.md` §1–2:

- manifests are **claim v2**; the actions label is **`c2pa.actions.v2`**;
- the **first action MUST be `c2pa.created` or `c2pa.opened`**, else
  `assertion.action.malformed: "first action must be created or opened"`;
- the AI-generated marking is a `c2pa.created` action carrying
  `digitalSourceType =
  http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia`
  and a `softwareAgent { name }`.

This spec defines a framework-agnostic Core component that builds exactly that
manifest, correct by construction, with the invariants enforced and tested.

## Scope

**In scope**

- A **fluent, immutable** builder under `ContentCredentials\Core\Manifest` that
  produces an immutable `Manifest` value object representing a **claim-v2
  manifest definition** for an **AI-generated image**.
- The manifest carries **exactly one** assertion: a `c2pa.actions.v2` assertion
  whose **first (and only) action** is `c2pa.created` with
  `digitalSourceType` = the trainedAlgorithmicMedia IPTC URI (verbatim) and a
  `softwareAgent { name, version? }`.
- **PNG and JPEG only**, modelled as a `MediaType` type; any other format is
  rejected with a typed domain exception.
- Optional `claim_generator_info` (name + version) on the manifest definition.
- Deterministic serialization to a JSON-ready array (`toArray()`), suitable as
  the input a later Signing component will map onto the service `/v1/sign`
  request (that mapping is a separate spec).
- Input validation surfaced as typed exceptions implementing a shared Core
  exception interface.

**Out of scope** (each needs its own spec before it may be built)

- Signing and any HTTP/transport to `service/` (mapping `Manifest` →
  `/v1/sign`) — a Signing spec (`SignerInterface` / `SigningServiceSigner`).
- Reading / verification of an existing manifest — a Reading spec.
- Any action other than a single `c2pa.created` (edits, `c2pa.opened`,
  `c2pa.placed`), multiple assertions, or ingredients.
- `digitalSourceType` values other than trainedAlgorithmicMedia (e.g.
  compositedWithTrainedAlgorithmicMedia) — a future enum/spec.
- Asset types beyond PNG/JPEG (MP4, WAV, …).
- Thumbnails: `c2pa-node` auto-adds `c2pa.thumbnail.claim` at sign time
  (primer §1); the builder MUST NOT add a thumbnail assertion itself.
- CAWG identity assertions, TSA timestamping.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-001')`. AC3 and AC4 are the
required error / malformed-input paths.

- **AC1 — builds the AI-generated marking for PNG**
  - Given a builder for an AI-generated image with media type PNG and a
    software agent named `"ACME GenAI Image Model"` version `"3.1.0"`
  - When the manifest is built and serialized with `toArray()`
  - Then `format` is `"image/png"`; and `assertions` contains **exactly one**
    entry whose `label` is `"c2pa.actions.v2"`; and that assertion's
    `data.actions` has **exactly one** action; and `actions[0].action` is
    `"c2pa.created"` (satisfying the claim-v2 first-action rule); and
    `actions[0].digitalSourceType` equals, byte-for-byte,
    `"http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia"`;
    and `actions[0].softwareAgent` is `{ "name": "ACME GenAI Image Model",
    "version": "3.1.0" }`.

- **AC2 — supports JPEG and omits an unset optional version**
  - Given a builder for an AI-generated image with media type JPEG and a
    software agent named `"X"` with **no** version
  - When serialized with `toArray()`
  - Then `format` is `"image/jpeg"`; and `actions[0].softwareAgent` is
    `{ "name": "X" }` with **no** `version` key present (absent, not null).

- **AC3 — rejects an unsupported media type** *(required error path)*
  - Given an attempt to select a media type from the MIME string
    `"image/gif"` (equally: `image/webp`, `application/pdf`)
  - When the media type is resolved
  - Then an `UnsupportedMediaTypeException` (implementing the Core exception
    interface) is thrown, its message naming the offending type; and **no**
    `Manifest` is produced.

- **AC4 — rejects an empty software-agent name** *(required error path)*
  - Given a builder configured with a software-agent name that is empty or
    only whitespace
  - When `build()` is called
  - Then an `InvalidSoftwareAgentException` (implementing the Core exception
    interface) is thrown; and **no** `Manifest` is produced. (Article 50
    marking and the domain rule both require an identifiable `softwareAgent`
    name; a blank name is a malformed manifest, not a silent default.)

- **AC5 — the builder is immutable / fluent**
  - Given a builder instance `b1` on which `withSoftwareAgent(...)` is called,
    returning `b2`
  - When both are inspected
  - Then `b2` is a **distinct** instance from `b1`, and mutating operations
    return new instances rather than modifying the receiver (calling a `with*`
    method never changes the state observable through the original reference).

- **AC6 — the AI marking URI is fixed and not caller-overridable**
  - Given any successfully built AI-generated manifest
  - When `toArray()` is inspected
  - Then `actions[0].digitalSourceType` is always the trainedAlgorithmicMedia
    URI above — there is no public API path that yields a different
    `digitalSourceType` for this builder (guards the "critical regression"
    domain rule).

## API sketch

Illustrative only — not binding. All files `declare(strict_types=1)`; public
API `final` with interfaces; value objects `readonly`; PHPStan level max.

```php
namespace ContentCredentials\Core\Manifest;

/** Supported asset formats for v1 (PNG, JPEG only). */
enum MediaType: string
{
    case Png  = 'image/png';
    case Jpeg = 'image/jpeg';

    /** @throws \ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException */
    public static function fromMimeType(string $mime): self;
}

/** The AI digitalSourceType this builder emits. Single case by design (SPEC-001). */
enum DigitalSourceType: string
{
    case TrainedAlgorithmicMedia =
        'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';
}

final readonly class SoftwareAgent
{
    public function __construct(public string $name, public ?string $version = null);
    /** @return array{name: string, version?: string} */
    public function toArray(): array;
}

/**
 * Immutable, fluent builder for a claim-v2 AI-generated-image manifest.
 * Every with* method returns a NEW instance.
 */
final class ManifestBuilder
{
    public static function forAiGeneratedImage(MediaType $type): self;

    public function withSoftwareAgent(string $name, ?string $version = null): self;
    public function withClaimGenerator(string $name, ?string $version = null): self;

    /** @throws \ContentCredentials\Core\Manifest\Exception\InvalidSoftwareAgentException */
    public function build(): Manifest;
}

/** Immutable manifest definition (claim v2). */
final readonly class Manifest
{
    /**
     * @return array{
     *   claim_generator_info?: list<array{name: string, version?: string}>,
     *   format: string,
     *   assertions: list<array{label: string, data: array<string, mixed>}>
     * }
     */
    public function toArray(): array;

    /** Just the assertions array (maps to /v1/sign `extra_assertions` later). */
    public function assertions(): array;
}
```

```php
namespace ContentCredentials\Core\Support;

/** Marker interface: every exception this library throws implements it. */
interface ContentCredentialsException extends \Throwable {}
```

```php
namespace ContentCredentials\Core\Manifest\Exception;

use ContentCredentials\Core\Support\ContentCredentialsException;

final class UnsupportedMediaTypeException
    extends \InvalidArgumentException
    implements ContentCredentialsException {}

final class InvalidSoftwareAgentException
    extends \InvalidArgumentException
    implements ContentCredentialsException {}
```

Example (happy path):

```php
$manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Png)
    ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
    ->withClaimGenerator('Content Credentials', '0.1.0')
    ->build();

$manifest->toArray();
// [
//   'claim_generator_info' => [['name' => 'Content Credentials', 'version' => '0.1.0']],
//   'format' => 'image/png',
//   'assertions' => [[
//     'label' => 'c2pa.actions.v2',
//     'data' => ['actions' => [[
//       'action' => 'c2pa.created',
//       'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
//       'softwareAgent' => ['name' => 'ACME GenAI Image Model', 'version' => '3.1.0'],
//     ]]],
//   ]],
// ]
```

## Decisions (resolved at approval, 2026-07-27)

The draft's open questions were resolved as proposed; recorded here so the
approved spec is self-contained.

- **D1 — claim_generator_info ownership.** Core owns `claim_generator_info`
  (as sketched). The later Signing layer maps/overrides it from the service
  `creator_name`; Core does not know about `/v1/sign`.
- **D2 — `MediaType::fromMimeType` strictness.** Resolve by trimming
  whitespace, lowercasing, and stripping any `;`-parameters (e.g.
  `image/jpeg; charset=…` → `image/jpeg`), then exact-matching a supported
  type. Anything else → `UnsupportedMediaTypeException` (AC3).
- **D3 — `withSoftwareAgent` is mandatory.** There is no valid Article 50
  marking without an identifiable software agent, so `build()` without a
  (non-blank) software-agent name throws `InvalidSoftwareAgentException`
  (AC4). No "anonymous agent" use case.
- **D4 — Core boundary.** `Manifest` exposes both `toArray()` (JSON-ready
  array) and `assertions()`. Core emits **no JSON string**; serialization to
  the wire is the Signing layer's job.

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
