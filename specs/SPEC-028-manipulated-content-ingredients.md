# SPEC-028: Marking manipulated content — ingredients and the edited action

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | 2026-08-07 (maintainer)                           |
| Supersedes | — (amends SPEC-026 AC4)                           |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

This package can mark an asset as **created** by AI. It cannot mark one as
**manipulated** by AI, and that is half of the obligation it exists to serve.

Article 50(2) of Regulation (EU) 2024/1689 requires providers to ensure that
synthetic output is "marked in a machine-readable format and detectable as
artificially generated **or manipulated**". The two are one sentence in the law
and two entirely different manifests in C2PA. A product whose feature is "remove
the background with AI", "extend this image", or any inpainting flow produces
manipulated content, and today this package refuses to mark it — correctly, but
unhelpfully.

SPEC-026 established *why* it refuses, and the refusal is deliberate rather than
an oversight. C2PA does not record editing as a different constant on the same
action. Per C2PA Implementation Guidance 2.4, verified in NOTES Step 32, it is
three linked things:

1. a `c2pa.opened` action, first, pointing at an ingredient;
2. an **ingredient assertion** for the original asset, with
   `relationship: parentOf`;
3. a `c2pa.edited` action carrying the `digitalSourceType`.

`ManifestBuilder` emits exactly one action, and `service/server.js` builds its
manifest definition with no ingredients at all. So SPEC-026 declared the three
editing terms in `DigitalSourceType` and made `ManifestBuilder::forSourceType()`
throw `UnsupportedSourceTypeException` for them, on the reasoning that emitting
one of them on a `c2pa.created` action "would be a well-formed manifest making a
false claim" — asserting that an asset was *created* by an operation which by
definition acts on something that already existed.

That reasoning still holds. This spec removes the condition that made it
necessary.

### What the ingredient actually costs

An ingredient is not metadata about the original. It is a **hash binding over the
original's bytes**, which means the caller must supply the original asset, not a
filename or a URL. Every layer changes:

- `SignerInterface::sign()` takes one asset today.
- `POST /v1/sign` carries one base64 body today.
- `MAX_BODY_SIZE` (20 MB, SPEC-017) and the measured ~7× memory multiplier were
  sized for one asset in flight, not two.

That is why this is a larger piece of work than the whole of SPEC-026, and why it
gets its own spec rather than riding along as "one more enum case".

### Verified before drafting (2026-08-07)

Against the running service, `@contentauth/c2pa-node` **0.8.1** (c2pa-rs 0.90.4):

```
version: 0.8.1
addIngredient: function
setIntent    : function
addAction    : function
```

So the mechanism exists in the version the service actually runs — this is not a
"pending upstream" spec. `BuilderInterface` documents `setIntent(Edit)` as
requiring a parent ingredient and as able to "add parent ingredients from the
source asset and automatically add required `c2pa.created` or `c2pa.opened`
actions". Whether we use that or build the actions ourselves is **OQ1**, and it
is the central design decision here.

Also verified, and it changes two things this spec must cover:

- **`ManifestReport::digitalSourceTypes()` already scans every action** in any
  `c2pa.actions*` assertion, not just the first. So a source type on a
  `c2pa.edited` action is picked up today, `isAiGenerated()` correctly stays
  false for it (it means exactly `trainedAlgorithmicMedia`), and
  `involvesGenerativeAi()` correctly becomes true. The reading side needs less
  work than expected — but it has no way to answer "was this *edited*?".
- **`REQUIRE_AI_MARKING` would refuse every manipulated asset.** The service's
  policy check reads `firstActionSourceTypes()`, and under the edited shape the
  first action is `c2pa.opened`, which carries no `digitalSourceType` at all. A
  hardened service would 400 the exact content this spec exists to enable. That
  is a defect this spec must fix, not a configuration note.

## OQ1 measured: the linkage is c2pa-rs's to build (2026-08-07)

Both routes were built and signed inside the running container against 0.8.1,
and the signed manifests inspected. This is measurement, not reasoning, and it
settles the blocking question.

- **A** — we supply `c2pa.opened` + `c2pa.edited` ourselves, no `setIntent`.
- **B** — `setIntent('edit')`; we supply only `c2pa.edited`, in our own assertion.
- **B2** — `setIntent('edit')`; we supply nothing, the edited action goes in
  through `addAction()`.

All three produced exactly **one** `c2pa.actions.v2` assertion. They differ only
in whether the result validates:

- **A** → **`Invalid`**, `assertion.action.ingredientMismatch`
- **B** → `Valid`
- **B2** → `Valid`

**Route A cannot be made to work from PHP.** The `c2pa.opened` action does not
merely name the ingredient, it carries a hash of it:

```json
{ "action": "c2pa.opened",
  "parameters": { "ingredients": [{
    "url": "self#jumbf=c2pa.assertions/c2pa.ingredient.v3",
    "hash": "nP3uvWkY9FColHEVkiXwzC/E90OQapMiYGge/AesTwg=" }] } }
```

That hash is over the **ingredient assertion**, which the service constructs.
PHP would have to reproduce c2pa-rs's own assertion serialisation and hashing to
emit it — so route A is not "fragile", it is impossible without reimplementing
part of the library. Left unlinked, c2pa-rs correctly refuses the manifest.

**The double-actions fear does not materialise.** Route B yields exactly **one**
`c2pa.actions.v2` assertion: c2pa-rs inserts the `c2pa.opened` action *into our
assertion* rather than adding a second one, and our `c2pa.edited` survives intact
with its `digitalSourceType` and `softwareAgent`. The invariant in CLAUDE.md's
Domain rules — exactly one actions assertion — is preserved.

**B is preferred over B2.** They are structurally identical, but B leaves the
service passing `extra_assertions` through unchanged, so the only service change
is `setIntent('edit')` plus `addIngredient()`.

Three secondary findings, each of which changes an acceptance criterion:

1. **`setIntent('edit')` without an ingredient signs anyway** — `Valid`, no
   error, despite the API documenting that "Edit requires a parent ingredient".
   The library does not enforce it, so **our guard is the only guard**. AC3 and
   AC5 are load-bearing, not belt-and-braces.
2. **c2pa-rs signs the contradictory shape without complaint.** Given a
   `c2pa.created` action, an ingredient with `relationship: parentOf` and the AI
   *edit* source type, it produced `Valid` and left the created action alone — no
   `c2pa.opened` added, no warning. That is precisely the well-formed-but-false
   manifest SPEC-026 exists to prevent, and nothing downstream of our builder
   will catch it. AC4 is essential.
3. **The existing created path is unaffected.** Without `setIntent`, one actions
   assertion, `c2pa.created`, `Valid` — unchanged. The intent is set only when a
   parent is present, so there is no regression surface for existing callers.

## OQ4 measured: a signed parent brings its whole manifest along (2026-08-07)

Signed an original the ordinary way (`c2pa.created`, `trainedAlgorithmicMedia`),
then used that signed file as the `parentOf` ingredient of a route-B edit, with
an unsigned parent as the baseline.

The manifest is richer, and it validates:

- the store gains a **second manifest** — `manifestCount` 1 → 2 — holding the
  parent's own claim;
- the ingredient gains `active_manifest` (pointing at the parent's manifest
  label), `manifest_data` and `validation_results`, none of which are present
  for an unsigned parent;
- `validation_state` stays `Valid` in both cases.

So provenance is preserved automatically. Nothing needs building for it, and
nothing may be claimed about it either — reading it is out of scope.

**Our reader is unaffected, and this was verified rather than reasoned.**
`ManifestStoreParser` resolves `active_manifest` and reads only that manifest's
assertions, so the parent's `trainedAlgorithmicMedia` cannot leak into what the
report says about the derived asset. Confirmed against the real file through
`ExtC2paReader` (c2pa-rs **0.89.0**, the older of our two engines):

```
isAiGenerated        : false   <-- correct: edited, not created
involvesGenerativeAi : true
digitalSourceTypes   : ["compositeWithTrainedAlgorithmicMedia"]
```

The whole-store view would have said both terms. That difference is one accessor
away from being a bug, so AC1's insistence that `isAiGenerated()` stays false is
not pedantry — it is the criterion that pins this.

**The size finding, and it compounds.** From a 1.7 KB fixture:

- signed original: **47,748** bytes
- derived, unsigned parent: **80,840** bytes
- derived, **signed** parent: **128,448** bytes

The extra ~47.6 KB is the parent's entire manifest store, carried inside the
child. A second edit would carry both, a third all three: **provenance
accumulates in the bytes**. For this package that lands in two places at once —
the derived asset is larger, and if it is later edited again it is also the
larger *input* to the next request. A deployment can therefore approach
`MAX_BODY_SIZE` through edit generations rather than through asset size, which
is not a failure mode SPEC-017 or SPEC-025 was sized for.

## Scope

**In scope**

- `ManifestBuilder::forAiManipulated(MediaType)` — the Article 50 editing case,
  `compositeWithTrainedAlgorithmicMedia`. The name was settled in NOTES Step 26
  (form A: named constructors, no general `for()`), before this spec existed.
- Lifting SPEC-026's refusal for **all three** terms whose `requiresIngredient()`
  is true, now that the structural reason is gone (amends SPEC-026 AC4). Only
  the AI term gets a named constructor; the other two are reached through
  `forSourceType()`. `UnsupportedSourceTypeException` is kept as public API that
  nothing throws — see OQ2, settled.
- Carrying the **parent asset** from the caller, through the Signing layer, to
  the service, and into the manifest as a `parentOf` ingredient.
- A `/v1/sign` request field for the parent asset, vetted under SPEC-011's
  limits and recorded under SPEC-012's audit contract.
- Bounding the two-asset request: body size, memory multiplier, and the error a
  caller gets when the pair does not fit (SPEC-017, SPEC-025).
- Widening `REQUIRE_AI_MARKING` so it recognises the marking wherever the
  edited shape puts it.
- One reading accessor: `ManifestReport::isAiManipulated()`.
- Documentation: the README's Article 50 section currently describes generation
  only.

**Out of scope** (each needs its own spec before it may be built)

- **Reading ingredients as data** — titles, hashes, thumbnails, or the nested
  manifest of a parent that was itself signed. This spec adds one boolean; a
  full ingredient reader is a separate surface on `ManifestReport`.
- **Reading the provenance chain.** A signed parent's manifest *is* carried into
  the child automatically (measured above) — but exposing it, walking it, or
  reporting the parent's own claims is a separate reader surface and a separate
  spec. What ships here is that the chain exists and that our reader does not
  confuse it with the active manifest.
- **More than one ingredient**, and the `componentOf` relationship — compositing
  several sources into one asset. `compositeSynthetic` covers that case today as
  a *created* claim without ingredients, which is honest and sufficient.
- **`setIntent(Update)`** and update manifests.
- **Remote or referenced ingredients** (`setRemoteUrl`, ingredient by URL). The
  service fetches nothing; that property is load-bearing for its threat model.
- **Streaming or path-based signing.** Two assets in one base64 body makes the
  transport limit bite twice as hard, and that is a known, documented ceiling
  (NOTES Step 25), not something this spec repairs.
- Any authenticity term. SPEC-026 settled that and nothing here reopens it.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-028')`.

- **AC1 — a manipulated asset signs and reads back as manipulated**
  - Given a signing service, an original asset, and a derived asset
  - When the caller builds `ManifestBuilder::forAiManipulated($type)` with a
    software agent and signs the derived asset with the original as parent
  - Then the signed asset reads back with `hasManifest()` true,
    `isSignatureValid()` true, `involvesGenerativeAi()` true,
    `isAiManipulated()` true, and `isAiGenerated()` **false** — the asset was
    not created by AI, and the accessor that gates Article 50(2) generation
    decisions must not start answering yes to a different question.

- **AC2 — the manifest carries the structure C2PA defines, not a lookalike**
  - Given the signed asset from AC1
  - When its manifest is inspected directly (not through our own reader)
  - Then it carries exactly one `c2pa.actions.v2` assertion whose first action
    is `c2pa.opened`; an ingredient with `relationship: parentOf`; and a
    `c2pa.edited` action carrying `compositeWithTrainedAlgorithmicMedia` and the
    software agent. The ingredient's hash covers the original's bytes.
  - And `c2patool` (trust settings on) reports signature valid, certificate
    trusted, and **no** `assertion.action.malformed` — the failure mode a
    hand-built action sequence is most likely to produce.

- **AC3 — a source type needing an ingredient is refused when none is supplied**
  *(error path)*
  - Given a manifest built for a source type whose `requiresIngredient()` is true
  - When the caller signs an asset **without** a parent asset
  - Then a `MissingParentAssetException` is thrown **before any HTTP request is
    made**, naming the source type and stating that the original asset is
    required; no partial side effects, nothing signed.

- **AC4 — a parent supplied for a created-type manifest is refused**
  *(error path)*
  - Given a manifest built by `forAiGenerated()` (no ingredient needed)
  - When the caller supplies a parent asset anyway
  - Then the call is refused with an exception rather than silently ignoring the
    parent. A caller who passes an original believes it is being recorded;
    accepting and discarding it would produce a manifest that omits exactly what
    the caller thought they were asserting.
  - **Measured, and this is why the criterion is essential:** handed a
    `c2pa.created` action, a `parentOf` ingredient and the AI *edit* source type
    together, c2pa-rs signs it and reports `Valid`, adding no `c2pa.opened` and
    raising nothing. The library will not catch the contradiction. Our builder's
    policy is the only thing standing between a caller and a well-formed
    manifest making a false claim — the exact failure SPEC-026 was written to
    prevent.

- **AC5 — the service refuses an unusable parent** *(error path)*
  - Given a `/v1/sign` request whose parent field is absent when the actions
    array contains a `c2pa.opened` action, is not valid base64, or is not a
    decodable asset of its declared media type
  - Then the service answers 400 with our own wording (never library
    internals, never an echo of the payload) and a correlation id, signs
    nothing, and writes exactly one SPEC-012 audit record for the refusal.
  - **Measured:** `setIntent('edit')` with no ingredient attached signs happily
    and reports `Valid`, despite the API documenting that "Edit requires a
    parent ingredient". The library enforces nothing here, so this criterion and
    AC3 are the only guards that exist. A missing parent must never reach
    `sign()`.

- **AC6 — the pair is bounded, and the refusal says why** *(error path)*
  - Given a request whose two assets together exceed `MAX_BODY_SIZE`
  - When it is posted
  - Then the service answers 413 with a message stating that a manipulation
    request carries **two** assets and that the limit applies to their combined
    size — a caller sending a 12 MB original and a 12 MB result must not read a
    message implying either one was too large on its own.
  - And the PHP client refuses the same case before encoding, per SPEC-025, with
    the same distinction in its message.

- **AC7 — `REQUIRE_AI_MARKING` recognises the edited shape**
  - Given a service started with `REQUIRE_AI_MARKING=true`
  - When a manifest is submitted whose first action is `c2pa.opened` and whose
    `c2pa.edited` action carries `compositeWithTrainedAlgorithmicMedia`
  - Then it is **accepted** — the policy asks whether this manifest marks AI
    involvement, and it does.
  - And a manifest whose only AI-relevant source type is `algorithmicMedia`
    (synthetic, explicitly not trained) is still refused, so the widening does
    not become "accept anything with a source type anywhere".

- **AC8 — the audit record shows an ingredient was present**
  - Given any `/v1/sign` request, accepted or refused
  - Then its SPEC-012 record reports whether a parent asset was supplied and its
    size, alongside the existing fields. Accountability for "we signed a claim
    that this was derived from something" requires knowing there was a
    something.

- **AC9 — memory at the concurrency cap is measured, not assumed**
  - Given the service at `MAX_CONCURRENT_SIGNS`, signing manipulation requests
    at the largest size the body limit admits
  - When container memory is sampled at peak
  - Then the measured multiplier is recorded in this spec and in
    `docs/service.md`, and the shipped default for `MAX_BODY_SIZE` is either
    confirmed against it or changed by amendment. SPEC-017 measured ~7× for one
    asset and corrected a "roughly four copies" claim that had been repeated
    twice; the two-asset figure must not be extrapolated from it.
  - And the **output** growth is measured across at least three edit
    generations, because a signed parent's manifest is carried into the child
    (measured above) and therefore accumulates. The recorded figure must be
    growth per generation, not a single before/after pair — one pair cannot show
    whether the cost compounds.

- **AC10 — the documentation distinguishes the two obligations**
  - Given the README and `docs/marking.md`
  - Then they state that Article 50(2) covers generated *and* manipulated
    content, show the manipulated call, and say plainly that the original asset
    must be supplied and why.
  - And they state that signing an already-signed original carries that
    original's manifest into the result, so a chain of edits grows with each
    generation — with the measured figure, not an adjective. Someone sizing a
    storage bucket or a body limit needs the number.
  - Phrases are asserted against whitespace-normalised text, and confirmed
    absent from `git show origin/main:README.md` before the change, per NOTES
    Step 31.

- **AC11 — the created path is untouched**
  - Given any manifest built by `forAiGenerated()`, `forSynthetic()` or
    `forAlgorithmic()`, signed with no parent asset
  - Then the request carries no parent field, the service sets no intent, and
    the resulting manifest is byte-for-byte the shape it is today: one
    `c2pa.actions.v2` assertion, first and only action `c2pa.created`,
    `validation_state` `Valid`.
  - This is a regression criterion, and it is cheap because the measurement
    already exists: the created path was signed alongside the probe routes and
    was unaffected. It is written down so that a later change to the intent
    logic cannot quietly widen to callers who never asked for an ingredient.

- **AC12 — every declared source type builds, and nothing catching breaks**
  - Given each case of `DigitalSourceType`
  - When passed to `ManifestBuilder::forSourceType()` with a media type and a
    software agent
  - Then a manifest is produced and nothing throws — including the three terms
    SPEC-026 AC4 refused.
  - And `UnsupportedSourceTypeException` still exists and is still
    `Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedSourceTypeException`,
    so code that catches it keeps compiling; it is simply never raised. The
    CHANGELOG says so under **Upgrading**, following SPEC-022's treatment of
    `forAiGeneratedImage()` — kept indefinitely, no runtime deprecation.

## API sketch

Illustrative only — not binding.

```php
// namespace Provemark\ContentCredentials\Core\Manifest;

final class ManifestBuilder
{
    /** Content manipulated with a generative AI model — Article 50(2), editing side. */
    public static function forAiManipulated(MediaType $type): self;
}

final readonly class Manifest
{
    /** True when this manifest's actions reference a parentOf ingredient. */
    public function requiresParentAsset(): bool;
}
```

```php
// namespace Provemark\ContentCredentials\Core\Signing;

interface SignerInterface
{
    // Additive and BC: the third parameter is optional at the signature level
    // and mandatory-by-manifest, enforced in the adapter (AC3/AC4).
    public function sign(Asset $asset, Manifest $manifest, ?Asset $parent = null): SignedAsset;
}
```

```php
$manifest = ManifestBuilder::forAiManipulated(MediaType::Png)
    ->withSoftwareAgent('ACME Inpainting', '2.0')
    ->build();

$signed = ContentCredentials::sign(
    new Asset($editedBytes, MediaType::Png),
    $manifest,
    parent: new Asset($originalBytes, MediaType::Png),
);
```

Wire contract, `POST /v1/sign` — one added field, optional, absent for every
request that exists today:

```jsonc
{
  "content": "<base64 of the edited asset>",
  "mime_type": "image/png",
  "extra_assertions": [ /* ... */ ],
  "parent": { "content": "<base64 of the original>", "mime_type": "image/png" }
}
```

## Open questions

**OQ1 — who builds the action linkage: us, or c2pa-rs?** *(was blocking —
**answered by measurement**, see the section above; needs the maintainer's
assent, not more investigation)*

**Route B.** Route A is not a worse option, it is not an available one: the
`c2pa.opened` action carries a hash over the ingredient assertion the service
builds, so PHP cannot emit it without reimplementing c2pa-rs's assertion
hashing, and left unlinked the manifest validates as `Invalid`. Route B yields
exactly one actions assertion, so the divergence from NOTES Step 1 — that the
client owns the actions assertion — is preserved rather than traded away.

The service change is two calls (`setIntent('edit')`, `addIngredient()`), both
conditional on a parent being present, and the existing created path is
measurably unchanged.

**OQ2 — do `algorithmicallyEnhanced` and `humanEdits` unlock too?**
**Settled 2026-08-07 (maintainer): yes — all three.** Named constructor for the
AI term only (`forAiManipulated()`); the other two are reached through
`forSourceType()`. `humanEdits` is not an authenticity claim about physical
origin — it is a claim about what the application itself did, which the
application does know, so SPEC-026's hearsay argument never excluded it.

**Consequence, and it is larger than it looks: SPEC-026's refusal path
disappears entirely.** With every declared source type emittable,
`ManifestBuilder::forSourceType()` no longer throws, and
`UnsupportedSourceTypeException` becomes a class nothing raises.
`DigitalSourceType::requiresIngredient()` survives but changes job — it stops
gating the builder and becomes the predicate behind
`Manifest::requiresParentAsset()`.

Two things follow, both of which must be settled here rather than discovered
during implementation:

- **The exception class is kept, not deleted.** It is public API since 0.8.0 and
  someone may be catching it; removing it is a break for no gain. This follows
  SPEC-022's precedent for `forAiGeneratedImage()`: keep it, document that it is
  no longer thrown, raise no runtime deprecation. The CHANGELOG says so under
  **Upgrading**.
- **SPEC-026 AC4's tests change rather than disappear.** They encode the
  superseded rule, so leaving them would pin behaviour this spec removes, and
  deleting them would lose the criterion. They become tests that the previously
  refused terms now build — the same move NOTES Step 13 made when SPEC-013
  amended SPEC-003 D3.

**OQ3 — must the parent's media type match the output's?**
**Settled 2026-08-07 (maintainer): they may differ, and no check is added.**
Editing a PNG and saving as JPEG is ordinary, and nothing in C2PA requires them
to match. `MediaTypeMismatchException` keeps its current meaning exactly — it
compares the asset being signed against its manifest, and the parent is not
compared to either. The parent's own `mime_type` is carried for the ingredient
record; per NOTES Step 23 it is advisory to c2pa-rs in any case, which is one
more reason not to build a check that would be enforcing our opinion rather than
the format's.

**OQ4 — what happens when the parent is already signed?** *(**answered by
measurement**, see the section above)*

The child carries the parent's whole manifest store: a second manifest in the
store, `active_manifest` / `manifest_data` / `validation_results` on the
ingredient, `Valid` throughout, and ~47.6 KB of growth on a 1.7 KB fixture. Our
reader is unaffected because it resolves `active_manifest` and reads only that.

What remains for the maintainer is not a question but a consequence to accept:
**provenance accumulates in the bytes across edit generations**, so
`MAX_BODY_SIZE` can be reached by chain depth rather than by asset size. AC9
measures it; AC10 requires the README to say it. If that is judged unacceptable
as a default, the lever is `MAX_BODY_SIZE`, and changing it is an amendment to
SPEC-017, not a change here.

**OQ5 — does the client's request bound (SPEC-025) need a second number?**
**Settled 2026-08-07 (maintainer): one budget for the pair.**
`AssetTooLargeException` is checked against the combined size before encoding,
and its message names both assets. A per-asset limit would invite the case where
each asset passes its own check and the request still 413s — a client-side guard
whose whole purpose is to fail before the server does, failing after it.

This also keeps the existing single-asset path arithmetically identical: one
asset and no parent is the same check with one term.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/ManipulatedContentTest.php` :: "signs a manipulated asset and reads it back as manipulated"; `tests/Unit/Signing/ParentAssetTest.php` :: "sends the parent asset as its own base64 field", "omits the parent field entirely when there is no parent" | `src/Core/Manifest/ManifestBuilder.php` `forAiManipulated()`, `src/Core/Signing/SigningServiceSigner.php` `buildPayload()`, `service/server.js` |
| AC2 | `tests/Integration/ManipulatedContentTest.php` :: "produces c2pa.opened first, a parentOf ingredient, and c2pa.edited"; `tests/Unit/Manifest/ManipulatedContentTest.php` :: "emits c2pa.edited for a source type that acts on an existing asset", "does not emit a c2pa.opened action of its own" | `service/server.js` `setIntent('edit')` + `addIngredient()`, `src/Core/Manifest/ManifestBuilder.php` `build()` |
| AC3 | `tests/Unit/Signing/ParentAssetTest.php` :: "refuses to sign a manipulated manifest with no parent asset", "names the source type in the missing-parent message" | `src/Core/Signing/Exception/MissingParentAssetException.php`, `src/Core/Signing/SigningServiceSigner.php` `sign()` |
| AC4 | `tests/Unit/Signing/ParentAssetTest.php` :: "refuses a parent asset for a manifest that creates rather than edits" | `src/Core/Signing/Exception/UnexpectedParentAssetException.php` |
| AC5 | `tests/Integration/ManipulatedContentTest.php` :: "refuses a parent whose content is not valid base64", "refuses a parent whose mime_type is not supported", "refuses an edited manifest that arrives with no parent at all", "refuses a parent supplied for a manifest that creates" | `service/server.js` `needsParentAsset()` + the parent block in `POST /v1/sign` |
| AC6 | `tests/Unit/Signing/ParentAssetTest.php` :: "counts both assets against one request budget"; `tests/Integration/ManipulatedContentTest.php` :: "names both assets when refusing a manipulation request for size" | `src/Core/Signing/SigningServiceSigner.php` `sign()`, `service/server.js` body-parser error handler |
| AC7 | `tests/Integration/ManipulatedContentTest.php` :: "accepts the edited shape under REQUIRE_AI_MARKING", "still refuses a non-AI marking under REQUIRE_AI_MARKING" | `service/server.js` `markingSourceTypes()`, `AI_MARKINGS` |
| AC8 | `tests/Integration/ManipulatedContentTest.php` :: "records the parent asset in the audit trail", "leaves the parent fields null when nothing was derived" | `service/server.js` `audit()` call in `POST /v1/sign` |
| AC9 | `tests/Unit/ManipulationGuidanceTest.php` :: "records the measured memory cost where a container is sized", "publishes the generational growth as a number, not an adjective" | `docs/service.md`, `docs/marking.md`, and the implementation notes below |
| AC10 | `tests/Unit/ManipulationGuidanceTest.php` :: "says Article 50 covers manipulation, not only generation", "shows the manipulated call and names the constructor", "says the original asset itself must be supplied, and why", "names both refusals so neither is discovered from a stack trace" | `README.md`, `docs/marking.md` |
| AC11 | `tests/Unit/Manifest/ManipulatedContentTest.php` :: "still emits c2pa.created for the source types that create" | `src/Core/Manifest/ManifestBuilder.php` `build()` |
| AC12 | `tests/Unit/Manifest/ManipulatedContentTest.php` :: "builds a manifest for every declared source type, refusing none", "keeps UnsupportedSourceTypeException as public API that nothing throws" | `src/Core/Manifest/ManifestBuilder.php` `forSourceType()`, `src/Core/Manifest/Exception/UnsupportedSourceTypeException.php` |

## Implementation notes (2026-08-07)

**AC9, measured rather than extrapolated.** Container memory at the concurrency
cap, signing manipulation requests at the largest pair the body limit admits
(6.3 MB each, 17.0 MB of body against a 20 MB limit), against a **clean** 24.4
MiB baseline:

```
HTTP statuses : 200 200 200 200
peak with 4 in flight : 244.1 MiB   per request : 54.9 MiB   multiplier vs the PAIR : 4.6x
```

The first attempt measured 0.8×, which is what a wrong measurement looks like
when it looks reassuring: the baseline was 133 MiB after a full integration run,
and the pair was sized so close to the limit that the requests were plausibly
refused. The script now asserts all four statuses are 200 and prints a warning
when they are not, because a memory figure taken over refused requests means
nothing and reads as good news.

**The default needs no change, which is the answer AC9 asked for.** A
manipulation request is bounded by the same `MAX_BODY_SIZE` as any other, and
the largest admissible *pair* is smaller than the largest admissible single
asset — so the peak (≈245 MiB) sits below SPEC-017's ≈420 MiB for four
maximum-size single-asset signings. The parent is hashed, not signed.

**Generational growth is linear, not compounding**, which is better than the
spec assumed. Four generations from a 1.7 KB fixture:

```
gen 1 (created)  55,455    gen 2  144,301 (+88,846)
gen 3  233,924 (+89,623)   gen 4  323,547 (+89,623)
```

A constant ~89.6 KB per generation. Had the child embedded the parent's whole
accumulated store, gen 3 would have been ≈288 KB rather than 234 KB. The
mechanism was not investigated further — the measured shape is what the README
publishes, and asserting a mechanism nobody verified is how NOTES fills up.

**One criterion needed a fix outside its own scope.** `bin/verify.sh` — the
authoritative check per CLAUDE.md — reported a correctly marked manipulated
asset as `AI Art.50 mark : FAIL`, because it tested for `trainedAlgorithmicMedia`
alone. Article 50(2) covers generated *or* manipulated, so the script now
recognises both and names which one it found. Verified in both directions.

**The Laravel manager was outside every criterion and had to change anyway.**
The API sketch and `docs/marking.md` both show `ContentCredentials::sign($asset,
$manifest, parent: ...)` — the facade. `ContentCredentialsManager::sign()` took
two parameters, so the documented example did not compile. Added, with a test
that asserts the third parameter exists rather than trusting the docs, since
nothing else exercises the manager's signature.

**A test of mine was wrong, not the code.** AC8's "parent fields are null when
nothing was derived" used `$record['parent_bytes'] ?? 'missing'`, and null
coalescing treats a real `null` exactly like an absent key — so it reported a
correct record as broken. It asserts presence and nullness separately now.

**`bin/spec-check.php` needs the bolded AC title on one line.** Its criteria
regex is `/m` without `/s`, so a title wrapped across two lines is invisible to
it and the traceability row reads as stale. Not a defect to fix in the tool —
worth knowing when writing a spec.