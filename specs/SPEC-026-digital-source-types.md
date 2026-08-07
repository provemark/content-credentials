# SPEC-026: The digitalSourceType family

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | 2026-08-07 (maintainer)                           |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`DigitalSourceType` has exactly one case, `trainedAlgorithmicMedia`, and
`ManifestBuilder::forAiGenerated()` hard-codes it. So this package can mark one
thing: media generated wholly by AI.

Article 50 is wider. Paragraph 2 requires marking AI-generated **or manipulated**
content; paragraph 4 adds the deepfake disclosure obligation, which is squarely
about manipulation of existing material. An application that edits a real
photograph with a generative model has an obligation and no way to meet it here.

The authenticity direction — a captured photograph — is the case that motivates
C2PA in the first place, and this package cannot express it. That turns out to be
correct rather than a gap; see Open questions, where it is settled that a web
application cannot honestly make that claim about bytes it was handed.

## What the research found, and why it reshapes this spec

NOTES Step 26 recorded a decision (form A: a family of named constructors) and
one thing to verify before writing this spec: **whether the manipulated case
differs only in `digitalSourceType`, or also in the action sequence.** Verified
2026-08-07 against the sources rather than reasoned about.

### It differs in the action sequence, and it needs ingredients

C2PA Implementation Guidance 2.4:

> "when an AI/ML performs an operation such as 'inpainting' (i.e., where a
> portion of an image is filled in based on a text prompt), the action could be
> recorded as a `c2pa.edited` or `c2pa.placed` action with the
> `digitalSourceType` set to […]"

and, for the asset being edited:

> "a `c2pa.opened` action pointing to an ingredient assertion for the original
> photo, where a `parentOf` relationship is indicated."

So the manipulated case is **not** one action with a different constant. It is:

1. `c2pa.opened` as the first action, referencing an **ingredient**;
2. an ingredient assertion for the original, with `relationship: parentOf`;
3. `c2pa.edited` (or `c2pa.placed`) carrying the AI `digitalSourceType`.

This package has no ingredient support at all. `ManifestBuilder` emits one
assertion; `service/server.js` passes `ingredients: []` to `Builder.withJson`.
Adding ingredients means the caller supplies the *original* asset (or its
manifest) so a hash can be computed over it — a new input to the whole signing
path, not a new value in an enum.

**That is why this spec splits the family rather than shipping it whole.**

### ⚠️ The guidance's spelling of the URI is wrong

The guidance quotes `compositedWithTrainedAlgorithmicMedia`. The IPTC vocabulary
at `cv.iptc.org/newscodes/digitalsourcetype/` has no such term. The registered
one is **`compositeWithTrainedAlgorithmicMedia`** — no "d". Implementing from the
guidance verbatim would produce a URI that resolves to nothing, in the assertion
whose whole purpose is machine readability.

Anything this spec ships takes its URIs from IPTC, never from prose about IPTC.

### And the IPTC definition is narrower than the C2PA docs suggest

The CAI documentation describes `compositeWithTrainedAlgorithmicMedia` as
"assets containing elements created by generative AI". IPTC defines it as:

> "Augmentation, correction or enhancement using a Generative AI model, such as
> with inpainting or outpainting operations"

Those are different claims. For "a new asset mixing AI and non-AI elements" the
registered term is **`compositeSynthetic`** ("Mix or composite of several
elements, at least one of which is Generative AI"). Using the first where the
second is meant would overstate what happened, in a direction that matters:
"edited with AI" implies an original that was not.

### The vocabulary, as it actually stands (IPTC, fetched 2026-08-07)

Relevant to us, with IPTC's own definitions:

| Term | Definition |
|---|---|
| `trainedAlgorithmicMedia` | created algorithmically using an AI model trained on captured content |
| `compositeWithTrainedAlgorithmicMedia` | augmentation, correction or enhancement **using** a generative AI model |
| `compositeSynthetic` | mix or composite of several elements, at least one of which is generative AI |
| `algorithmicMedia` | created purely by an algorithm not based on sampled training data |
| `digitalCapture` | captured from real life with a digital camera or recording device |
| `computationalCapture` | multiple captured frames merged by signal processing and/or non-generative AI |
| `algorithmicallyEnhanced` | modification by algorithm without changing the main content |
| `humanEdits` | augmentation or enhancement by humans using non-generative tools |
| `digitalCreation` | created by a human using non-generative tools |

Retired, and therefore never to be emitted: `minorHumanEdits`, `digitalArt`,
`softwareImage`.

## Scope

**In scope**

- `DigitalSourceType` becomes **the vocabulary**, and `ManifestBuilder` becomes
  **the policy**. The enum gains the two emittable synthetic cases —
  `compositeSynthetic` (a mix, at least one element generative AI) and
  `algorithmicMedia` (purely algorithmic, not trained on sampled data), both of
  which ride on the existing single `c2pa.created` action — *and* the three
  editing cases the builder refuses (`compositeWithTrainedAlgorithmicMedia`,
  `algorithmicallyEnhanced`, `humanEdits`).
- *(Amendment made while this spec was still `draft`, 2026-08-07. The first
  version had the enum gain only the two emittable cases while AC4 required the
  builder to refuse the other three — which cannot be expressed if they are not
  in the enum. Splitting vocabulary from policy resolves it, and is better: the
  refusal can then say **why**, where an absent constant only says "no such
  thing". A caller who reaches for the editing term learns that it needs
  ingredients, which is the fact worth conveying.)*
- Named constructors in the form settled in NOTES Step 26:
  `ManifestBuilder::forSynthetic()`, `::forAlgorithmic()`, and a general
  `::forSourceType(DigitalSourceType, MediaType)` behind them.
- The service's `REQUIRE_AI_MARKING` policy, which today tests for exactly
  `trainedAlgorithmicMedia`, must keep meaning what it says.
- README, primer and CHANGELOG, including what each term claims — because
  choosing the wrong one is a false statement about an asset, not a style error.

**Out of scope** (each needs its own spec before it may be built)

- **Emitting the manipulated case** — `compositeWithTrainedAlgorithmicMedia`,
  `algorithmicallyEnhanced`, `humanEdits`. All three describe an operation on an
  existing asset, so all three need `c2pa.opened` + an ingredient +
  `c2pa.edited`. That is ingredient support, and it is a larger piece of work
  than this whole spec. They are *declared* here so the refusal can name them;
  building a manifest with them is what a later spec adds.
- **Every authenticity claim** — `digitalCapture`, `computationalCapture`,
  `digitalCreation`, the film and print terms. Settled before approval: they do
  not belong in this package. See Open questions; the short version is that this
  package exists to mark AI involvement, and a PHP web application asserting that
  a photograph was *captured*, or that a human made something without generative
  tools, is asserting something it has no way to know.
- Ingredients generally: a second asset as input, its hash, `parentOf`
  relationships, and what the service does with them.
- Multiple actions in one manifest. `ManifestBuilder` emits one, and every
  criterion here keeps it that way.
- CAWG identity assertions (SPEC-016's territory).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-026')`.

- **AC1 — every declared source type produces a well-formed manifest**
  - Given each created-time `DigitalSourceType`
  - When a manifest is built and signed
  - Then it carries exactly one `c2pa.actions.v2` assertion whose first and only
    action is `c2pa.created` with that `digitalSourceType`
  - And it reads back `Valid` with the marking intact

- **AC2 — the URIs are IPTC's, verbatim**
  - Given the enum, emittable and refused cases alike
  - When each case's value is compared with the IPTC vocabulary
  - Then it matches exactly, including `compositeWithTrainedAlgorithmicMedia`
    having no "d"
  - And no retired term (`minorHumanEdits`, `digitalArt`, `softwareImage`) is
    present
  - *(The guidance document itself misspells one of these. A test that pins the
    exact strings is the only thing standing between a typo and an assertion
    that claims nothing resolvable.)*

- **AC3 — the AI-generated path is unchanged**
  - Given `forAiGenerated()`
  - When it builds a manifest
  - Then the result is byte-identical to what SPEC-001 fixes
  - *(This spec widens what can be said; it must not disturb the one thing that
    is already said correctly, and which regressions are critical in.)*

- **AC4 — a source type that needs an ingredient is refused** *(error path)*
  - Given `compositeWithTrainedAlgorithmicMedia`, `algorithmicallyEnhanced` or
    `humanEdits`
  - When it is offered to the builder
  - Then it throws, naming ingredients as the missing capability and this spec's
    out-of-scope decision as the reason
  - *(Rather than emitting a `c2pa.created` action with an editing source type,
    which would be a well-formed manifest making a false claim: that the asset
    was created by an operation which by definition acts on an existing one.)*

- **AC5 — `REQUIRE_AI_MARKING` still means what it says**
  - Given a service configured with `REQUIRE_AI_MARKING=true`
  - When a manifest with `algorithmicMedia` is offered
  - Then it is refused
  - *(`algorithmicMedia` is synthetic but explicitly **not** trained on sampled
    data, so it is the sharpest test of that policy: near enough to AI marking to
    be mistaken for it, and not what the policy names.)*
  - *(The policy exists for deployments whose certificate marks AI content only.
    Widening the enum must not quietly widen what that policy accepts.)*

- **AC6 — the documentation says what each term claims**
  - Given the README
  - When someone chooses a source type
  - Then each is given with IPTC's own definition, and the difference between
    `compositeSynthetic` and `compositeWithTrainedAlgorithmicMedia` is stated
  - *(The second is the one people will reach for by name and it means
    "edited with AI". Choosing it for a composite is a false statement about
    provenance.)*

- **AC7 — the reading side keeps its meaning, and gains one** *(amendment,
  2026-08-07, before implementation)*
  - Given a manifest marked `compositeSynthetic` or `algorithmicMedia`
  - When it is read back
  - Then `isAiGenerated()` is **false** for both, unchanged: it means exactly
    `trainedAlgorithmicMedia` and SPEC-013 is the record of what a too-permissive
    predicate costs
  - And a new `involvesGenerativeAi()` is **true** for `trainedAlgorithmicMedia`
    and `compositeSynthetic`, and **false** for `algorithmicMedia` — which is the
    whole point of that term: synthetic, but no model, no training data
  - *(Found while scoping the implementation: shipping the writing side without
    deciding the reading side would leave a caller who marks `compositeSynthetic`
    unable to detect it except by string-matching `digitalSourceTypes()`. Widening
    `isAiGenerated()` instead was rejected: it gates Article 50 decisions in code
    already written against it, and silently changing what it answers is the
    failure SPEC-013 exists to remember.)*

## API sketch

Illustrative only.

```php
enum DigitalSourceType: string
{
    case TrainedAlgorithmicMedia = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';
    case CompositeSynthetic = 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeSynthetic';
    case AlgorithmicMedia = 'http://cv.iptc.org/newscodes/digitalsourcetype/algorithmicMedia';
    // No capture or human-authorship terms: see Scope.
}
```

```php
ManifestBuilder::forAiGenerated(MediaType::Png)   // unchanged, SPEC-022
ManifestBuilder::forSynthetic(MediaType::Png)     // compositeSynthetic
ManifestBuilder::forAlgorithmic(MediaType::Png)   // algorithmicMedia
ManifestBuilder::forSourceType(DigitalSourceType::CompositeSynthetic, MediaType::Png)
```

## Open questions

- ~~**Does `forAiManipulated()` stay reserved?**~~ **Settled before approval: no
  name is added now.** The family settled in NOTES Step 26 assumed it would
  arrive alongside the others; it cannot, because it needs ingredients. A
  constructor that throws is worse than one that does not exist — it looks like a
  supported path with a bug, and an IDE will offer it for completion. AC4's
  exception, raised from `forSourceType()`, names the gap where someone actually
  meets it.

- ~~**Should `forSourceType()` be public at all?**~~ **Settled before approval:
  public.** It is the general form under the named ones, and exposing it means a
  caller can pass any case — including the editing ones, which is exactly where
  AC4's refusal has to live for it to be reachable at all. The alternative, named
  constructors only, means a new constructor for every term IPTC registers, and
  IPTC has registered three since 2024.

- ~~**Does the authenticity case belong to this package at all?**~~ **Settled
  before approval, 2026-08-07: no, not in this package, not now.**

  The consequence is larger than the one term that prompted the question.
  `digitalCapture` and `computationalCapture` are claims that a real device
  recorded something; `digitalCreation` is a claim that a human made something
  without generative tools; the film and print terms are claims about a physical
  original. **A PHP web application cannot know any of them.** It receives bytes.
  Whatever it asserts about their origin, it is repeating something it was told —
  and a C2PA assertion is signed with a certificate, which turns hearsay into
  attestation.

  That is not an argument against ever supporting them. It is an argument that
  they need a different position than "an enum case you can pass": the caller
  would have to be the capture device or vouch for it, and this package would
  have to say so loudly. Until something is asking for that, the enum stays
  restricted to what an application generating media can honestly assert about
  its own output.

  What remains is coherent: this package marks **synthetic** media. AI-generated
  (`trainedAlgorithmicMedia`), a mix containing generative AI
  (`compositeSynthetic`), and purely algorithmic without training data
  (`algorithmicMedia`) — the last of which is useful precisely because it is a
  *negative* claim about AI involvement, distinguishing procedural output from
  generative output rather than letting both go unmarked.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/Manifest/DigitalSourceTypeTest.php` :: "emits one c2pa.created action carrying the requested source type", "offers a named constructor for each emittable type"; `tests/Integration/DigitalSourceTypeTest.php` :: "signs and reads back trainedAlgorithmicMedia", "signs and reads back compositeSynthetic", "signs and reads back algorithmicMedia" | `src/Core/Manifest/ManifestBuilder.php` `forSourceType()`, `forSynthetic()`, `forAlgorithmic()` |
| AC2 | `tests/Unit/Manifest/DigitalSourceTypeTest.php` :: "carries the exact IPTC URI for every case", "spells the composite term without the d the guidance adds", "declares no term IPTC has retired" | `src/Core/Manifest/DigitalSourceType.php` |
| AC3 | `tests/Unit/Manifest/DigitalSourceTypeTest.php` :: "leaves the AI-generated manifest byte-identical to what SPEC-001 fixes" | `src/Core/Manifest/ManifestBuilder.php` `forAiGenerated()` |
| AC4 | `tests/Unit/Manifest/DigitalSourceTypeTest.php` :: "refuses a source type that describes an operation on an existing asset", "names ingredients as the missing capability when refusing", "refuses rather than emitting a created action for an editing term" | `src/Core/Manifest/DigitalSourceType.php` `requiresIngredient()`, `src/Core/Manifest/Exception/UnsupportedSourceTypeException.php` |
| AC5 | `tests/Integration/DigitalSourceTypeTest.php` :: "refuses algorithmicMedia when the service requires AI marking", "still signs trainedAlgorithmicMedia when the service requires AI marking" | `service/server.js` `REQUIRE_AI_MARKING` (unchanged) |
| AC6 | `tests/Unit/SourceTypeGuidanceTest.php` :: all five | `README.md` § What you are claiming, `docs/c2pa-primer.md` §10 |
| AC7 | `tests/Unit/Reading/GenerativeAiDetectionTest.php` :: all four; `tests/Integration/DigitalSourceTypeTest.php` :: "reads a compositeSynthetic asset as generative but not AI-generated", "reads an algorithmicMedia asset as neither" | `src/Core/Reading/ManifestReport.php` `involvesGenerativeAi()`, `src/Core/Manifest/DigitalSourceType.php` `involvesGenerativeAi()` |

## Implementation notes (2026-08-07)

- **Two amendments while still `draft`, both found by trying to write the code.**
  The first version had the enum gain only the emittable cases while AC4 required
  the builder to refuse three others — which cannot be expressed if they are not
  in the enum. Splitting vocabulary (enum) from policy (builder) resolved it, and
  is better: the refusal can say *why*, where an absent constant only says "no
  such thing". The second was AC7: shipping the writing side without deciding the
  reading side would have left a caller who marks `compositeSynthetic` unable to
  detect it except by string-matching.
- **AC1 and AC5 describe configurations that cannot coexist**, exactly like
  SPEC-014's trust-on/trust-off split. A service with `REQUIRE_AI_MARKING=true`
  refuses the non-AI terms *by design*, so the round-trip criteria skip there and
  AC5 skips everywhere else. The `defaults` and `hardened` CI profiles cover one
  half each; no new profile was needed.
- **`isAiGenerated()` was deliberately not widened.** It gates Article 50
  decisions in code already written against it, and SPEC-013 is the record of what
  a predicate that quietly answers more than it says costs. `involvesGenerativeAi()`
  is additive and explicit instead.
- **Verified beyond our own reader**: a signed `compositeSynthetic` and
  `algorithmicMedia` asset each inspected with `c2patool` under trust settings —
  `c2pa.created`, the exact IPTC URI, `validation_state: Trusted`, no status
  codes.
- **The AC6 phrases were checked against `origin/main` before trusting the
  green**, the same way SPEC-025 AC6 was: all six were absent, so the tests would
  have failed on the previous revision.
