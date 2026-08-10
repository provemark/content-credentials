# Step 28 — SPEC-023 implemented: thirteen media types (2026-08-07)

SVG, MOV, AVI and FLAC shipped. Straightforward, because SPEC-021 built the
machinery — two criteria there derive their expectation from
`MediaType::cases()`, so they widened by themselves. What is worth recording is
what broke, and what the SVG measurement changed.

### The morning's upgrade note came true before lunch

SPEC-022 added a CHANGELOG note warning consumers that adding enum cases makes
an exhaustive `match ($mediaType)` throw `UnhandledMatchError`. SPEC-021's own
`cc21Fixture()` helper is exactly such a match, and it was the first thing to
fail. Our own suite was the first consumer bitten by our own upgrade note —
which is the cheapest possible confirmation that the note was worth writing.

### ⚠️ A fourth counter-example overtaken by scope

`image/svg+xml` sat in two places as an example of "unsupported": the SPEC-021
unit dataset and the Eris property pool. Both went red. That pool has now lost
members twice — gif/webp/tiff in SPEC-021, svg here.

It is refilled with types measured as genuinely out of reach (`application/pdf`,
`video/webm`, `image/jxl`) **plus malformed input** (`imagepng`, `image/`, `''`).
The malformed half is the part that cannot go stale, and in hindsight that is
what a negative pool should have been built on from the start: a rule about
shape, not a list of things we happen not to support yet.

### The 413 message is now derived

SPEC-021 hand-wrote the oversized-body refusal around `video/mp4`. With three
video types that sentence was quietly wrong — someone sending a MOV would read
about MP4 and reasonably conclude it did not apply. `VIDEO_MIME_LIST` is now
filtered out of `SUPPORTED_MIME`, so a fourth video type cannot repeat it.

Same move as the enum-derived error messages in SPEC-021/022: every place that
lists what we support is derived from the one list, or it will drift.

### SVG shipped because of a measurement, not despite one

The open question was whether to ship SVG at all, given that build tooling
destroys the credential. Answered by measuring rather than reasoning:

| Operation | Result | What a verifier sees |
|---|---|---|
| SVGO, default preset | 17.7 KiB → 0.1 KiB | `hasManifest() === false`, **silently** |
| XML re-serialisation | payload intact | `error parsing SVG: invalid file` |

`preset-default` includes `removeMetadata`, and every common bundler runs SVGO
with defaults. The decision was to ship with the README carrying the measurement
verbatim: refusing would protect nobody — someone wanting a signed SVG reaches
for another route and gets no warning at all — while it would exclude the
legitimate case, a generated diagram delivered as a file.

The second failure mode was found by accident and is the more insidious one: the
namespace prefix (`c2pa:` → `ns1:`) changes on any XML rewrite, so the bytes
survive and the file becomes unparseable. Any XML tool does this, SVGO or not.

### Verified

`composer check` green (214 passed). Integration **88 passed / 5 skipped**.
`bin/e2e.php` green. All four new formats signed through `SigningServiceSigner`
and checked with `bin/verify.sh` (c2patool, trust on): signature valid / cert
trusted / Art.50 mark PASS on each. `php bin/spec-check.php` 0 errors.

### What is left, and it is not much

`0.8.0` now carries SPEC-021, 022 and 023 — thirteen media types, a builder
entry point that matches them, and four documented refusals. The next real piece
of work is the `digitalSourceType` family (form A, NOTES Step 26), and it needs
the action-sequence question answered against the C2PA spec first.

---

[← Step 27](step-27-measuring-remaining-formats-and-pdf.md) · [index](../NOTES.md) · [Step 29 →](step-29-codebase-review-two-defects.md)
