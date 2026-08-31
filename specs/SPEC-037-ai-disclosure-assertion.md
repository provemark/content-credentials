# SPEC-037: The AI Disclosure assertion

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — while draft                                     |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

This package can say that an asset was made by a machine. It cannot say **how
much of a person was involved** in making it.

C2PA 2.4 §18.28 introduces `c2pa.ai-disclosure`, an assertion the specification
positions for *"verifiable AI transparency, automated compliance verification,
and trustworthy AI content at scale"* — and which it says **complements**
`digitalSourceType` rather than replacing it. §18.29 sets the two side by side:

| `digitalSourceType` | `humanOversightLevel` | Interpretation |
|---|---|---|
| `trainedAlgorithmicMedia` | `fully_autonomous` | Pure AI generation, no human review |
| `trainedAlgorithmicMedia` | `prompt_guided` | AI generation guided by human prompts |
| `compositeWithTrainedAlgorithmicMedia` | `human_validated` | AI edits reviewed and approved by a human before release |

The first column is what this package emits today. The second is the axis it
cannot express — and it is the one a regulator asks about. Two assets both
marked `trainedAlgorithmicMedia` can differ entirely in how much human judgement
stood behind them, and nothing we sign distinguishes them.

That is a genuine gap in exactly this package's subject. **It is also not a
reason to build it yet**, for three measured reasons, which is what this spec
exists to record.

### Measurements this spec rests on

All 2026-08-31, against the 2.4 specification text and `c2patool` 0.27.16
(c2pa-rs 0.90.16).

- **We can emit it today.** An `c2pa.ai-disclosure` assertion passed through
  `extra_assertions` signs and round-trips verbatim —
  `{"contentProfile":{"humanOversightLevel":"prompt_guided"},"modelName":"…","modelType":"c2pa.types.model.onnx"}`
  — with `validation_state: Valid`. Transport is **not** the obstacle, so nothing
  here is a wait for capability.
- **The schema is explicitly unfinished.** The CDDL in §18.29.1 carries, in the
  specification's own words: *"The remaining fields for the AI model disclosure
  metadata are pending further specification and will be added once descriptions
  and requirements are provided"*, with `modelFrontier`, `trainingCleared` and
  `category` present as commented-out placeholders.
- **The one mandatory field asks something our caller usually cannot know.**
  `modelType` is required and must come from Table 9, which is a **machine
  learning framework** taxonomy: `c2pa.types.model.onnx`, `.keras`, `.jax`,
  `.coreml`, `.huggingface.transformers`, `.caffe`, `.mxnet`, `.lightgbm` and
  the rest. A caller who received an image from a generation API does not know
  which of those sits behind it.
- **Upstream has no support at all.** A code search across
  `contentauth/c2pa-rs` for `ai-disclosure` and `ai_disclosure` returns **zero**
  hits, and `@contentauth/c2pa-node` 0.9.1 has nothing in its distributed types.
  Nothing validates it, nothing decodes it — we would be alone with it, the same
  position SPEC-034 records for CAWG metadata.

### Why the mandatory field is the deciding one

`modelType` is not merely inconvenient. It is the same shape as the
`digitalSourceType` authenticity terms SPEC-026 refuses to emit: **a field that
invites a caller to attest to something they are not in a position to know.**

A web application receives bytes from an API. Asking it to declare ONNX or JAX
is asking it to guess, and a guess signed into a manifest is worse than a
silence — that is the argument the primer §10 already makes about
`digitalCapture` and friends, and it applies here unchanged.

The honest exception: a caller running its **own** model — a self-hosted ONNX
export, say — does know. So the field is not unknowable, it is unknown in the
common case. A future version of this spec could serve only that caller, and
should say so rather than pretending the field is generally answerable.

## Scope

**In scope** (when the triggers below fire)

- A typed builder for `c2pa.ai-disclosure`, carried through the existing
  `extra_assertions` field — no service change and no wire-contract change.
- `humanOversightLevel` as the value this is actually for.
- A typed read accessor, and its addition to `spec019Accessors()`.
- Refusing values outside the specification's enumerations, before signing.

**Out of scope** (each needs its own spec before it may be built)

- **Inferring `humanOversightLevel`.** This package receives bytes and cannot
  know whether a person reviewed the output. It can only relay a caller's
  statement, and the documentation must say so — the same division SPEC-036
  settled for `created`: contents are the caller's, structure is ours.
- **Guessing `modelType`.** If a caller cannot name it, this package must refuse
  rather than default to `c2pa.types.model` (the "not described by any other
  model type" catch-all), which would convert ignorance into an attestation.
- The other 2.4 assertions — `c2pa.repository-receipt`,
  `c2pa.environmental-sustainability` — which share nothing with this package's
  subject.
- `scientificDomain`. An arXiv-taxonomy list is meaningful for research output
  and meaningless for the assets this package signs.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-037')`.

These describe the assertion's **shape**, which the pending schema fields do not
touch: `modelFrontier`, `trainingCleared` and `category` are additions, and when
they land they arrive as an amendment rather than a rewrite. Writing the criteria
now is what makes the deferral a decision rather than an absence.

- **AC1 — the assertion is built and signed alongside the marking**
  - Given a manifest for AI-generated content with an AI disclosure attached,
    naming a model type and a human oversight level
  - When the asset is signed
  - Then the signed asset carries exactly one `c2pa.ai-disclosure` assertion
    beside exactly one `c2pa.actions.v2`, and the values are present verbatim —
    pinned as exact strings, not asserted non-empty

- **AC2 — human oversight is restricted to what the specification defines** *(error path)*
  - Given a human oversight level outside `fully_autonomous`, `prompt_guided`
    and `human_validated`
  - When the manifest is built
  - Then building throws before any request is made, naming the offending value;
    no signature is spent

- **AC3 — a model type is required and never defaulted** *(error path)*
  - Given an AI disclosure with no model type
  - When the manifest is built
  - Then building throws. It must **not** fall back to `c2pa.types.model`, the
    "not described by any other model type" catch-all: defaulting would convert
    a caller's ignorance into a signed attestation, which is the objection
    SPEC-026 and the primer §10 already make about authenticity source types

- **AC4 — it reads back typed, and absence is absence**
  - Given an asset carrying the assertion, and a second carrying none
  - When both are read
  - Then the first reports the model type and oversight level, and the second
    reports absence without failing, per the SPEC-003 contract

- **AC5 — a malformed assertion reads as unparseable, not as absent** *(error path)*
  - Given a crafted asset whose `c2pa.ai-disclosure` is present but structurally
    invalid — untrusted input, per the parser rule this project applies to every
    manifest it did not produce
  - When it is read
  - Then it is reported as unparseable rather than as missing, the raw shape
    stays available through `assertions()`, and the read does not throw

- **AC6 — both readers agree**
  - Given an asset signed under AC1
  - When it is read through `SigningServiceReader` and through `ExtC2paReader`
  - Then both return the same typed result, and the new accessor is added to
    `spec019Accessors()`, whose docblock promises every public accessor

## API sketch

Illustrative only, and deliberately thin while the schema moves.

```php
// namespace Provemark\ContentCredentials\Core\Manifest;

$manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
    ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
    ->withAiDisclosure(
        AiDisclosure::forModel(ModelType::Onnx)          // required, never guessed
            ->withName('ACME GenAI Image Model')
            ->withHumanOversight(HumanOversight::PromptGuided),
    )
    ->build();
```

## When this becomes worth building

Written down so the research survives, not as a commitment to build — the same
standing SPEC-016 and SPEC-034 hold.

**Subject-matter fit is not a trigger.** This assertion is about AI transparency
and so is this package; that is exactly the reasoning that would make us build
something unfinished because it looked relevant. SPEC-034's trigger section
exists for the same temptation.

Any one of these is the signal:

- **The pending fields land and the schema stabilises.** The CDDL says three are
  outstanding. Shipping a typed builder before then means shipping public API
  that changes underneath callers, which this package does not do.
- **A user asks to record human oversight.** Nobody has. Reach is early; the
  baseline is in NOTES Step 51, and it should be read rather than restated from
  here.
- **c2pa-rs gains support.** Today nothing upstream validates or decodes this,
  so we would be the only thing in the stack able to read back what we wrote —
  and would carry that alone.

Until then the honest position is that we understand it and it is not time.

## Open questions

- **Does `humanOversightLevel` alone justify the assertion?** It is the field
  with real value here, and it is optional in the schema while `modelType` — the
  field our callers usually cannot supply — is mandatory. So the useful part is
  gated behind the unanswerable part. *Blocker*, and possibly grounds for asking
  C2PA whether that gating is intended.
- **Is this the right vehicle for Article 50 at all?** The Code of Practice asks
  for signed metadata and imperceptible watermarking; it does not ask for this
  assertion, and `digitalSourceType` already carries the marking the regulation
  names. This may be a richer answer to a question nobody is asking us yet.
  *Non-blocker*, but it belongs in the decision.
- **Would we serve only self-hosting callers?** They can supply `modelType`
  truthfully. A spec that says so plainly is more honest than one that pretends
  the field is generally answerable. *Non-blocker*, leaning: say it plainly.

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