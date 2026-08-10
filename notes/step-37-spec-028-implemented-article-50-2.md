# Step 37 — SPEC-028 implemented: the second half of Article 50(2) (2026-08-07)

Content *manipulated* with AI can now be marked. Route B throughout, as Step 35
measured: the client emits one `c2pa.edited` action, the service sets
`setIntent('edit')` and calls `addIngredient()`, and c2pa-rs writes the
`c2pa.opened` action and its linkage into our own actions assertion.

Verified end to end rather than through our own reader alone — `c2patool` with
trust settings on a signed manipulated PNG:

```
c2pa.opened  -> parameters.ingredients[0] = {url: self#jumbf=…/c2pa.ingredient.v3, hash: …}
c2pa.edited  -> softwareAgent + compositeWithTrainedAlgorithmicMedia
ingredients  : [('parent', 'parentOf')]     validation_state: Trusted     status: []
```

### ⚠️ The measurement that was wrong looked like good news

AC9's first run reported a memory multiplier of **0.8×**, against SPEC-017's ~7×
for a single asset. A number that low is not a pleasant surprise, it is a broken
measurement — and this one was broken twice over: the baseline was 133 MiB
because the container had just run the full integration suite, and the asset
pair was sized so close to the body limit that the requests were plausibly
refused rather than signed.

Restarted the container and made the script **assert the HTTP statuses**:

```
HTTP statuses : 200 200 200 200
idle baseline : 24.4 MiB   peak with 4 in flight : 244.1 MiB
per request   : 54.9 MiB   multiplier vs the PAIR : 4.6x
```

The lesson is the sibling of everything in Steps 20, 21 and 34: **a measurement
taken over work that did not happen reads as a small number, not as an error.**
Any load measurement has to prove the load arrived. The script now prints a
warning when a status is not 200.

The answer AC9 wanted: **`MAX_BODY_SIZE` needs no change.** A manipulation
request is bounded by the same limit, and the largest admissible *pair* is
smaller than the largest admissible single asset, so the peak (≈245 MiB) sits
below SPEC-017's ≈420 MiB. The parent is hashed, not signed.

### Generational growth is linear, and that was worth measuring

```
gen 1 (created)  55,455    gen 2  144,301 (+88,846)
gen 3  233,924 (+89,623)   gen 4  323,547 (+89,623)
```

Constant ~89.6 KB per generation. Step 35 established that a signed parent's
manifest is carried into the child and worried in the spec that it "compounds";
four generations show it does not. Had the child embedded the parent's whole
accumulated store, gen 3 would have been ≈288 KB rather than 234 KB. The
mechanism was deliberately not chased — the measured shape is what the README
publishes, and a mechanism nobody verified is how this log fills with things
that turn out to be wrong.

### ⚠️ `bin/verify.sh` gave a wrong answer, and it is the authoritative check

CLAUDE.md names it as the authoritative verification. It reported a correctly
marked manipulated asset as `AI Art.50 mark : FAIL`, because it tested for
`trainedAlgorithmicMedia` alone — while Article 50(2) covers generated **or**
manipulated. So the one tool the project trusts to arbitrate would have said no
to exactly the content this spec added. Now recognises both and names which it
found; checked in both directions.

Worth generalising: this is the fourth place in the repo where a list of
"what counts as supported" had gone stale (SPEC-021's three allow-lists, SPEC-023's
413 wording, SPEC-027 AC2's directory glob, now this). Every one of them was a
hand-written enumeration of something that grew.

### ⚠️ A documented example that did not compile

SPEC-028's API sketch and `docs/marking.md` both show
`ContentCredentials::sign($asset, $manifest, parent: ...)` — the Laravel facade.
`ContentCredentialsManager::sign()` still took two parameters, and **no
acceptance criterion covered the Laravel layer at all**, so nothing would have
caught it: the Core signer's tests bypass the manager entirely.

Added, plus a test asserting the third parameter exists by reflection rather
than trusting the prose. The spec gap is recorded in its implementation notes
rather than papered over — the criteria were written about Core and the service,
and the sketch quietly assumed a third layer.

### ⚠️ `?? 'missing'` cannot test for null

AC8 asserts the audit record's `parent_bytes` is null when nothing was derived.
Written as `expect($record['parent_bytes'] ?? 'missing')->toBeNull()`, which
**cannot pass**: null coalescing returns the fallback for a real `null` exactly
as it does for an absent key. It reported correct behaviour as broken. Presence
and nullness are now asserted separately, which is also the stricter contract —
the field must be *there* and null, so a reader can tell lineage was considered.

Same family as Step 14's over-long `creator_name`: a test of mine that was wrong
about the code rather than the other way round.

### SPEC-026's AC4 tests were rewritten, not deleted

They asserted the refusal SPEC-028 removes. Deleting them would have lost the
criterion; leaving them would have pinned behaviour that no longer exists. What
AC4 actually guarded survives intact — an editing term must never ride on
`c2pa.created` — so they now assert that, and SPEC-026's traceability row was
updated (the only section of an `approved` spec that may change). Same move as
Step 13 when SPEC-013 amended SPEC-003 D3.

### Two smaller findings

- **PHPStan caught a vacuous test again**, the eighth in this log and the second
  by a tool: `is_subclass_of(MissingParentAssetException::class,
  ContentCredentialsException::class)` is provably always true once the class
  exists, because `implements` is enforced by the type system. Removed rather
  than silenced, with a comment where it stood so nobody restores it.
- **`bin/spec-check.php` needs the bolded AC title on ONE line.** Its criteria
  regex is `/m` without `/s`, so a title wrapped across two lines is invisible
  and the traceability row is reported as stale. It reported that clearly —
  `AC12 has a traceability row but no entry in Behavior` — which is the tool
  working, not failing.

### Verified

`composer check` green (293 passed), integration **109 passed / 11 skipped**
(defaults) and **107 / 13** (hardened, `REQUIRE_AI_MARKING=true`),
`php bin/spec-check.php` 0 errors, `bin/e2e.php` green, `bin/verify.sh` PASS on
both a generated and a manipulated asset.

---

[← Step 36](step-36-adr-0004-and-the-link-check-again.md) · [index](../NOTES.md) · [Step 38 →](step-38-reviewing-0-9-0-after-shipping.md)
