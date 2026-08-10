# Step 32 — The digitalSourceType research, and what it changed (2026-08-07)

Step 26 settled the shape of the builder family (form A) and left one thing to
verify before writing the spec: whether the manipulated case differs only in
`digitalSourceType`, or also in the action sequence. Verified against the
sources. **It differs, and much more than expected.**

### The manipulated case needs ingredients, which this package does not have

C2PA Implementation Guidance 2.4 is explicit: AI editing is recorded as a
`c2pa.edited` or `c2pa.placed` action carrying the source type, with a
`c2pa.opened` action first, "pointing to an ingredient assertion for the
original photo, where a `parentOf` relationship is indicated".

So Article 50(4)'s case is three things, not one constant:

1. `c2pa.opened` first, referencing an ingredient;
2. an ingredient assertion for the original, `relationship: parentOf`;
3. `c2pa.edited` carrying the AI `digitalSourceType`.

`ManifestBuilder` emits one assertion. `service/server.js` passes
`ingredients: []`. Supporting this means the caller supplies the *original*
asset so a hash can be computed over it — a new input to the entire signing
path. That is a bigger piece of work than the whole source-type spec, which is
why SPEC-026 (draft) ships the **created-time** terms and puts the edited ones
out of scope behind an explicit exception.

Had this not been checked first, the obvious implementation — one more enum case
— would have produced a well-formed manifest making a **false claim**: that the
asset was *created* by an operation which by definition acts on one that already
existed.

### ⚠️ The guidance misspells the IPTC term

The guidance writes `compositedWithTrainedAlgorithmicMedia`. IPTC has no such
concept. The registered term is `compositeWithTrainedAlgorithmicMedia` — no "d".
Implementing from the prose would emit a URI resolving to nothing, inside the
assertion whose entire purpose is machine readability.

Rule for this project, now written down: **source-type URIs come from
`cv.iptc.org`, never from a document quoting it.**

### ⚠️ And the CAI docs describe that term more loosely than IPTC defines it

CAI: "assets containing elements created by generative AI". IPTC: "Augmentation,
correction or enhancement **using** a Generative AI model, such as with
inpainting or outpainting". Those are different claims. For "a new asset mixing
AI and non-AI elements" the registered term is `compositeSynthetic`.

Reaching for `compositeWithTrainedAlgorithmicMedia` because its name sounds like
"composite" would assert that an original existed and was edited. That is the
kind of error nobody notices, because the manifest is valid and the assertion is
present — it is simply about something that did not happen.

### The vocabulary as it stands (fetched 2026-08-07)

Active and relevant: `trainedAlgorithmicMedia`,
`compositeWithTrainedAlgorithmicMedia`, `compositeSynthetic`, `composite`,
`compositeCapture`, `algorithmicMedia`, `digitalCapture`, `computationalCapture`,
`algorithmicallyEnhanced`, `humanEdits`, `digitalCreation`, `dataDrivenMedia`,
`virtualRecording`, `screenCapture`, `negativeFilm`, `positiveFilm`, `print`.

**Retired, never to be emitted:** `minorHumanEdits` and `digitalArt` (both
2024-09-17), `softwareImage` (2022-06-14). Worth knowing because older examples
on the web still use them.

### What the draft asks the maintainer

Three open questions, and the third is the one worth sleeping on: **does the
authenticity case belong in this package at all?** Everything here is built
around Article 50 and AI marking. `digitalCapture` is the opposite claim, made by
cameras — and a PHP web application asserting that it captured a photograph is
asserting something it cannot know. Cheap to support, and it may invite a use
this package is not positioned for.

### Open question 3, settled the same day: no authenticity claims here

Answered: **not in this package, not now** — and the consequence is wider than
the one term that prompted it. `digitalCapture` and `computationalCapture` claim
a device recorded something; `digitalCreation` claims a human made it without
generative tools; the film and print terms claim a physical original. **A PHP web
application cannot know any of them.** It receives bytes. Whatever it asserts
about their origin it is repeating something it was told — and a C2PA assertion
is signed with a certificate, which turns hearsay into attestation.

Not an argument against ever supporting them, but against supporting them as
"an enum case you can pass". The caller would have to be the capture device or
vouch for it, and the package would have to say so loudly. That is a different
product decision, and nothing is asking for it.

What remains is coherent, and sharper than the draft was: this package marks
**synthetic** media. `trainedAlgorithmicMedia` (exists), `compositeSynthetic`
(a mix containing generative AI), and `algorithmicMedia` (purely algorithmic,
not trained on sampled data). The last is worth having precisely because it is a
*negative* claim about AI — it distinguishes procedural output from generative
output instead of leaving both unmarked.

The draft narrowed accordingly: two new cases instead of five, `forCaptured()`
dropped before it existed, and SPEC-026 AC5 now tests `REQUIRE_AI_MARKING`
against `algorithmicMedia` rather than `digitalCapture` — synthetic but
explicitly not trained, which is the sharpest test of a policy that names
`trainedAlgorithmicMedia`.

Sources: C2PA Implementation Guidance 2.4; IPTC Digital Source Type NewsCodes;
CAI open-source documentation on writing assertions and actions.

---

[← Step 31](step-31-spec-025-client-side-bounds.md) · [index](../NOTES.md) · [Step 33 →](step-33-spec-026-three-claims.md)
