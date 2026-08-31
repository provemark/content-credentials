# Step 54 — Declaring a spec version, and who an assertion is attributed to (2026-08-31)

Two specs shipped from this (SPEC-035, SPEC-036) and one was parked (SPEC-037).
The primer got §11 out of it. This is the raw record: what was measured, in what
order, and the three claims that turned out to be wrong along the way — because
the wrong turns are the reusable part.

## The starting question

`claim_generator_info` carries a name, a version and the engine's own
`org.contentauth.c2pa_rs` key. C2PA 2.3 introduced a `specVersion` field for it;
2.4 moved it there from the claim and strengthened the recommendation. Ours
carried none, so a verifier meeting one of these assets had to infer the version
from its shape.

## What the engine does, measured

Signed with `c2patool` 0.27.16 alone — pure c2pa-rs 0.90.16, with neither this
package nor the service in the path:

```
claim_generator_info: [{"name": "…", "version": "…", "org.contentauth.c2pa_rs": "0.90.16"}]
specVersion anywhere in the report: False
```

**The engine never sets it, and that is by design.** `sdk/src/claim.rs` at tag
`c2pa-v0.90.16`: `spec_version` is `Option<String>`, `None` in three
constructors, serialised only `if self.spec_version.is_some()`, with a public
`set_spec_version()`. Opt-in — and the reference tool does not opt in. So if the
value appears in a manifest, a claim generator chose to put it there.

`claim_generator_info` is an **open map**: the engine adds its own key and does
not validate ours. Nothing upstream checks that a declared version is accurate.

**SemVer, not two parts.** 2.3 §10.2.2: the field *"may be present, and if so,
shall contain a SemVer formatted specVersion field"*. So `2.4.0`, never `2.4` —
a SHALL that applies the moment you set the field at all.

## The audit: which version do we actually satisfy?

Done before writing any code, because the answer is the value the code pins.

First pass used the version histories, and reached the right conclusion for a
reason that was only half right. Second pass extracted both specification PDFs
with `pdftotext -layout` and compared §18.15.2 **verbatim**:

- **2.2** — "at least one actions assertion present in **either** the
  created_assertions or gathered_assertions array" (§18.14.2 — note the section
  number shifts between versions)
- **2.3** — same wording
- **2.4** — "at least one actions assertion present in **the created_assertions**
  array"

One word. And it is the word that matters here, because
`gathered_assertions` is defined as the field for assertions *"provided to the
claim generator by other components in the workflow"*, with a NOTE saying that
placing one there declares it *"was not sourced from the claim generator and is
not attributed to the signer"*. For a package whose whole point is one actions
assertion saying "made by a machine", that is close to the opposite of what is
meant.

So: we satisfied 2.3, failed 2.4, and `2.3.0` was the honest declaration at that
moment. It shipped as v0.14.0's predecessor state and was correct.

⚠️ **The 2.3 change log says "Allowed actions to be gathered (not only
created)"**, which reads as though 2.2 required created-only and 2.4 restored it
— a flip-flop. **It is not.** 2.2's normative text permits either, checked
directly. Third time in one day that a change log pointed the wrong way; the
normative text is the record.

## The correction that mattered

Having concluded we failed 2.4, the first answer for *why we could not fix it*
was wrong, and wrong three times over:

1. Searched `c2pa-rs` for `add_created_assertion`, found it only as a definition,
   concluded **"no public builder API exists"**. That tests the guess, not the
   claim.
2. Tried `builder.created_assertion_labels` in the settings — no effect.
3. Tried the `Create` builder intent (`c2patool --create trainedAlgorithmicMedia`),
   where c2pa-rs builds the actions assertion **itself**, digitalSourceType
   included — and it still landed in `gathered_assertions`. That is the strongest
   of the three, and it is why this is not about who supplies the assertion.

From three failures the conclusion drawn was "the lever exists but cannot be
reached", recorded in a SPEC-035 amendment. **Also wrong.**

**The control is a field on the assertion**, in the manifest definition JSON we
already send. `sdk/src/manifest_assertion.rs`:

```rust
/// True if this assertion is attributed to the signer
/// This maps to a created vs a gathered assertion. (defaults to false)
#[serde(default, skip_serializing_if = "std::ops::Not::not")]
created: bool,
```

With `"created": true` on the assertion:

```
created_assertions  -> ['c2pa.actions.v2', 'c2pa.hash.data']
gathered_assertions -> ['c2pa.thumbnail.claim']
validation_state: Valid
```

Measured through `c2patool` **and** through `Builder.withJson(manifest, settings)`
in c2pa-node — this package's own path — and on the **manipulated** path too,
where c2pa-rs inserts the `c2pa.opened` action itself under the edit intent.

**Failing to find a thing is not evidence that it is absent.** Three mechanisms
of my own invention failed, and I read that as a fact about the world.

## Who sets the flag, and the design error that revealed it

SPEC-036 first put `"created": true` in the PHP client, with a criterion refusing
a client and service that disagreed. Implementing it turned **37 integration
tests red** — which is exactly what a user's deployment would have done on a
rebuild without a `composer update`. Total signing failure, to prevent a
mismatch.

The breakage was the symptom. The error was deeper: **`created` means "attributed
to the signer", and the signer is the service.** Where an assertion sits in the
claim is a property of how the claim generator builds it, like
`claim_generator_info`, which the service has always owned. Putting it in the
client handed a decision about claim *structure* to the party that does not sign.

Moving it to `markActionsAsCreated()` in `server.js` **removed** the mismatch
rather than guarding it, and the criterion went with it. Nobody breaks, both
halves travel by the same route, and there is no combination left to police.

The division worth remembering: **contents are the caller's, structure is the
generator's.** The primer §3 said the client "owns" the actions assertion; it now
says "supplies", because that one word would have made the first design
obviously wrong before it was written.

## Two things nothing will tell you

**`validation_state: Valid` proves nothing about any of this.** c2pa-rs does not
validate the placement rules. A manifest with its actions assertion in
`gathered_assertions` — violating 2.4 §18.15.2 — reads back `Valid` with no
status entry about it. Nor does anything validate a declared `specVersion`
against what the manifest does. Both are claims. Assert structure directly.

**The created/gathered split is only visible through `c2patool --detailed`.**
Neither `POST /v1/read` nor `ExtC2paReader` surfaces those arrays, and CI does
not install c2patool. So the tests assert the `created` flag on the read-back
assertion — observable everywhere, and the thing we control. SPEC-035 AC7 pins
the engine version, which is the alarm that prompts re-measuring the mapping
after a bump.

## The thumbnail, which is not about 2.4 at all

`gathered_assertions` means "not sourced from the claim generator" in 2.3 and 2.4
alike. The thumbnail c2pa-rs generates for us sits there, and the generator made
it. That contradiction is in manifests this package signed **before** any of this
work, and raising to 2.4 neither caused nor cured it.

Upstream's default, tracked as
[c2pa-rs #2106](https://github.com/contentauth/c2pa-rs/issues/2106) with a
`// todo: add setting for created added thumbnails` on the responsible line, and
a fix that **moves** the thumbnail rather than removing it. It affects every tool
built on c2pa-rs, `c2patool` included.

Suppressible — `builder.thumbnail.enabled: false` leaves `gathered_assertions`
absent entirely, measured — and deliberately not done. Deleting the only thing
that exposes a problem makes the declaration look tidier and the package worse.
Recorded in `docs/readers.md` instead.

## What was parked

**SPEC-037, `c2pa.ai-disclosure`** (2.4 §18.28). Offers `humanOversightLevel` —
`fully_autonomous` / `prompt_guided` / `human_validated` — the axis
`digitalSourceType` cannot express, and the one a regulator asks about.

Not built, for three measured reasons: the CDDL says the schema is unfinished
(`modelFrontier`, `trainingCleared`, `category` are commented-out placeholders);
`c2pa-rs` and `c2pa-node` have **zero** support; and the one **mandatory** field,
`modelType`, is a machine-learning *framework* taxonomy — ONNX, Keras, JAX,
Core ML — that a caller consuming a generation API cannot know. That last is the
SPEC-026 objection again: never invite a caller to attest to what they cannot
know.

Transport is **not** the obstacle. The assertion signs and round-trips verbatim
through `extra_assertions` with `validation_state: Valid`, measured.

## The pattern behind the wrong turns

Three of today's errors had one shape: **I tested my own hypothesis and treated
the result as a statement about the world.** Counting hits in a code search and
concluding "blocked". Trying three mechanisms and concluding "no mechanism
exists". Writing "you do not need to change anything" an hour after repairing
thirteen tests that broke on exactly that change.

None of them was caught by a test. The suite was green through all three. What
caught them was being asked a second time, and re-reading my own output as a
reader rather than as its author.