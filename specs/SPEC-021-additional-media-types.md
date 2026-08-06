# SPEC-021: The media types the engine already supports

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — (draft)                                         |
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

- **AC6 — the service reports what it accepts**
  - Given a running service
  - When `GET /health` is called
  - Then it lists the accepted media types, so an operator and a client can see
    what a given deployment supports without reading its source

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

- **Should `video/mp4` ship at all in this spec?** It works, and refusing a
  format that works is its own kind of wrong. But shipping it invites someone to
  try a 200 MB file and meet a 413 — with a message about body size rather than
  about video. Leaning **yes, with AC5 carrying its weight**, and a specific
  error message when a video exceeds the limit. *Blocker: decide at approval.*
- **`audio/mpeg` versus `audio/mp3`.** The registered type is `audio/mpeg`;
  browsers and libraries emit both. Leaning accepting both strings and mapping
  them to one case, since `MediaType::fromMime()` already normalises input.
  *Non-blocker.*
- **How does AC2 compare a PHP enum with a JavaScript `Set`?** Simplest is
  parsing `server.js` for the literal, which is brittle. Alternative: the service
  reports its list on `/health` (AC6 provides it), and the test compares against
  a live service — making AC2 an integration test that cannot run in
  `composer check`. Leaning the `/health` comparison, because it tests the
  running deployment rather than the source text. *Non-blocker.*

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |