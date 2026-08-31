# SPEC-036: Raising the declared specification version to 2.4

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — while draft                                     |
| Supersedes | — (raises the value SPEC-035 declares)            |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-035 declares `2.3.0` and put raising that value out of scope, on the
understanding that 2.4 was blocked upstream. **That understanding was wrong**,
and its second amendment records both the error and the measurement that
overturned it: 2.4's one binding requirement is met by a single field on the
assertion we already send.

So the reason for stopping at 2.3.0 has gone, and what remains is the ordinary
question — should we, and what does it take?

**Why it is worth doing.** The declaration is a signed statement about which
rules a manifest follows, and a verifier reading it learns more from a truthful
`2.4.0` than from a truthful `2.3.0`: 2.4 is the version that requires the
actions assertion to be *attributed to the signer* rather than merely relayed.
For a package whose entire purpose is one actions assertion saying "this was
made by a machine", the difference is not cosmetic. Under 2.3 that assertion may
sit in `gathered_assertions`, where the specification says the generator is
declaring it *"was not sourced from the claim generator and is not attributed to
the signer"* — which is close to the opposite of what we mean by it.

**Why it is not simply a version bump.** SPEC-035's audit was built from the
*version histories*, and that is how it missed a defect it should have caught
(see the thumbnail finding below). This spec re-audits against the **normative
text** of the specification, which is a different and slower piece of work.

### Measurements this spec rests on

All 2026-08-31. Placement read with `c2patool --detailed` 0.27.16 (c2pa-rs
0.90.16); the writing side exercised through **both** `c2patool` and
`Builder.withJson(manifest, settings)` in `@contentauth/c2pa-node` 0.9.1, which
is this package's own path.

- **`"created": true` on the actions assertion moves it, on the creation path.**
  `sdk/src/manifest_assertion.rs` carries `created: bool` with
  `#[serde(default)]`, so it deserialises from the manifest definition JSON we
  already send. Result: `created_assertions -> ['c2pa.actions.v2',
  'c2pa.hash.data']`, `validation_state: Valid`.
- **It also works on the manipulated path**, which was the open question, because
  c2pa-rs inserts the `c2pa.opened` action itself under the edit intent (SPEC-028
  AC13). Signing with `--parent` and the flag set gives
  `created_assertions -> ['c2pa.actions.v2', 'c2pa.hash.data']`, `Valid`.
- **The 2.4 requirement on `c2pa.opened` is already met.** 2.4 requires that
  action to carry a hashed-uri reference to its ingredient assertion; our
  manipulated manifests already emit
  `parameters.ingredients: [{url: "self#jumbf=c2pa.assertions/c2pa.ingredient.v3", hash: "…"}]`,
  built by c2pa-rs. Nothing to do.
- **The validator cannot confirm any of this.** Our pre-change manifests, with
  the actions assertion in `gathered_assertions`, validate as `Valid` with only
  `signingCredential.untrusted`. c2pa-rs does not check these placement rules, so
  **`Valid` is not evidence of conformance in either direction** — every
  criterion below has to be asserted structurally rather than inferred from a
  verdict.
- **What is left in `gathered_assertions` afterwards** is the open question this
  spec must answer: `c2pa.thumbnail.claim` on the creation path, and
  additionally `c2pa.thumbnail.ingredient` and `c2pa.ingredient.v3` on the
  manipulated path.

### The thumbnail finding, which is not about 2.4 at all

The `gathered_assertions` definition is **identical in 2.3 and 2.4**: the field
holds assertions *"provided to the claim generator by other components in the
workflow"*, with a NOTE that putting one there declares it *"was not sourced from
the claim generator and is not attributed to the signer"*.

The thumbnail c2pa-rs generates for us sits there, and it *was* sourced from the
claim generator. That contradiction exists in manifests this package signs
**today**, under the `2.3.0` declaration, and raising to 2.4 neither causes nor
cures it. It is upstream's default — `contentauth/c2pa-rs` #2106 tracks it, with
a `// todo: add setting for created added thumbnails` at the line responsible —
and it affects every c2pa-rs caller including c2patool itself.

We can remove it: `builder.thumbnail.enabled: false` works through our path and
leaves `gathered_assertions` **absent entirely**. Whether we should is a product
question, not a conformance one, and AC5 is where this spec answers it.

## Scope

**In scope**

- Emitting `"created": true` on the actions assertion, on both the creation and
  the manipulated paths.
- Re-auditing against the **normative text** of C2PA 2.4 — not its version
  history — for the requirements that touch what this package emits.
- Raising SPEC-035's declared value to `2.4.0` and extending its AC2 guard row
  list to match, so the guard's row 7 (the declared value equals the version the
  list was written for) keeps holding.
- Deciding, and documenting, what happens to the auto-generated thumbnail.

**Out of scope** (each needs its own spec before it may be built)

- **`c2pa.ingredient.v3`'s placement.** It stays in `gathered_assertions` on the
  manipulated path. Unlike the thumbnail the semantics are genuinely arguable —
  the assertion is generated by the claim generator, but everything it describes
  came from outside the workflow — and no upstream issue or specification
  sentence settles it. Recorded rather than decided.
- 2.4 additions that do not touch our output: the sustainability and
  AI-disclosure assertions, HTML and structured-text embedding, live video and
  dynamic packaging, `relatedAssertions` in action parameters, the
  `c2pa.watermarked.bound` recommendation, and the Dublin Core metadata changes.
- **`allActionsIncluded`.** 2.4 requires it only when a generator opens and
  immediately re-saves without other changes, which this package never does. Its
  wider use remains out of scope for the reasons SPEC-035 records.
- Raising beyond 2.4.

**Delivery note.** Same split as SPEC-035, and for the same reason. The actions
assertion is built by the PHP client, so `"created": true` ships in the Composer
package; the declared value and any thumbnail setting live in
`service/server.js`, which is `export-ignore`d and reaches users through
`git pull` plus a rebuild. A deployment can therefore emit a 2.4-shaped manifest
while still declaring `2.3.0`, or the reverse — which is why AC6 pins them
together at the point where it can.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-036')`.

- **AC1 — the actions assertion is attributed to the signer, on the creation path**
  - Given an asset signed for AI-generated content through this package
  - When the raw claim is inspected
  - Then `c2pa.actions.v2` appears in `created_assertions` and **not** in
    `gathered_assertions`, satisfying 2.4 §18.15.2 — asserted on the claim's own
    arrays, because the validator does not check this and reports `Valid` either
    way

- **AC2 — and on the manipulated path**
  - Given an asset signed for manipulated content, with a parent ingredient
  - When the raw claim is inspected
  - Then `c2pa.actions.v2` likewise appears in `created_assertions`, and the
    `c2pa.opened` action still carries its `parameters.ingredients` hashed-uri
    reference — the 2.4 requirement that was already met must not regress while
    the placement changes

- **AC3 — the declared version rises with the manifests, never before them**
  - Given the service declaring `2.4.0`
  - When a signed manifest is inspected
  - Then it satisfies every row of SPEC-035 AC2's list as extended by this spec,
    including the two 2.4 rows; and the extended list is written for `2.4.0`, so
    SPEC-035 AC2 row 7 fails if either half moves without the other

- **AC4 — the audit's scope is recorded, and it is the normative text this time**
  - Given the guard from AC3
  - When its docblock is read
  - Then it states that the rows come from the **normative text** of C2PA 2.4,
    names the sections they come from, and lists what was examined and excluded —
    because SPEC-035's guard was built from version histories and that is exactly
    how it missed the thumbnail

- **AC5 — nothing the claim generator created is declared as gathered** *(the thumbnail decision)*
  - Given an asset signed through this package
  - When `gathered_assertions` is inspected
  - Then it contains no assertion that this package's claim generator produced.
    On the creation path that means the auto-generated thumbnail is suppressed,
    since the specification's own NOTE says placing it there declares it "was not
    sourced from the claim generator" — which is untrue of it
  - And the removal is **documented as a visible change**: assets signed after
    this no longer carry a thumbnail inside the credential

- **AC6 — a caller cannot get a 2.4 manifest that declares 2.3, or the reverse** *(error path)*
  - Given a client emitting `"created": true` against a service still declaring
    `2.3.0`, or a service declaring `2.4.0` receiving an actions assertion
    without the flag
  - When the manifest is built
  - Then the mismatch is refused with a message naming both halves, rather than
    signing a manifest whose shape and whose declaration disagree — the split
    delivery makes this reachable in a real deployment, not a hypothetical

- **AC7 — the in-process reader still agrees** *(error path)*
  - Given an asset whose actions assertion sits in `created_assertions`
  - When it is read through `SigningServiceReader` and through `ExtC2paReader`,
    which runs c2pa-rs **0.89.0** — older than the engine that wrote it
  - Then every accessor in `spec019Accessors()` agrees, and in particular
    `isAiGenerated()` and `digitalSourceTypes()` still find the marking. A
    placement change that made the older reader blind to the Article 50 marking
    would be a far worse outcome than an unraised declaration

## API sketch

Illustrative only.

Client side — the actions assertion gains one field:

```php
// Provemark\ContentCredentials\Core\Manifest\ManifestBuilder
// emits, per SPEC-036 AC1:
[
    'label' => 'c2pa.actions.v2',
    'created' => true,          // attributed to the signer (2.4 §18.15.2)
    'data' => ['actions' => [/* … */]],
]
```

Service side — the declared value and the thumbnail decision:

```js
const SPEC_VERSION = '2.4.0';

const settings = { version: 1, builder: { thumbnail: { enabled: false } } };
const builder = Builder.withJson(manifestDefinition, settings);
```

## Open questions

- **Is suppressing the thumbnail the right answer, or should we wait for
  upstream?** AC5 assumes suppression. The alternative is to accept the
  contradiction until c2pa-rs #2106 lands, on the grounds that a thumbnail inside
  a credential is genuinely useful to a person inspecting an asset, and that the
  mismatch is a mislabelling of provenance rather than a false claim about the
  content. **Blocker for AC5**, and the only question in this spec that is a
  judgement rather than a measurement.
- **Does anything depend on the thumbnail today?** The primer records it as
  "expected, harmless" and no criterion asserts it, but consumers may have come
  to rely on it. *Non-blocker*, and the CHANGELOG must call the removal out
  regardless.
- **Should the client refuse to sign against a 2.3-declaring service at all, or
  only warn?** AC6 says refuse. The cost is that a partially upgraded deployment
  stops signing rather than degrading, which is the fail-closed choice this
  package makes elsewhere (SPEC-007, SPEC-014). *Non-blocker*, leaning refuse.
- **`c2pa.ingredient.v3` in `gathered_assertions`.** Out of scope above, but
  worth a sentence in the docs: we are not claiming the manipulated path is
  free of the same question the thumbnail raised, only that this one is arguable
  and unlegislated. *Non-blocker*.

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