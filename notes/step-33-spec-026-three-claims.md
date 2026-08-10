# Step 33 — SPEC-026 implemented: three claims instead of one (2026-08-07)

`compositeSynthetic` and `algorithmicMedia` ship; the three editing terms are
declared and refused; the capture terms are absent by decision.

### Two amendments, both found by trying to write the code

The draft was internally inconsistent and the implementation exposed it within
minutes. **AC4 required the builder to refuse three source types that the Scope
never let into the enum** — a criterion that cannot be expressed. Resolved by
splitting **vocabulary (the enum) from policy (the builder)**, which is better
than either original reading: the refusal can now say *why*, where an absent
constant only says "no such thing". Someone reaching for the editing term learns
it needs ingredients, which is the fact worth conveying.

The second was **AC7**, added before implementation: shipping the writing side
without deciding the reading side would have left a caller who marks
`compositeSynthetic` unable to detect it except by string-matching
`digitalSourceTypes()`. That is the asymmetry the Step 29 review found elsewhere,
about to be created deliberately.

### isAiGenerated() was deliberately not widened

A `compositeSynthetic` asset contains generative AI, so widening the predicate
was tempting. It gates Article 50 decisions in code already written against it,
and SPEC-013 is this project's record of what a predicate that quietly answers
more than it says costs. `involvesGenerativeAi()` is additive and explicit —
true for `trainedAlgorithmicMedia` and `compositeSynthetic`, **false** for
`algorithmicMedia`, which is the entire point of that term.

### AC1 and AC5 cannot be tested in one configuration

A service with `REQUIRE_AI_MARKING=true` refuses the non-AI terms **by design** —
that is AC5. So the round-trip criteria describe a configuration AC5 excludes,
and the two skip past each other. Same shape as SPEC-014's trust-on/trust-off,
and no new CI profile was needed: `defaults` and `hardened` cover one half each.

Worth noticing how it surfaced: the AC1 tests simply went red when the hardened
service was started, which read as a defect for about a minute. The skip
condition is not a workaround — it is the criterion admitting what it needs.

### Verified beyond our own reader

A signed `compositeSynthetic` and `algorithmicMedia` asset, each inspected with
`c2patool` under trust settings:

```
action            : c2pa.created
digitalSourceType : compositeSynthetic / algorithmicMedia
validation_state  : Trusted    status: none
```

So the service passes an unfamiliar source type straight through, and c2pa-rs
neither rewrites nor objects to it. That was a real question — the service
composes its own claim generator and could have had opinions.

### What is still not buildable, and why that is the useful part

The Article 50(4) case — content *manipulated* with AI — remains out of reach,
and now says so in a way a caller meets at the point of asking rather than
discovering later. Building it means ingredients: a second asset as input, its
hash, a `parentOf` relationship, and a `c2pa.opened` action this package has
never emitted. That is the next real piece of work in this direction, and it is
larger than everything SPEC-026 did.

---

[← Step 32](step-32-digitalsourcetype-research.md) · [index](../NOTES.md) · [Step 34 →](step-34-spec-027-the-anchors-it-broke.md)
