# SPEC-021: The media types the engine already supports

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | 2026-08-07 (maintainer)                           |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

This package signs and reads **PNG and JPEG**. Not because c2pa-rs is limited to
them, but because two hand-written allow-lists say so:

```php
// src/Core/Manifest/MediaType.php — "Supported asset formats for v1"
case Png = 'image/png';
case Jpeg = 'image/jpeg';
```

```js
// service/server.js:179
const SUPPORTED_MIME = new Set(['image/png', 'image/jpeg']);
```

That was the right call for v1: SPEC-001 scoped to what the spike had proven, and
Article 50's deadline was the thing to hit. It has since become a limitation
nobody re-examined.

### Measured 2026-08-06 — everything below already works

Signed with `@contentauth/c2pa-node` 0.8.1 inside the running service, bypassing
`SUPPORTED_MIME`, then read back and inspected. Every format signed, read back
`validation_state: Valid`, and carried the Article 50 marking intact:

| Media type | Sign | Read back | Art. 50 marking |
|---|---|---|---|
| `image/png`, `image/jpeg` | ok | `Valid` | present |
| `image/webp` | ok | `Valid` | present |
| `image/avif` | ok | `Valid` | present |
| `image/gif` | ok | `Valid` | present |
| `image/tiff` | ok | `Valid` | present |
| `audio/wav` | ok | `Valid` | present |
| `audio/mpeg` | ok | `Valid` | present |
| `video/mp4` | ok | `Valid` | present |

`ExtC2paReader` was then pointed at the signed WEBP, WAV and MP4 from PHP: all
three read back with a manifest and `Valid`. So both readers already handle them.

### Why it matters beyond convenience

- **WEBP and AVIF are what the modern web actually serves**, and what several
  generators emit. A Laravel application that optimises images produces WEBP and
  then cannot sign it — with an error that reads like a defect in this library.
- **Article 50(2) covers audio, image, video and text**, not images alone. Text
  is not C2PA's domain (there is no container to carry a manifest), but audio and
  video are, and today this package addresses neither.

### What is NOT solved by this spec

Size. A 64×64, one-second MP4 signs fine at 16 KB. A real video does not: the
body limit is 20 MB (SPEC-017) and a signing request costs about **7× the asset**
in memory. The barrier to video is the **transport**, not the format — base64 in
one HTTP body — and removing it is a separate architectural project.

The measurement is honest about its own limits: these are small, synthetic files.
For the image and audio formats that is representative. For video it is not, and
this spec must not be read as "video is supported".

## Scope

**In scope**

- Adding `image/webp`, `image/avif`, `image/gif`, `image/tiff`, `audio/wav` and
  `audio/mpeg` to `MediaType` and to the service's `SUPPORTED_MIME`.
- The **third** hand-written list, found while scoping this: `InfersMediaType`
  (SPEC-006 D4) maps a file *extension* to a `MediaType` for the artisan
  commands. Widening the enum without it ships an enum that accepts `image/webp`
  and a command that refuses `photo.webp` — see AC8.
- `video/mp4`, accepted with its size limitation documented rather than hidden.
- A test per format that **signs and reads back**, asserting the Article 50
  marking survives. Widening an enum without that proves nothing.
- README, config comment and CHANGELOG, including what the size limit means in
  practice per format.

**Out of scope** (each needs its own spec before it may be built)

- Streaming or path-based signing — the transport change that would make real
  video possible. It is the reason video stays qualified here.
- Raising `MAX_BODY_SIZE` or the concurrency cap (SPEC-015, SPEC-017). Those
  numbers were measured for images; changing them needs its own measurement.
- Formats c2pa-rs supports but this spec did not measure: SVG, PDF, DNG, AVI,
  MOV, WEBM. Not excluded on principle — simply unmeasured, and this project does
  not ship what it has not seen work.
- Any change to the manifest itself. `ManifestBuilder::forAiGeneratedImage()`
  keeps producing the same assertion; only the asset container changes.
- `digitalSourceType` values other than `trainedAlgorithmicMedia` — the
  authenticity case (a captured photo) is a separate spec.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-021')`.

- **AC1 — every declared format signs and reads back**
  - Given an asset in each supported media type
  - When it is signed through the service and read back
  - Then the report has a manifest, `isSignatureValid()` is true, and
    `isAiGenerated()` is true — the marking survived the container
  - *(Per format, individually. One parameterised test that stops at the first
    failure would hide which format broke.)*

- **AC2 — the two allow-lists agree**
  - Given the set of media types `MediaType` accepts
  - When it is compared with the service's `SUPPORTED_MIME`
  - Then they are identical
  - *(Two hand-maintained lists in different languages are exactly the thing that
    drifts. The client would reject what the service supports, or send what it
    refuses — and the second only shows up in production.)*

- **AC3 — an unsupported type is refused, at both ends** *(error path)*
  - Given a media type outside the set — `image/bmp`, `application/pdf`, `text/plain`
  - When it is offered to `MediaType::fromMime()` and to `/v1/sign`
  - Then the client throws `UnsupportedMediaTypeException` and the service
    answers 400, naming what it does support
  - And nothing is signed

- **AC4 — mismatched bytes and declared type do not silently succeed**
  *(error path)*
  - Given bytes of one format declared as another supported format — a WAV sent
    as `image/webp`
  - When it is signed
  - Then the outcome is deterministic and documented: either a refusal, or a
    signature over what the engine detected
  - *(Measured 2026-08-06: c2pa-rs recognises the format from the bytes and
    treats the declared type as advisory (NOTES Step 22). This criterion pins
    what that means once more formats are in play, rather than leaving callers to
    discover it.)*

- **AC5 — the size limit is documented per format, not just as a number**
  - Given the README
  - When someone plans to sign audio or video
  - Then it states that `MAX_BODY_SIZE` (20 MB) and the ~7× memory multiplier
    apply to every format, that this comfortably covers images and short audio,
    and that **`video/mp4` is supported as a container but bounded to small
    files** — with the transport named as the reason
  - *(Shipping `video/mp4` without this would be the most misleading thing in
    this spec.)*

- **AC7 — an oversized video is refused for the right reason** *(error path)*
  - Given an `video/mp4` asset above `MAX_BODY_SIZE`
  - When it is offered to `/v1/sign`
  - Then the 413 names the limit **and** says that video is bounded by it, rather
    than reporting a generic body-size error
  - *(This is the criterion that makes shipping `video/mp4` honest. Without it,
    the first person to try a real video learns that this library "supports MP4"
    and then that it does not, in two steps, from an error about bytes.)*

- **AC6 — the service reports what it accepts**
  - Given a running service
  - When `GET /health` is called
  - Then it lists the accepted media types, so an operator and a client can see
    what a given deployment supports without reading its source

- **AC8 — the artisan commands accept the same set, by extension**
  - Given a file path in each supported media type
  - When `mediaTypeFromPath()` resolves it
  - Then it returns the matching `MediaType`, for `.jpg`/`.jpeg`, `.tif`/`.tiff`
    and `.mp3` alike
  - And an unsupported extension still throws `UnsupportedMediaTypeException`
    naming the extensions that are supported — a list that must not go stale
    against the enum, so the test derives the expectation from the enum rather
    than restating it
  - *(Amendment made while this spec was still `draft`, 2026-08-07. The
    extension map is a third list saying what is supported; it was not in the
    Problem section because it was not found until implementation was scoped.)*

## API sketch

Illustrative only.

```php
enum MediaType: string
{
    case Png = 'image/png';
    case Jpeg = 'image/jpeg';
    case Webp = 'image/webp';
    case Avif = 'image/avif';
    case Gif = 'image/gif';
    case Tiff = 'image/tiff';
    case Wav = 'audio/wav';
    case Mp3 = 'audio/mpeg';
    case Mp4 = 'video/mp4';   // container supported; size-bounded, see README
}
```

```js
// service/server.js — kept in the same order as the enum, and asserted equal by AC2
const SUPPORTED_MIME = new Set([
  'image/png', 'image/jpeg', 'image/webp', 'image/avif', 'image/gif',
  'image/tiff', 'audio/wav', 'audio/mpeg', 'video/mp4',
]);
```

## Open questions

All three settled before approval. Recorded rather than deleted, because the
reasoning is the useful part.

- **Should `video/mp4` ship at all?** **Yes.** It works, and refusing a format
  that works is its own kind of wrong. The risk it carries — someone tries a
  200 MB file and meets a 413 about body size rather than about video — is
  answered by AC5 and by **AC7 below**, which requires the refusal to say what is
  actually wrong.
- **`audio/mpeg` versus `audio/mp3`.** Accept both strings, mapped to one case.
  `MediaType::fromMime()` already normalises input, and `audio/mp3` is what a
  good deal of software emits despite `audio/mpeg` being the registered type.
  Rejecting it would be pedantry with a support cost.
- **How does AC2 compare a PHP enum with a JavaScript `Set`?** Through
  **`/health`** (AC6), against a running service — not by parsing `server.js` for
  a literal. It tests the deployment rather than the source text, which is the
  thing that can actually be wrong. Consequence: AC2 is an integration test and
  does not run in `composer check`. Accepted; the drift it guards against is a
  deployment property, not a compile-time one.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/MediaTypesTest.php` :: "signs and reads back image/png" — plus one sibling per case, generated from `MediaType::cases()` (`SPEC-021`, `integration`) | `src/Core/Manifest/MediaType.php`, `service/server.js` `SUPPORTED_MIME` |
| AC2 | `tests/Integration/MediaTypesTest.php` :: "accepts on the service exactly what the client enum declares" | `service/server.js` `/health` `media_types` vs `src/Core/Manifest/MediaType.php` |
| AC3 | `tests/Unit/Manifest/MediaTypeSetTest.php` :: "refuses a media type outside the set", "names every supported type in the refusal"; `tests/Integration/MediaTypesTest.php` :: "refuses an unsupported media type and names what it supports" | `src/Core/Manifest/MediaType.php` `fromMimeType()`, `service/server.js` `/v1/sign`, `/v1/read` |
| AC4 | `tests/Integration/MediaTypesTest.php` :: "signs what the engine detects when the declared type disagrees" | `service/server.js` `/v1/sign` (passes `mime_type` to c2pa-rs, which decides from the bytes) |
| AC5 | `tests/Unit/MediaTypeGuidanceTest.php` :: "lists every supported media type", "says the size limit applies to every format", "qualifies video rather than claiming it", "names the transport as the reason video is bounded" | `README.md` § Supported media types |
| AC6 | `tests/Integration/MediaTypesTest.php` :: "reports the accepted media types on /health" | `service/server.js` `/health` |
| AC7 | `tests/Integration/MediaTypesTest.php` :: "refuses an oversized video by naming the limit and that video is bounded by it" | `service/server.js` body-parser error handler |
| AC8 | `tests/Unit/Laravel/MediaTypeInferenceTest.php` :: "infers the declared type from a file extension", "reaches every declared media type from some extension", "refuses an unsupported extension and names what is supported" | `src/Laravel/Console/InfersMediaType.php` `EXTENSIONS` |

## Implementation notes (2026-08-07)

- **Nothing about the manifest changed**, as scoped: `ManifestBuilder` emits the
  same single `c2pa.actions.v2` assertion, only the container differs. Confirmed
  outside our own reader — `bin/verify.sh` (c2patool, trust on) reports
  signature valid / cert trusted / Art.50 mark PASS on a signed WEBP, WAV and
  MP4.
- **AC7's message cannot be conditional on the media type.** The body parser
  refuses before any route runs, so the request is never decoded and there is no
  `mime_type` to branch on — the same ordering that SPEC-017 found for the
  correlation id. So the 413 names video unconditionally. That is the honest
  reading of the criterion: the person who needs the sentence is precisely the
  one whose oversized body never got parsed.
- **A third allow-list turned up during implementation** and became AC8 while
  the spec was still `draft`; see Scope.
- **Four existing tests used a now-supported type as their "unsupported"
  example** (`image/gif` in SPEC-001, SPEC-010, SPEC-012, and `.gif` in
  SPEC-006). They were retargeted at `image/bmp` / `.bmp`, which is outside the
  set: the criteria are unchanged, the example had to move. `Gen::mediaType()`
  now derives from `MediaType::cases()` rather than listing two.
- **Fixtures are committed** under `tests/Fixtures/fixture.*`, generated with
  ffmpeg at 64×64 / one second. They are tiny by construction; note that a
  signed asset is dominated by the manifest at this size (a 312-byte WEBP signs
  to 108 KB, because c2pa-node adds a claim thumbnail).
