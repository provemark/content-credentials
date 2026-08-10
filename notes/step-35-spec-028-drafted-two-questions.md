# Step 35 — SPEC-028 drafted, and the two questions it could not be written without (2026-08-07)

Article 50(2) requires marking content that is "generated **or manipulated**".
This package does the first half. SPEC-028 (draft) is the second half, and two of
its open questions were blocking enough that the spec was written around
measurements rather than around reasoning. Both were measured against the running
container: `@contentauth/c2pa-node` **0.8.1**, c2pa-rs 0.90.4.

No implementation code exists — the spec is `draft`. What follows is probe work.

### ⚠️ The local `service/node_modules` is 0.7.0; the service runs 0.8.1

Nearly read the wrong API surface. `service/package.json` and the lockfile say
0.8.1 and the Docker build honours them (Step 10), but the checkout's own
`node_modules` had never been refreshed. Any claim about "what the library
offers" has to come from inside the container:

```
docker exec c2pa-spike-service-1 node -e "…require('/app/node_modules/…')"
-> version: 0.8.1   addIngredient: function   setIntent: function   addAction: function
```

Same family as Step 23's "check the registry, not the prose", one layer down:
**check the artefact that runs, not the one that happens to be on disk.**

Also worth keeping: `@contentauth/c2pa-node`'s own `Builder.spec.js` ships inside
the image, and it is the authoritative source for API shapes —
`setIntent('edit')` takes a plain string, `addIngredient(parentJsonString,
{buffer, mimeType})`. That is how Step 1 verified the 0.5.x API too, and it beats
the README every time.

### OQ1 — who builds the `c2pa.opened` → ingredient linkage

Three shapes built and signed, then the signed manifests inspected.

| Route | What we supply | `validation_state` |
|---|---|---|
| A | `c2pa.opened` + `c2pa.edited`, no intent | **`Invalid`** |
| B | `setIntent('edit')`, only `c2pa.edited` | `Valid` |
| B2 | `setIntent('edit')`, action via `addAction()` | `Valid` |

**Route A is not a worse option — it is not an available one.** The failure is
`assertion.action.ingredientMismatch`, and the reason is visible in what a
correct `c2pa.opened` actually carries:

```json
"parameters": { "ingredients": [{
  "url": "self#jumbf=c2pa.assertions/c2pa.ingredient.v3",
  "hash": "nP3uvWkY9FColHEVkiXwzC/E90OQapMiYGge/AesTwg=" }] }
```

That hash is over the **ingredient assertion**, which the service constructs.
PHP would have to reproduce c2pa-rs's own assertion serialisation and hashing to
emit it. So the linkage is c2pa-rs's to build, and the only question was what it
costs us.

**It costs nothing.** All three routes produced exactly **one**
`c2pa.actions.v2` assertion: c2pa-rs inserts `c2pa.opened` *into our assertion*
rather than adding a second one, and our `c2pa.edited` survives with its
`digitalSourceType` and `softwareAgent` intact. The double-actions problem that
NOTES Step 1's divergence exists to prevent does not appear, so the invariant
"the client owns the actions assertion" holds under route B.

B over B2 because B leaves `extra_assertions` flowing through the service
unchanged; the whole service delta is `setIntent('edit')` + `addIngredient()`,
both conditional on a parent being present.

### ⚠️ Three things the library will not do for us

1. **`setIntent('edit')` with no ingredient signs anyway** — `Valid`, no error,
   despite `BuilderInterface` documenting that "Edit requires a parent
   ingredient". There is no enforcement underneath us. SPEC-028's AC3/AC5 are
   the only guards that exist.
2. **The contradictory shape signs clean.** Given `c2pa.created` + a `parentOf`
   ingredient + the AI *edit* source type together, c2pa-rs returned `Valid`,
   added no `c2pa.opened`, and warned about nothing — actions stayed
   `[c2pa.created]`. That is exactly the well-formed-but-false manifest SPEC-026
   was written to prevent, and **nothing outside our own builder catches it**.
   Retroactive justification for SPEC-026's split of vocabulary (the enum) from
   policy (the builder): the policy is not a nicety, it is the only check.
3. **The existing created path is untouched.** No intent set, one actions
   assertion, `c2pa.created`, `Valid` — measured alongside, so the regression
   claim is evidence rather than an assumption. Written down as AC11.

### OQ4 — a parent that is already signed

Signed an original the ordinary way, then used that signed file as the
`parentOf` ingredient of a route-B edit, with an unsigned parent as baseline.

Provenance is preserved automatically: the store gains a **second manifest**
(`manifestCount` 1 → 2), the ingredient gains `active_manifest`, `manifest_data`
and `validation_results`, and `validation_state` stays `Valid`. Nothing needs
building for it.

**The check that mattered was our own reader, and it was run rather than
reasoned.** `ManifestStoreParser` resolves `active_manifest` and reads only that
manifest's assertions, so the parent's `trainedAlgorithmicMedia` must not leak
into what we report about the child. Confirmed on the real file through
`ExtC2paReader` (c2pa-rs **0.89.0**, the older engine):

```
isAiGenerated        : false   <- correct: edited, not created
involvesGenerativeAi : true
digitalSourceTypes   : ["compositeWithTrainedAlgorithmicMedia"]
```

A whole-store scan would have reported **both** terms and made `isAiGenerated()`
wrongly true. One accessor away from a bug, in the same predicate SPEC-013 spent
a whole step repairing.

### ⚠️ Provenance accumulates in the bytes

From a 1.7 KB fixture:

| | bytes |
|---|---|
| signed original | 47,748 |
| derived, unsigned parent | 80,840 |
| derived, **signed** parent | **128,448** |

The extra ~47.6 KB is the parent's entire manifest store, carried inside the
child. A second edit carries two, a third all three.

This lands twice: the output is larger, and when it is edited again it is also
the larger *input* to the next request. So a deployment can approach
`MAX_BODY_SIZE` through **edit-chain depth rather than asset size** — a path
neither SPEC-017 nor SPEC-025 was sized for. SPEC-028 AC9 therefore requires
measurement across at least three generations (one before/after pair cannot show
whether a cost compounds), and AC10 requires the README to publish the number
rather than an adjective.

### Where SPEC-028 stands

OQ1 and OQ4 are answered by measurement. OQ2 (do `algorithmicallyEnhanced` and
`humanEdits` unlock too), OQ3 (may the parent's media type differ) and OQ5 (one
size budget for the pair, or two) are maintainer decisions with recommendations
attached, not open investigations. Status stays `draft`.

---

[← Step 34](step-34-spec-027-the-anchors-it-broke.md) · [index](../NOTES.md) · [Step 36 →](step-36-adr-0004-and-the-link-check-again.md)
