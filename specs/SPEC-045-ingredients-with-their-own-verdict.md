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

## Amendment 1 — a writer's record can hide a failure anywhere in the store (approved 2026-10-10)

Found in review before the branch left this machine. A reader drops a status
the writer recorded, matched on **code and url, in any category** (success,
informational, failure), from `validation_status`, `validation_results` and
the record's own nested `ingredientDeltas` — and it collects those records
**store-wide**, not per ingredient (the verifier's
`IngredientManifestCheck::recordedInStore()`, which follows c2pa-rs). So
whoever wrote a manifest in the chain can make a real `claimSignature.mismatch`
disappear from the reader's delta by "recording" it, even under `success`, and
add a `claimSignature.validated` of their own. Under AC3 as first written, a
synthetic store built that way read `isTrusted() === true`.

AC3 gains a fifth condition:

- no writer anywhere in the store recorded a code outside this list:
  `claimSignature.validated`, `claimSignature.insideValidity`,
  `assertion.hashedURI.match`, `assertion.dataHash.match`,
  `assertion.bmffHash.match`, `signingCredential.trusted`,
  `timeStamp.validated`, `timeStamp.trusted`, `ingredient.manifest.validated`,
  `assertion.alternativeContentRepresentation.match`,
  `signingCredential.ocsp.notRevoked`,
  `assertion.dataHash.additionalExclusionsPresent`,
  `ingredient.unknownProvenance`, `ingredient.claimSignature.validated`,
  `assertion.bmffHash.additionalExclusionsPresent`, `timeStamp.malformed`,
  `timeStamp.mismatch`, `timeStamp.outsideValidity`, `timeStamp.untrusted`,
  `signingCredential.ocsp.skipped`, `signingCredential.ocsp.unknown`, and
  `signingCredential.untrusted`. These are the C2PA 2.4 success and
  informational codes as provemark/c2pa-verifier classifies them
  (`StatusCode::isSuccess()` / `isInformational()`), plus the one failure the
  reader re-evaluates itself. **An unknown code counts as a failure.**

If that condition fails, no ingredient in the report is trusted: a recorded
failure code can have hidden a real one from any ingredient's delta. The cost
is that an honest writer who recorded a real fault (as in `CIE-sig-CA.jpg`)
makes every ingredient of that file untrusted — the safe direction. On every
real file in the AC3 table the verdicts are unchanged.

New cases under AC3: a failure recorded under `success`, under
`informational`, under another ingredient, in a record's nested
`ingredientDeltas`, and an unknown code — each turning an otherwise good
ingredient untrusted.

## Amendment 2 — trust flows down, and unreadable evidence is no evidence (approved 2026-10-10)

From an independent review of the branch, before it left this machine.

**Trust flows down.** A rogue-signed image with a genuine, trusted AI
manifest grafted under it as its `parentOf` ingredient read, on all three
readers, as a store that is `Invalid` with an ingredient that is
`isTrusted() === true` and carries `trainedAlgorithmicMedia`. Only the
untrusted top signer vouches that the image was made from that ingredient. AC3
gains a condition, so no caller can get this wrong:

- an ingredient of the active manifest is trusted only if the report is
  (`ManifestReport::isTrusted()`); an ingredient of an ingredient only if that
  parent ingredient is.

This changes two verdicts in the AC3 table: the C2PA Sign upload manifest
above a rogue original is now untrusted (the store is `Valid`, not
`Trusted`), and so is everything under the verifier's logo exception (AC2),
because that store is `Invalid` there.

**Unreadable evidence is no evidence.**

- Two reader deltas under the same `ingredientAssertionURI`: neither counts.
- A delta whose `failure` is present but is not a list, or holds an entry
  without a string `code`: it counts as a failure.

**Records are read in linear time.** A 9.8 MB file with 60,000 nested
deltas in one writer record took 18.6 s to parse; reading the record must be
linear, and stop at the first code outside `RECORDABLE_CODES`.

New cases: the grafted file (`spec045-graft.png`, rogue CA over a trusted AI
original, made by the reviewer with c2patool 0.27.22) on every reader; an
untrusted store over a good ingredient; an untrusted parent over a good
grandchild; duplicate deltas in both orders; malformed failures; a deep and
wide tree against the 64 budget; a large record in bounded time.

## Amendment 3 — one key, one ingredient (approved 2026-10-10)

From a second independent review, of Amendment 2. None of these was shown
reachable through a real reader; each closes a way the evidence for one
ingredient could be read as evidence for another, in the safe direction.

- **The duplicate rule compares normalised keys.** `self#jumbf=c2pa/…` and
  `self#jumbf=/c2pa/…` name the same assertion; two deltas whose keys differ
  only in that leading slash are two deltas under one key, and neither counts.
- **One label, one ingredient.** When two ingredient entries in the same
  manifest carry the same `label`, the single delta under that label cannot
  say which one it validated: neither entry is trusted.
- **The documentation says what a writer record can and cannot do.** A
  record entry without a string `code` is ignored when looking for recorded
  failures: it cannot match a reader status, so it cannot hide one.

Also under Amendment 2, which already required them, two fixes the same review
found: a delta whose `failure` is `null` counts as a failure (present, not a
list), and a malformed delta counts as one of two under its key.

## Amendment 4 — the verifier's logo exception is gone (approved 2026-10-10)

AC2 pinned an exception "until the verifier resolves it": on a C2PA Sign
file with a site logo, the verifier's delta for the upload manifest held
`assertion.missing` (the icon in `c2pa.databoxes`), so that ingredient, and
by Amendment 2 everything below it, read untrusted there. `provemark/c2pa-verifier`
0.6.1 (its SPEC-067) reads the data box and checks its hash, so the
exception is resolved; the pinned test turned red on 0.6.1, as it was meant
to.

- AC2's exception is withdrawn: on `spec045-c2pasign-logo.png` the verifier
  reader reports the same ingredient tree as the extension reader, every
  level trusted, and the integration comparison includes that file.
- The tested verifier range moves to `^0.6.1` (SPEC-042 Amendment 5).

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
| AC2 | `tests/Integration/IngredientEquivalenceTest.php` :: "reports the same ingredients through the service and the verifier", "reports the same ingredients through the service and ext-c2pa"; `tests/Integration/ServiceHarness.php` `accessors()` / `ingredientTree()` (so the SPEC-019 and SPEC-042 comparisons cover it); `tests/Unit/Reading/IngredientsTest.php` :: "reads a logo-bearing C2PA Sign chain through the verifier as c2pa-rs does" (Amendment 4, replacing the pinned exception), "trusts the same logo-bearing ingredient through c2pa-rs" | `src/Core/Reading/ManifestStoreParser.php` `ingredientDeltas()` |
| AC3 | `tests/Unit/Reading/IngredientsTest.php` :: "gives each ingredient the verdict of the SPEC-045 table" (5 files × verifier, extension), "trusts a synthetic ingredient only when every AC3 condition holds" (7 cases), "keeps the relationship verbatim"; Amendment 1: "trusts the good synthetic chain when the writer recorded only allowed codes", "trusts no ingredient once a writer recorded a failure code anywhere" (6 cases), "looks for recorded codes in every manifest of the store, not only the active one"; Amendment 2: "trusts no ingredient of a report that is not trusted", "trusts no grandchild of an untrusted ingredient", "trusts a grandchild when every link above it is trusted", "counts neither of two deltas under the same key" (2 orders), "counts an unreadable failure in a delta as a failure" (4 cases, `null` from the second review), "counts a malformed delta as one of two under the same key" (2 cases), "reads a large writer record in bounded time", and the `graft` row of the table test; Amendment 3: "treats keys that differ only in the leading slash as one key" (2 orders), "still finds a delta written in the relative form", "trusts neither of two ingredient entries that share a label" | `src/Core/Reading/ManifestStoreParser.php` `ingredientTrusted()`, `recordedByWriter()`; Amendment 1: `RECORDABLE_CODES`, `recordsHideNothing()`; Amendment 2: `fromArray()` (the report's trust as the first link), `parseIngredients()` `$aboveTrusted`, `recordHidesNothing()`, `ingredientDeltas()`; Amendment 3: `normalisedUri()`, `parseIngredients()` `$labelCounts` |
| AC4 | `tests/Unit/Reading/IngredientsTest.php` :: "does not trust any ingredient when the reader has no anchors", "does not trust an ingredient without a manifest, or without its own delta", "does not trust an ingredient without a manifest even under a good delta" | `src/Core/Reading/ManifestStoreParser.php` `parseIngredients()`, `ingredientTrusted()` |
| AC5 | `tests/Unit/Reading/IngredientsTest.php` :: "stops at a manifest that names itself", "stops at a cycle through an ancestor", "reports an ingredient naming a label that is not in the store as having no manifest", "reports no deeper than depth 8", "reports no more than 64 ingredients in total", "shares the 64 budget across a deep and wide tree" | `src/Core/Reading/ManifestStoreParser.php` `MAX_INGREDIENT_DEPTH`, `MAX_INGREDIENTS`, `parseIngredients()` |
| AC6 | `tests/Unit/Reading/IngredientsTest.php` :: "degrades malformed ingredient data instead of throwing" (5 cases), "treats an ingredient with a non-string relationship as having none, and still judges it", "reports no ingredients for an empty report" | `src/Core/Reading/ManifestStoreParser.php` `parseIngredients()`, `codesIn()`, `ingredientDeltas()` |

All SPEC-045 tests were run red first (`Call to undefined method
ManifestReport::ingredients()`). Eight mutations of the parser were watched
failing: no `claimSignature.validated` check, delta failures ignored, every
recorded failure excused, no cycle guard, no depth bound, no total budget, no
`signingCredential.trusted` check, and trusting an ingredient without a
manifest — the last one survived the first test set and got its own test.
Amendment 1's seven tests were run red first (the forged store read
`true`); six mutations of it were watched failing: the condition not applied,
only the `failure` category read, nested deltas skipped, `validation_status`
skipped, only the first manifest searched, and `signingCredential.untrusted`
not allowed (over-strict, turns the good cases red).
Amendment 2's twelve tests were run red first (the large record took 19.5 s;
it now takes 0.05 s). Six mutations were watched failing: no gate on the
report's trust, children ignoring their parent's verdict, duplicate deltas
resolved last-wins, unreadable failures ignored, Amendment 1 dropped, and no
`claimSignature.validated` check. The quadratic version is caught by the
bounded-time test.
The second review's two Amendment 2 fixes and Amendment 3 were run red first
(7 tests); five mutations were watched failing: `null` failure read as empty,
a malformed delta not counted as seen, keys not normalised, normalisation a
no-op, and a shared label still getting the delta.
`composer check` green (518 passed, 7 skipped); integration against the local
service with trust settings: 212 passed, 22 skipped, the SPEC-045 comparisons
among the passed.

Amendment 4 (verifier 0.6.1): the pinned logo test turned red on 0.6.1 as
designed and was replaced by an agreement test; the logo fixture joined the
integration comparison (`c2pasign logo`). `composer check` 519 passed;
integration against the local service with trust settings 214 passed.
