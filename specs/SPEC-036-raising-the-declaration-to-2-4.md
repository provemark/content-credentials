# SPEC-036: Raising the declared specification version to 2.4

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon (maintainer), 2026-08-31         |
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
- **The flag is observable everywhere; the placement is not.** `"created": true`
  comes back on the assertion in the ordinary manifest report, so
  `ManifestReport::assertions()` can see it and every criterion below can run in
  CI. The `created_assertions` array itself is visible only through
  `c2patool --detailed`, which CI does not install — so the criteria assert the
  **flag we emit**, and this measurement records the mapping from flag to
  placement. That mapping is an engine behaviour, and SPEC-035 AC7 already fails
  on an engine bump, which is the prompt to re-measure it. Asserting the flag in
  a test that runs everywhere beats asserting the placement in one that reports
  `skipped` on every machine.
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
question, not a conformance one. **AC5 answers it: we keep the thumbnail and
record the exception**, rather than manufacture conformance by deleting the
thing that exposes it. The reasoning is with the criterion.

## Scope

**In scope**

- Emitting `"created": true` on the actions assertion **from the service**, on
  both the creation and the manipulated paths.
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

**Delivery note — one route, and that is the point.** Both halves live in
`service/server.js`: the declared value, and the flag that makes it true. So this
reaches users through `git pull` plus a rebuild and **not** through
`composer update`, and there is no way to hold one half without the other.

An earlier draft put the flag in the PHP client and added a criterion refusing
mismatched combinations. Implementing it showed why that was wrong: it broke
every existing caller the moment a service was rebuilt — 37 integration tests
went red, which is what a user's deployment would have done — and it put a
decision about **claim structure** in the hands of the party that does not sign.
`created` means "attributed to the signer". The signer is the service. Moving it
there removed the mismatch rather than guarding it, and removed the criterion
with it.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-036')`.

- **AC1 — the actions assertion is attributed to the signer, on the creation path**
  - Given an asset signed for AI-generated content through this package, by a
    client that sends **no** `created` flag of its own
  - When the signed manifest is read back
  - Then the actions assertion carries `"created": true`, which is what places it
    in `created_assertions` per 2.4 §18.15.2. **The service sets it, not the
    client**: `created` means "attributed to the signer", and the signer is the
    service — where an assertion sits in the claim is a property of how the claim
    generator builds it, like `claim_generator_info`. Asserted on the flag rather
    than on the array, for the observability reason recorded above

- **AC2 — and on the manipulated path**
  - Given an asset signed for manipulated content, with a parent ingredient
  - When the signed manifest is read back
  - Then the actions assertion likewise carries `"created": true`, and the
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

- **AC5 — the inherited thumbnail exception is pinned, and alarms when it is fixed**
  - Given an asset signed through this package
  - When its manifest is read
  - Then the auto-generated thumbnail is **present**, and the documented
    exception explaining where it sits and whose default that is exists in
    `docs/` — both asserted positively, so neither can pass by the absence of
    something
  - And the alarm for upstream fixing it is **SPEC-035 AC7**, which fails on any
    engine bump and prompts the re-audit that would notice the move. A test
    asserting the `gathered_assertions` placement directly would need
    `c2patool --detailed`, which CI does not install, and would report `skipped`
    on every machine — the failure mode this repository has already paid for
    once

  **Why the exception is kept rather than removed.** The obvious alternative is
  to suppress the thumbnail — `builder.thumbnail.enabled: false` works and leaves
  `gathered_assertions` absent entirely, measured. It is the wrong trade, for
  three reasons.

  *It is not our defect, and deletion is not the repair.* Upstream tracks this as
  #2106 with a `// todo: add setting for created added thumbnails` on the line
  responsible, and their intended fix is to move the thumbnail into
  `created_assertions` — the correct one. Suppressing it here would permanently
  remove a feature to work around a bug that is expected to go away.

  *It is a mislabelling, not a false statement.* The marking, the signature and
  the hard binding are untouched. What is wrong is that a thumbnail sits in the
  field meaning "sourced from elsewhere" when the generator made it.

  *And the strict reading is arguable.* The requirement is that
  `gathered_assertions` "shall contain one or more URI references to assertions
  that have been provided to the claim generator by other components in the
  workflow" — a positive constraint on what it holds. That putting something else
  there is forbidden comes from the accompanying NOTE, and a NOTE is not
  normative. Removing a feature a person actually sees, on that reading, is not
  proportionate.

  What we do instead is say so: declare 2.4 **with a recorded, inherited
  exception**, rather than manufacture conformance by deleting the thing that
  exposes it.

- **AC6 — the in-process reader still agrees** *(error path)*
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

// No thumbnail setting: AC5 keeps c2pa-rs's auto-generated thumbnail where it
// puts it, and pins that as a documented, inherited exception. Suppressing it
// (`builder.thumbnail.enabled: false`) works, and is deliberately not done.
```

## Open questions

- ~~**Is suppressing the thumbnail the right answer?**~~ **RESOLVED
  (2026-08-31): no — keep it and pin the exception.** The question was posed as
  conformance versus feature, which framed deletion as the rigorous choice. It is
  not: the defect is upstream's, tracked with an intended fix that moves the
  thumbnail rather than removes it; the departure is a mislabelling rather than a
  false statement; and the strict reading rests on a NOTE, which is not
  normative. Manufacturing a clean `gathered_assertions` by deleting the only
  thing in it would make the declaration look better and the package worse. See
  AC5.
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
| AC1 | `tests/Integration/SpecVersion24Test.php` :: it marks the actions assertion as created on the creation path | `service/server.js` — `markActionsAsCreated()` |
| AC2 | `tests/Integration/SpecVersion24Test.php` :: it marks it as created on the manipulated path, keeping the ingredient reference | `service/server.js` — `markActionsAsCreated()`; the ingredient reference is c2pa-rs's |
| AC3 | `tests/Integration/SpecVersion24Test.php` :: it declares 2.4.0 and emits manifests shaped for it; `tests/Integration/SpecVersionTest.php` :: it satisfies every requirement of the version it declares (row 8) | `service/server.js` — `SPEC_VERSION` |
| AC4 | `tests/Integration/SpecVersionTest.php` :: it satisfies every requirement of the version it declares — the docblock states the scope | `tests/Integration/SpecVersionTest.php` — the guard's docblock |
| AC5 | `tests/Integration/SpecVersion24Test.php` :: it still carries the upstream thumbnail, and documents the exception | `docs/readers.md` — the inherited-exception note |
| AC6 | `tests/Integration/SpecVersion24Test.php` :: it keeps the Article 50 marking readable by the extension, on an older engine | `src/Core/Reading/ExtC2paReader.php` — unchanged; this criterion guards it against regression |