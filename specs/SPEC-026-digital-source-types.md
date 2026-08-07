# SPEC-026: The digitalSourceType family

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — (draft)                                         |
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

- `DigitalSourceType` gains the two **created-time synthetic** cases:
  `compositeSynthetic` (a mix, at least one element generative AI) and
  `algorithmicMedia` (purely algorithmic, not trained on sampled data). Both
  describe how an asset came into being, so both ride on the existing single
  `c2pa.created` action.
- Named constructors in the form settled in NOTES Step 26:
  `ManifestBuilder::forSynthetic()`, `::forAlgorithmic()`, and a general
  `::forSourceType(DigitalSourceType, MediaType)` behind them.
- The service's `REQUIRE_AI_MARKING` policy, which today tests for exactly
  `trainedAlgorithmicMedia`, must keep meaning what it says.
- README, primer and CHANGELOG, including what each term claims — because
  choosing the wrong one is a false statement about an asset, not a style error.

**Out of scope** (each needs its own spec before it may be built)

- **The manipulated case** — `compositeWithTrainedAlgorithmicMedia`,
  `algorithmicallyEnhanced`, `humanEdits`. All three describe an operation on an
  existing asset, so all three need `c2pa.opened` + an ingredient +
  `c2pa.edited`. That is ingredient support, and it is a larger piece of work
  than this whole spec.
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
  - Given the enum
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

- **Does `forAiManipulated()` stay reserved?** The family settled in NOTES Step
  26 assumed it would arrive alongside the others. It cannot, because it needs
  ingredients. Recommendation: **do not add the name now**, and let AC4's
  exception name the gap. A constructor that throws is worse than one that does
  not exist — it looks like a supported path with a bug.

- **Should `forSourceType()` be public at all?** It is the general form under the
  named ones, and exposing it means a caller can pass any case, including ones a
  future spec adds for the edited path. Recommendation: **public**, with AC4
  guarding the cases that need ingredients. The alternative — named constructors
  only — means a new constructor for every term IPTC registers, and IPTC has
  registered three new ones since 2024.

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
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |