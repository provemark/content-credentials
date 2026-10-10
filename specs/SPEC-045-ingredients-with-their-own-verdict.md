# SPEC-045: Ingredients, each with its own verdict

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon, 2026-10-10                      |
| Supersedes | — (lifts SPEC-003 D4 "active manifest only, deferred for v1") |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`ManifestReport` describes the active manifest only (SPEC-003 D4, "deferred
for v1"). `ManifestStoreParser` drops every other manifest in the store, and
with them every ingredient.

The Drupal module `drupal/content_credentials` met the case that makes this a
gap (its issue #3629737, measured in its NOTES Step 33 on 2026-10-10). A site
running C2PA Sign re-signs every upload in place: an AI image's
`c2pa.created` + `trainedAlgorithmicMedia` manifest becomes a `parentOf`
ingredient under C2PA Sign's `c2pa.opened` + `c2pa.creativeWork`, and a second
time under `c2pa.opened` + `c2pa.published` when the content is published. The
active manifest carries no `digitalSourceType`. On such a site the module
suggests nothing for any upload, because the AI origin is one or two
ingredients down and this library never reports it.

The data is there on every route. Measured on all three readers (service
c2pa-rs 0.90.22 with trust settings, ext-c2pa c2pa-rs 0.89.0, verifier 0.5.3),
on files from C2PA Sign 1.4.11 + c2patool 0.9.12, from c2patool 0.27.22 `-p`,
and on the c2pa-rs fixtures `CIE-sig-CA.jpg` and `E-sig-CA.jpg`:

- `manifests` holds every manifest in the store, each with its own
  `assertions` (actions and their `digitalSourceType` included) and
  `ingredients`.
- An ingredient entry carries `relationship`, `label` (the assertion label,
  e.g. `c2pa.ingredient`, `c2pa.ingredient.v3`), `active_manifest` (the label of
  its manifest in the store, absent when it has none), and — depending on the
  writer — what the writer recorded about it (`validation_results`, or
  `validation_status` for older writers).
- `validation_results.ingredientDeltas[]` holds the **reader's own** validation
  of each ingredient, keyed by `ingredientAssertionURI` =
  `self#jumbf=/c2pa/{label of the manifest that names it}/c2pa.assertions/{ingredient label}`.
  Same key on all three routes, for claim v1 and v2, at depth one and two.

**The store verdict does not cover an ingredient.** Only the active manifest's
signer can make the state `Trusted`, and a status the writer recorded about an
ingredient is dropped from both the delta and the verdict (C2PA 2.4
§18.16.12.4; the verifier's `IngredientManifestCheck` follows c2pa-rs here).
Measured consequences:

| ingredient | writer recorded | reader's delta | store state | AC3 verdict |
|---|---|---|---|---|
| good, C2PA Sign + 0.9.12 | nothing | `signingCredential.trusted`, `claimSignature.validated` | Trusted | trusted |
| good, c2patool 0.27 `-p` | `claimSignature.validated`, `signingCredential.untrusted` | `signingCredential.trusted` only | Trusted | trusted |
| **broken signature** (`CIE-sig-CA.jpg`) | **`claimSignature.mismatch`** | **`signingCredential.trusted`**, no failure | Trusted | **not trusted** |
| untrusted signer, C2PA Sign + 0.9.12 | nothing | failure `signingCredential.untrusted` | Valid | not trusted |
| untrusted signer, c2patool 0.27 `-p` | `claimSignature.validated`, `signingCredential.untrusted` | nothing trust-related | **Trusted** | not trusted |

So neither the store verdict, nor the delta alone, nor the writer's record
alone says whether an ingredient can be relied on. The last two rows are the
reason this belongs in the library and not in each caller: a caller reading
the delta's `signingCredential.trusted` alone would accept a manifest whose
signature does not verify.

## Scope

**In scope**

- `ManifestReport::ingredients()`: the active manifest's ingredients, each
  with its relationship, whether it has a manifest, that manifest's
  `digitalSourceType`s, its own ingredients (recursively, bounded), and a
  verdict of its own.
- The verdict rule (AC3), defined once, in `ManifestStoreParser`, the parser
  every reader shares.
- `VerifierOutcome` passes the same store shape through, so the verifier route
  gets the same data.

**Out of scope** (each needs its own spec before it may be built)

- Any policy about *using* an ingredient's type: which relationship counts,
  how deep, what a caller suggests. That is the caller's (for the Drupal
  module, its own spec).
- `componentOf` / `inputTo` semantics beyond reporting the relationship
  string verbatim.
- Ingredients without an embedded manifest that point at a remote one.
- Changing `isTrusted()`, `digitalSourceTypes()` or any existing accessor.
- The verifier not resolving a C2PA Sign icon stored in `c2pa.databoxes`
  (`assertion.missing`, Drupal module NOTES Step 33 §4): a
  `provemark/c2pa-verifier` finding, filed there.

## Behavior

- **AC1 — the ingredient chain is reported**
  - Given a file re-signed twice by C2PA Sign (publish over upload over an AI
    original), read with trust anchors that include its signer
  - When it is read through any of the three readers
  - Then `ingredients()` has one entry, relationship `parentOf`, with a
    manifest and no `digitalSourceType`; its `ingredients()` has one entry
    whose `digitalSourceTypes()` is
    `['http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia']`;
    and the existing accessors are unchanged (`digitalSourceTypes()` still `[]`).

- **AC2 — every reader agrees**
  - Given the AC1 file and the rows of the table above
  - When read through the service and ext-c2pa readers
  - Then `ingredients()` compares equal (SPEC-019 AC2: the accessor is added
    to `spec019Accessors()`), and the verifier reader matches them on the same
    files.
  - One known exception, pinned rather than hidden: on a C2PA Sign file whose
    manifest carries a site logo, the verifier's delta for that ingredient
    holds `assertion.missing` (the icon in `c2pa.databoxes`), so its verdict
    there is false. AC2's files are made without a logo; a separate test
    records the exception until the verifier resolves it.

- **AC3 — an ingredient is trusted only on the reader's own evidence**
  - Given an ingredient with a manifest
  - When its verdict is computed
  - Then `isTrusted()` is true only if **all** hold:
    - the reader's delta for it contains `signingCredential.trusted`;
    - `claimSignature.validated` is in the delta's success **or** in what the
      writer recorded as success;
    - the delta has no failure;
    - what the writer recorded has no failure other than
      `signingCredential.untrusted` (which the reader re-evaluated against its
      own anchors).
  - Each row of the table above yields the verdict in its last column.

- **AC4 — no evidence is not trust** *(error path)*
  - Given an ingredient without a manifest, an ingredient whose delta is
    missing, a delta under a key that names no ingredient, or a reader run
    without trust anchors
  - When the report is built
  - Then that ingredient's `isTrusted()` is false and nothing throws.

- **AC5 — hostile chains are bounded** *(error path)*
  - Given a store whose ingredients form a cycle (a manifest naming itself or
    an ancestor), name a label not in `manifests`, nest deeper than the bound,
    or list more ingredients than the bound
  - When the report is built
  - Then the walk stops at the bound or the repeat, the ingredients beyond it
    are absent (not an error), and building the report does not throw.

- **AC6 — malformed ingredient data degrades, never throws** *(error path)*
  - Given `ingredients` that is not a list, entries that are not objects,
    non-string labels or relationships, or `ingredientDeltas` of the wrong type
  - When the report is built
  - Then the affected entries are skipped or report false/[]/null, as every
    other field in `ManifestStoreParser` does.

## API sketch

```php
// namespace Provemark\ContentCredentials\Core\Reading;

final readonly class IngredientReport
{
    public function relationship(): ?string;        // 'parentOf', 'componentOf', … verbatim
    public function hasManifest(): bool;
    public function digitalSourceTypes(): array;    // list<string>, its manifest's actions
    public function isTrusted(): bool;              // AC3
    /** @return list<IngredientReport> */
    public function ingredients(): array;           // its own, bounded (AC5)
}

// ManifestReport
/** @return list<IngredientReport> */
public function ingredients(): array;
```

## Open questions

Resolved at approval (maintainer, 2026-10-10):

- **Bounds** (AC5): depth 8, 64 ingredients in total.
- **Reasons**: `isTrusted()` returns a bool only; exposing the codes it looked
  at is not in this spec.
- **Release**: a minor, 0.19.0. The Drupal module moves its requirement only
  with its own spec. Whether that lands before its 1.0.0-alpha1 is decided on
  2026-10-14, not promised.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/Reading/IngredientsTest.php` :: "reports the AI origin two ingredients down a C2PA Sign chain" (verifier, extension) | `src/Core/Reading/ManifestStoreParser.php` `parseIngredients()`; `src/Core/Reading/IngredientReport.php`; `src/Core/Reading/ManifestReport.php` `ingredients()` |
| AC2 | `tests/Integration/IngredientEquivalenceTest.php` :: "reports the same ingredients through the service and the verifier", "reports the same ingredients through the service and ext-c2pa"; `tests/Integration/ServiceHarness.php` `accessors()` / `ingredientTree()` (so the SPEC-019 and SPEC-042 comparisons cover it); `tests/Unit/Reading/IngredientsTest.php` :: "pins the verifier refusing an ingredient whose C2PA Sign logo it cannot resolve", "trusts the same logo-bearing ingredient through c2pa-rs" | `src/Core/Reading/ManifestStoreParser.php` `ingredientDeltas()` |
| AC3 | `tests/Unit/Reading/IngredientsTest.php` :: "gives each ingredient the verdict of the SPEC-045 table" (5 files × verifier, extension), "trusts a synthetic ingredient only when every AC3 condition holds" (7 cases), "keeps the relationship verbatim" | `src/Core/Reading/ManifestStoreParser.php` `ingredientTrusted()`, `recordedByWriter()` |
| AC4 | `tests/Unit/Reading/IngredientsTest.php` :: "does not trust any ingredient when the reader has no anchors", "does not trust an ingredient without a manifest, or without its own delta", "does not trust an ingredient without a manifest even under a good delta" | `src/Core/Reading/ManifestStoreParser.php` `parseIngredients()`, `ingredientTrusted()` |
| AC5 | `tests/Unit/Reading/IngredientsTest.php` :: "stops at a manifest that names itself", "stops at a cycle through an ancestor", "reports an ingredient naming a label that is not in the store as having no manifest", "reports no deeper than depth 8", "reports no more than 64 ingredients in total" | `src/Core/Reading/ManifestStoreParser.php` `MAX_INGREDIENT_DEPTH`, `MAX_INGREDIENTS`, `parseIngredients()` |
| AC6 | `tests/Unit/Reading/IngredientsTest.php` :: "degrades malformed ingredient data instead of throwing" (5 cases), "treats an ingredient with a non-string relationship as having none", "reports no ingredients for an empty report" | `src/Core/Reading/ManifestStoreParser.php` `parseIngredients()`, `codesIn()`, `ingredientDeltas()` |

All SPEC-045 tests were run red first (`Call to undefined method
ManifestReport::ingredients()`). Eight mutations of the parser were watched
failing: no `claimSignature.validated` check, delta failures ignored, every
recorded failure excused, no cycle guard, no depth bound, no total budget, no
`signingCredential.trusted` check, and trusting an ingredient without a
manifest — the last one survived the first test set and got its own test.
`composer check` green (491 passed, 7 skipped); integration against the local
service with trust settings: 210 passed, 22 skipped, the SPEC-045 comparisons
among the passed.
