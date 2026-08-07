# SPEC-023: The four remaining formats that actually work

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | 2026-08-07 (maintainer)                           |
| Supersedes | — (extends SPEC-021)                              |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-021 put six formats out of scope with a precise reason: *"Not excluded on
principle — simply unmeasured, and this project does not ship what it has not
seen work."* They have now been measured (NOTES Step 27). Four work; the rest do
not, and each "no" has a different cause worth keeping.

### Measured 2026-08-07

Signed inside the running service with `@contentauth/c2pa-node` 0.8.1 (c2pa-rs
0.90.4) bypassing `SUPPORTED_MIME`, read back, verified with `c2patool` under
trust settings, and re-read through `ExtC2paReader` (c2pa-rs 0.89.0):

| Format | MIME | Sign | Read back | Art. 50 | c2patool | ext-c2pa |
|---|---|---|---|---|---|---|
| SVG | `image/svg+xml` | ok | `Valid` | present | PASS | agrees |
| MOV | `video/quicktime` | ok | `Valid` | present | PASS | agrees |
| AVI | `video/x-msvideo` | ok | `Valid` | present | PASS | agrees |
| FLAC | `audio/flac` | ok | `Valid` | present | PASS | agrees |

### SVG survives signing, and not much else (measured 2026-08-07)

The manifest lands in `<metadata><c2pa:manifest>` as base64. Two ordinary
operations were run against a signed SVG and read back:

| Operation | Result | What a verifier sees |
|---|---|---|
| SVGO, default settings | 17.7 KiB → 0.1 KiB | `hasManifest() === false` — **silently gone** |
| Any XML re-serialisation | 18.1 KiB, payload still present | **`error parsing SVG: invalid file`** |

SVGO's `preset-default` includes `removeMetadata`, so it deletes the element
outright and the image renders identically afterwards: indistinguishable from an
SVG that was never signed. The second case is subtler — re-serialising renames
the namespace prefix (`c2pa:` → `ns1:`), the bytes survive, and c2pa-rs then
refuses to parse the file at all.

The immutability rule (`docs/c2pa-primer.md` §6) already covers "post-sign
mutation invalidates". SVG is different in *who decides*: re-encoding a JPEG is
something a developer chooses, while SVGO runs because an SVG was added to a
build — every common bundler invokes it with default settings. That is why AC4
requires this measurement in the README rather than a general caveat.

FLAC was not on SPEC-021's list at all. It surfaced from the parser names inside
the native binary and turned out to work end to end, so it belongs here rather
than in a third round.

### Why the other four stay out

- **PDF** — c2pa-rs registers readers and writers separately; PDF is a **reader
  only**. `sdk/src/asset_handlers/pdf_io.rs` returns
  `Err(NotImplemented(WRITE_NOT_IMPLEMENTED))` from all three write methods
  ("PDF write functionality will be added in a future release"). The C2PA
  specification *does* define PDF embedding (§3.4; Appendix A.4), so this is an
  implementation gap upstream, not a specification one. It cannot simply ride
  along when that changes — see NOTES Step 27 for why our two engines disagreeing
  on PDF is an architecture question, not a list entry.
- **WEBM** — Matroska. c2pa-rs has no handler in either register, which is why
  it fails on *reading* too. Nothing suggests it is coming.
- **DNG** — **not measured.** The probe was a plain TIFF named `.dng`; the
  declared type is advisory, so c2pa-rs saw TIFF, which we already support. The
  green result measured nothing. A real DNG is needed first.
- **JPEG XL** — a handler exists (`jpegxl_io.rs`), discovered while investigating
  the above. Unmeasured, and this spec does not ship what it has not seen work.

## Scope

**In scope**

- `image/svg+xml`, `video/quicktime`, `video/x-msvideo` and `audio/flac` added to
  `MediaType`, to `SUPPORTED_MIME` in `service/server.js`, and to the extension
  map in `InfersMediaType` (`.svg`, `.mov`, `.avi`, `.flac`).
- Two alias spellings, settled before approval: `audio/x-flac` → `audio/flac` and
  `video/avi` → `video/x-msvideo`. Same reasoning as SPEC-021's `audio/mp3`.
- A test per format that signs and reads back, asserting the Article 50 marking
  survives — the same bar SPEC-021 set.
- **The oversized-body refusal, which SPEC-021 wrote around `video/mp4` alone.**
  Two more video types make that message incomplete; see AC3.
- **README caveats that are specific to these formats**, not a longer table: SVG
  is text, and FLAC is the first format whose realistic files approach the body
  limit. See AC4 and AC5.

**Out of scope** (each needs its own spec before it may be built)

- PDF, WEBM, DNG, JPEG XL — for the four distinct reasons above.
- Any capability model for asymmetric support (a format one engine can read and
  the other cannot, or a format that can be read but not signed). That is what
  PDF would eventually need; it is deliberately not built in advance of knowing
  what actually arrives (NOTES Step 27).
- Raising `MAX_BODY_SIZE` or the concurrency cap. FLAC makes the limit bite
  sooner; changing the number needs its own measurement, exactly as SPEC-017 did.
- Any change to the manifest, to `digitalSourceType` values, or to the builder.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-023')`.

- **AC1 — every added format signs and reads back**
  - Given an asset in each of `image/svg+xml`, `video/quicktime`,
    `video/x-msvideo` and `audio/flac`
  - When it is signed through the service and read back
  - Then the report has a manifest, `isSignatureValid()` is true, and
    `isAiGenerated()` is true
  - *(One test per format, written out rather than looped — SPEC-021's
    implementation notes record why a runtime-assembled title is a mistake.)*

- **AC2 — the allow-lists still agree, and the enum is the source**
  - Given the widened `MediaType`
  - When it is compared with what `GET /health` reports, and with the extensions
    `InfersMediaType` accepts
  - Then all three cover exactly the same set
  - *(SPEC-021 AC2 and AC8 already assert this by deriving from
    `MediaType::cases()`, so this criterion is satisfied by those tests
    continuing to pass with four cases added — which is the point of having
    written them that way. It is restated here so the traceability is explicit
    rather than assumed.)*

- **AC3 — the oversized-body refusal covers every video type** *(error path)*
  - Given a body above `MAX_BODY_SIZE`
  - When it is offered to `/v1/sign`
  - Then the 413 names the limit and says that **video** is accepted as a
    container but bounded by it, naming the video types rather than `video/mp4`
    alone
  - *(SPEC-021 AC7 made that refusal honest for the one video type that existed.
    Two more make the current wording quietly wrong: someone sending a MOV would
    read a sentence about MP4 and reasonably conclude it does not apply to them.)*

- **AC4 — the README states the measured fate of a signed SVG**
  - Given the README
  - When someone plans to sign SVG
  - Then it states what was measured, not a general caveat: that **SVGO with its
    default preset removes the manifest entirely and silently**, leaving a file a
    verifier cannot distinguish from one that was never signed; that **any tool
    which re-serialises the XML** renames the namespace prefix and leaves a file
    c2pa-rs refuses to parse; and therefore that SVG should be signed **as a
    final deliverable, not as a build asset**
  - *(Both failure modes are worse than the usual one. A re-encoded JPEG at least
    fails loudly on verification; a bundled SVG fails by simply not being signed
    any more, and the bundler ran because SVG is a build asset, not because
    anyone decided to modify it.)*

- **AC5 — the README states the size limit in FLAC terms**
  - Given the README, which says the limit "comfortably covers images and short
    audio"
  - When someone plans to sign lossless audio
  - Then it says that FLAC is not short-audio territory: a few minutes of
    lossless stereo approaches or exceeds the 20 MB body limit, so
    `MAX_BODY_SIZE` is the setting to check before signing music
  - *(FLAC is the first supported format whose ordinary files run into the limit.
    Leaving the existing sentence unqualified would be the same kind of
    misleading-by-omission that SPEC-021 AC5 exists to prevent for video.)*

- **AC6 — an unsupported type is still refused, and the examples still hold**
  *(error path)*
  - Given `image/bmp`, `application/pdf`, `video/webm`
  - When they are offered to `MediaType::fromMimeType()` and to `/v1/sign`
  - Then the client throws `UnsupportedMediaTypeException` and the service
    answers 400, naming all thirteen supported types
  - *(`application/pdf` and `video/webm` are now the interesting cases: they are
    refused by **us**, and the reason is upstream rather than arbitrary. If
    c2pa-rs ever gains PDF writing, this criterion is what will fail first and
    send the next person to NOTES Step 27.)*

## API sketch

Illustrative only.

```php
enum MediaType: string
{
    // … the nine from SPEC-021 …
    case Svg = 'image/svg+xml';
    case Mov = 'video/quicktime';
    case Avi = 'video/x-msvideo';
    case Flac = 'audio/flac';

    private const ALIASES = [
        'audio/mp3'   => 'audio/mpeg',      // SPEC-021
        'audio/x-flac' => 'audio/flac',     // SPEC-023
        'video/avi'   => 'video/x-msvideo', // SPEC-023
    ];
}
```

```js
// service/server.js — same order as the enum, asserted equal by SPEC-021 AC2
const SUPPORTED_MIME = new Set([
  'image/png', 'image/jpeg', 'image/webp', 'image/avif', 'image/gif',
  'image/tiff', 'audio/wav', 'audio/mpeg', 'audio/flac',
  'video/mp4', 'video/quicktime', 'video/x-msvideo', 'image/svg+xml',
]);
```

## Open questions

All three settled before approval, 2026-08-07. Recorded rather than deleted,
because the reasoning is the useful part.

- ~~**Which aliases, if any?**~~ **`audio/x-flac` and `video/avi`, and no
  others.** Both are what software actually emits — `audio/x-flac` predates
  registration and is still widespread, `video/avi` sits beside the de-facto
  `video/x-msvideo`. The rest (`video/msvideo`, `image/svg`, `audio/vnd.wave`)
  stay out on the SPEC-021 principle that the cost of an alias is not the line of
  code but the claim that we accept a spelling we have never seen in the wild.

- ~~**Is AVI worth shipping at all?**~~ **Yes.** It works in both engines and is
  confirmed by c2patool, it costs nothing on top of this spec, and "we support
  the video containers c2pa-rs supports" is a simpler sentence than one carrying
  an exception. No special documentation; it is not special.

- ~~**Does SVG belong with the images?**~~ **Yes, with AC4 sharpened to the
  measurement.** The question was deliberately answered by measuring rather than
  reasoning (see Problem): SVGO silently deletes the manifest, and any XML
  re-serialisation breaks parsing. Refusing to support SVG would protect nobody —
  someone who wants a signed SVG will reach for another route, and get no warning
  at all — while it would exclude the legitimate case, an AI-generated diagram
  delivered as a file rather than compiled into a page. So: ship it, and make the
  README say exactly what was measured.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/RemainingMediaTypesTest.php` :: "signs and reads back image/svg+xml", "signs and reads back video/quicktime", "signs and reads back video/x-msvideo", "signs and reads back audio/flac" | `src/Core/Manifest/MediaType.php`, `service/server.js` `SUPPORTED_MIME` |
| AC2 | `tests/Unit/Manifest/RemainingMediaTypesTest.php` :: "declares all thirteen measured media types"; `tests/Integration/RemainingMediaTypesTest.php` :: "accepts the four added types on the running service"; `tests/Unit/Laravel/MediaTypeInferenceTest.php` :: "reaches every declared media type from some extension" | `src/Core/Manifest/MediaType.php`, `service/server.js` `/health` `media_types`, `src/Laravel/Console/InfersMediaType.php` `EXTENSIONS` |
| AC3 | `tests/Integration/RemainingMediaTypesTest.php` :: "names every video type when refusing an oversized body" | `service/server.js` `VIDEO_MIME_LIST`, body-parser error handler |
| AC4 | `tests/Unit/RemainingMediaTypeGuidanceTest.php` :: "names the tool that silently removes an SVG manifest", "says the SVG failure is silent, not an error", "gives the rule that follows from it", "covers the second SVG failure mode as well" | `README.md` § Signing SVG |
| AC5 | `tests/Unit/RemainingMediaTypeGuidanceTest.php` :: "qualifies the short-audio claim for lossless formats", "keeps the lossless caveat beside the short-audio claim" | `README.md` § Supported media types |
| AC6 | `tests/Unit/Manifest/RemainingMediaTypesTest.php` :: "still refuses the formats measured as unsupported", "names all thirteen supported types when refusing"; `tests/Integration/RemainingMediaTypesTest.php` :: "refuses the formats c2pa-rs cannot sign, naming what it accepts" | `src/Core/Manifest/MediaType.php` `fromMimeType()`, `service/server.js` `/v1/sign` |

## Implementation notes (2026-08-07)

- **The upgrade note written this morning came true within the hour.** SPEC-022
  warned that adding enum cases makes an exhaustive `match ($mediaType)` throw
  `UnhandledMatchError`. SPEC-021's own `cc21Fixture()` helper is exactly such a
  match, and it was the first thing to break. Our own test suite was the first
  consumer bitten by our own upgrade note, which is the cheapest possible way to
  learn that the note was worth writing.
- **A fourth counter-example was overtaken by scope.** `image/svg+xml` was in the
  SPEC-021 property suite's pool of "unsupported however formatted" and in the
  unit dataset. That pool has now lost members twice (gif/webp/tiff in SPEC-021,
  svg here). It is refilled with types measured as genuinely out of reach —
  `application/pdf`, `video/webm`, `image/jxl` — plus malformed input, which no
  spec can make supported. The malformed half is the part that cannot go stale.
- **`VIDEO_MIME_LIST` is derived from `SUPPORTED_MIME`**, not written out. The
  413 message was hand-written around `video/mp4` in SPEC-021 and went stale the
  moment a second video type arrived; deriving it means a fourth cannot repeat
  that.
- **SPEC-021's exhaustive-set test became a subset test.** It asserted the nine
  types in order; with thirteen that assertion is about SPEC-023, not SPEC-021.
  It now asserts its nine are all still present — the criterion it was actually
  written for — and the exhaustive list lives in this spec's test.
- **Verified beyond our own reader**: all four formats signed through
  `SigningServiceSigner` and checked with `bin/verify.sh` (c2patool 0.27.3, trust
  on) — signature valid / cert trusted / Art.50 mark PASS on each.
