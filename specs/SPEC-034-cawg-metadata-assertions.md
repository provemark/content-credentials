# SPEC-034: CAWG metadata assertions

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — while draft                                     |
| Supersedes | — (extends SPEC-001 building, SPEC-003 reading)   |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

This package can state that an asset was made by a machine. It cannot state, in
the manifest, **who made it, who holds the rights, or on what terms it may be
used**. Those are different questions, and only the first is answered today.

The gap has an external reason to close. The AI Office's Code of Practice on
Transparency of AI-generated Content (10 June 2026) asks signatories to carry
richer metadata where technically feasible, and IPTC's summary of it points at
**CAWG metadata assertions inside C2PA manifests** as the vehicle. The
[CAWG metadata specification](https://cawg.io/metadata/1.1/) defines an assertion
labelled `cawg.metadata` that binds metadata drawn from established vocabularies
— XMP, IPTC, Exif — into a manifest tamper-evidently, as JSON-LD.

A caller can already do this by hand, and that is the actual problem. Nothing
stops them assembling a raw assertion and passing it through `extra_assertions`
— but they must get JSON-LD right unaided, discover the CAWG field names
themselves, and find out about a malformed payload from a signature that
verifies as valid while carrying metadata no reader will interpret. This library
exists precisely so that the *actions* assertion is not hand-assembled by every
caller (SPEC-001, and the deliberate divergence from the CAI wp-plugin contract
recorded in the primer §3). The same argument applies here and has simply not
been made yet.

### The premise this spec does not rest on

An earlier reading held that CAWG was **blocked upstream** and that this spec
could only record a wait. That was wrong, and it is recorded here because the
mistaken version nearly became the spec. Measured 2026-08-31:

- `service/server.js`, `rejectAssertions()` — there is **no label allow-list**.
  Any assertion with a non-empty string label passes, bounded only by count,
  nesting depth and serialised size. `cawg.metadata` is accepted today.
- `@contentauth/c2pa-node`, `dist/types/Builder.d.ts` —
  `addAssertion(label: string, assertion: unknown, assertionKind?)` takes an
  arbitrary label. There is nothing to wait for on the writing side.
- `ManifestReport::assertions()` returns the raw assertion list, so a
  `cawg.metadata` assertion already survives a round trip and comes back
  readable.

What *is* thin upstream is interpretation, not transport: a GitHub code search
for `cawg.metadata` across `contentauth/c2pa-rs` returns two hits — a label
constant in `sdk/src/assertions/labels.rs` and one builder test — so c2pa-rs
carries no typed decoder for it. **That asymmetry is this spec's real design
content**, not a blocker: we would be writing metadata that our own accessors
must interpret, because the engine beneath will hand it back as an opaque shape.

## Scope

**In scope**

- A typed builder producing a well-formed `cawg.metadata` assertion, carried to
  the service through the existing `extra_assertions` field — no service change
  and no wire-contract change.
- Validation of that assertion **before** signing: field names drawn from the
  CAWG specification, and the envelope bounds the service already enforces
  applied client-side so a caller learns of a problem before spending a
  signature.
- A typed read accessor exposing the metadata, alongside the existing raw
  `assertions()`.
- Documentation of which fields are supported and, explicitly, which are not.

**Out of scope** (each needs its own spec before it may be built)

- **The CAWG identity assertion.** A different mechanism with a different cost:
  it binds a verifiable identity to the claim and needs a second credential plus
  `signAsync`'s `IdentityAssertionSignerInterface`. SPEC-016 already names
  organisational identity as the natural next step and left
  `createCawgTrustSettings` out of SPEC-014 for the same reason.
- **CAWG trust settings on verification** — deciding whether an identity
  assertion is trusted. Follows the identity assertion, not this.
- **Imperceptible watermarking and C2PA soft bindings.** The second layer of the
  Code of Practice, documented in `docs/production.md` as deliberately outside
  this package: a watermark is a pixel-level change and must precede signing.
- **Inventing fields.** Only terms the CAWG specification defines may be
  emitted, on the same principle as SPEC-026: this package does not turn a
  caller's assertion into an attestation it cannot support.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-034')`.

- **AC1 — a metadata assertion is built and signed**
  - Given a manifest built for AI-generated content with CAWG metadata attached,
    naming a creator and a rights statement
  - When the asset is signed
  - Then the signed asset carries **exactly one** `cawg.metadata` assertion,
    alongside exactly one `c2pa.actions.v2` assertion, and the values are present
    verbatim — asserted against pinned strings, not against "not empty"

- **AC2 — it survives the round trip and is exposed typed**
  - Given an asset signed under AC1
  - When it is read back
  - Then a typed accessor returns the creator and rights values, and
    `assertions()` still contains the raw `cawg.metadata` shape — the typed view
    is additive and hides nothing

- **AC3 — absence is absence, not failure**
  - Given a validly signed asset carrying no `cawg.metadata` assertion
  - When it is read
  - Then the typed accessor returns an empty result and the read does **not**
    fail, matching the SPEC-003 contract for a manifest-less read

- **AC4 — an unknown field is refused at build time** *(error path)*
  - Given CAWG metadata naming a field the CAWG specification does not define
  - When the manifest is built
  - Then building throws before any request is made, naming the offending field;
    no signature is spent and no partial assertion is emitted

- **AC5 — the client enforces the service's own envelope bounds** *(error path)*
  - Given CAWG metadata that exceeds `MAX_ASSERTION_BYTES` or
    `MAX_ASSERTION_DEPTH`
  - When the manifest is built
  - Then building fails client-side with a message naming the bound, rather than
    surfacing as a 400 after the asset has been uploaded

- **AC6 — a malformed assertion reads as unparseable, not as absent** *(error path)*
  - Given an asset whose `cawg.metadata` assertion is present but structurally
    invalid (a crafted asset — untrusted input, per the parser rule in
    CLAUDE.md)
  - When it is read
  - Then the typed accessor reports it as unparseable rather than as absent, and
    the raw shape remains available through `assertions()`; the read itself does
    not throw

- **AC7 — both readers agree**
  - Given an asset signed under AC1
  - When it is read through `SigningServiceReader` and through `ExtC2paReader`
  - Then both return the same typed metadata, and the new accessor is added to
    `spec019Accessors()`, whose docblock promises every public accessor

## API sketch

Illustrative only. `strict_types=1`, value objects `readonly`, public API
`final`.

```php
// namespace Provemark\ContentCredentials\Core\Manifest;

final readonly class CawgMetadata
{
    public static function create(): self;

    public function withCreator(string $name): self;
    public function withRights(string $statement): self;
    public function withLicence(string $uri): self;

    /** @return array{label: string, data: array<string, mixed>} */
    public function toAssertion(): array;   // label: 'cawg.metadata'
}

$manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
    ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
    ->withCawgMetadata(
        CawgMetadata::create()
            ->withCreator('Example Studio')
            ->withRights('© 2026 Example Studio'),
    )
    ->build();

// Reading
$report->cawgMetadata()?->creator();   // 'Example Studio'
```

## Open questions

- **Which fields ship first?** CAWG binds XMP, IPTC and Exif — a large surface,
  and shipping all of it is not the way in. *Non-blocker*, leaning the
  creator/rights/licence subset that the Code of Practice's richer-metadata
  measure actually motivates, with the rest added on request rather than
  speculatively.
- **Validate structurally, or against the CAWG JSON-LD context?** Fetching a
  context at build time would put a network call in the builder, which the
  architecture does not otherwise have. *Non-blocker*, leaning a pinned local
  list of permitted field names — enough for AC4, no new runtime dependency.
- **Is the typed accessor ours alone to maintain?** c2pa-rs has no decoder, so
  unlike every other accessor this one interprets bytes rather than surfacing an
  engine's interpretation. If c2pa-rs later adds one and the two disagree, ours
  must yield. *Non-blocker*, but it belongs in the class docblock so the next
  reader does not assume engine backing.
- **Is this personal data?** Almost certainly, and more clearly than
  `creator_name`, which SPEC-012 AC10 already requires the README to flag. A
  creator name and rights statement are signed into an asset that travels, and
  cannot be retracted from copies. *Blocker for the docs, not for the build*:
  the page must say so before this ships.
- **Does an empty metadata object earn an assertion at all?** Emitting
  `cawg.metadata` with nothing in it costs manifest size and says nothing.
  *Non-blocker*, leaning refuse at build time, consistent with AC4's stance that
  this package does not emit assertions it cannot stand behind.

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