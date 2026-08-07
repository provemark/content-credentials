# SPEC-023: The four remaining formats that actually work

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — (draft)                                         |
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

- **AC4 — the README says what SVG signing actually means**
  - Given the README
  - When someone plans to sign SVG
  - Then it states that SVG is text, that any minifier or optimiser (SVGO and
    friends) invalidates the manifest, and that inlining the SVG into HTML
    discards it entirely
  - *(The general immutability rule already covers "post-sign mutation
    invalidates". For SVG that is not a caveat but the normal pipeline: SVG is
    routinely optimised and inlined by build tooling, so the rule bites where
    nobody is thinking about it.)*

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

For the maintainer at approval time.

- **Which aliases, if any?** SPEC-021 settled exactly one (`audio/mp3` →
  `audio/mpeg`) on the grounds that rejecting what real software emits is
  pedantry with a support cost. The same argument reaches `audio/x-flac` (the
  pre-registration spelling, still widely emitted) and `video/avi` /
  `video/msvideo` (both common, neither registered). Recommendation: add
  `audio/x-flac` and `video/avi`, skip the rest — each alias is a line in a map
  and a case in a test, and the cost is not the code but the claim that we accept
  a spelling we have not seen in the wild.
- **Is AVI worth shipping at all?** It works, and refusing a format that works is
  its own kind of wrong (SPEC-021's reasoning for `video/mp4`). But AVI is legacy
  and no generator emits it. The counter-argument is that it costs nothing on top
  of the same spec, and that "we support the video containers c2pa-rs supports"
  is a simpler sentence than one with an exception in it. Recommendation: ship
  it, and say nothing special about it.
- **Does SVG belong with the images?** It signs like one, but it is a text format
  whose normal build pipeline destroys the manifest (AC4). If the caveat cannot
  be made clear enough in the README, the honest alternative is not to ship it —
  a credential that is silently stripped by a bundler is worse than no support,
  because the failure is invisible until someone verifies.

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