# SPEC-041: `audio/x-wav`, the spelling every local tool actually emits

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-09                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`MediaType::Wav` is `audio/wav`, and `MediaType::ALIASES` carries three accepted
spellings that are not the registered type — `audio/mp3`, `audio/x-flac` and
`video/avi` — each added because, in the words of the enum's own docblock, they
are what "a good deal of software emits".

`audio/x-wav` is not among them, and it is what **every** local source emits.
Measured 2026-09-09 against `tests/Fixtures/fixture.wav`:

| Source | Reports |
|---|---|
| PHP `mime_content_type()` | `audio/x-wav` |
| PHP `finfo(FILEINFO_MIME_TYPE)` | `audio/x-wav` |
| `file --mime-type` | `audio/x-wav` |
| Drupal core's `ExtensionMimeTypeGuesser` | `audio/x-wav` |

That last one is not incidental. A caller that hands this library the MIME type
its framework detected — which is the ordinary way to obtain one — cannot sign or
read a WAV at all. `fromMimeType('audio/x-wav')` throws
`UnsupportedMediaTypeException`, naming `audio/wav` among the supported types,
which reads to the caller as though they got the file wrong.

The case is stronger than for the three aliases already shipped. Those cover
spellings some software emits; this one covers the spelling PHP itself emits from
its own `finfo` database.

**Found from outside.** `provemark/content-credentials-drupal` gates uploads on
what this library accepts. Every `.wav` uploaded to a Drupal site was being
skipped silently, because an unsupported media type is an ordinary upload there
and is not logged. NOTES Step 11 in that repository carries the trail.

## Scope

**In scope**

- `audio/x-wav` accepted as an alias of `audio/wav`, normalised to the registered
  type before it reaches the enum, exactly as the existing three are.
- The alias participating in the normalisation the others already get: case
  folding, surrounding whitespace, and `;`-parameters stripped.

**Out of scope** (each needs its own spec before it may be built)

- `audio/wave` and `audio/vnd.wave`. Both are plausible and **neither was
  measured as emitted** by anything on the test machine. Adding a spelling on the
  grounds that it might exist is the opposite of how the other three arrived.
- Any change to what `MediaType::cases()` returns. This is an accepted input
  spelling, not a fourteenth media type: the value carried into a manifest and
  sent to the service stays `audio/wav`.
- Reverse mapping — reporting `audio/x-wav` back to a caller anywhere.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-041')`.

- **AC1 — `audio/x-wav` resolves to `MediaType::Wav`**
  - Given the MIME string `audio/x-wav`
  - When `MediaType::fromMimeType()` is called with it
  - Then `MediaType::Wav` is returned, identical to what `audio/wav` returns.

- **AC2 — the alias normalises like every other accepted spelling**
  - Given `AUDIO/X-WAV`, `  audio/x-wav `, or `audio/x-wav; charset=binary`
  - When `MediaType::fromMimeType()` is called with each
  - Then `MediaType::Wav` is returned for all of them, because the alias is
    applied after trimming, lowercasing and parameter stripping rather than
    before.

- **AC3 — the alias is an input spelling and never an output** *(error path)*
  - Given `MediaType::Wav`
  - When its `value` is read, or `MediaType::cases()` is enumerated
  - Then `audio/wav` appears and `audio/x-wav` does not, anywhere. An alias that
    leaked into the value would put an unregistered type into a manifest and onto
    the wire, which is a worse defect than the one this spec fixes.

- **AC4 — a near-miss is still refused** *(required: error / malformed input)*
  - Given `audio/x-wave`, `audiox-wav` or `x-wav`
  - When `MediaType::fromMimeType()` is called with each
  - Then `UnsupportedMediaTypeException` is thrown. The alias table is an exact
    map, not a prefix or fuzzy match, and widening it by accident is how a
    supported-type list stops meaning anything.

## API sketch

Illustrative only. One line, in the table that already exists.

```php
private const ALIASES = [
    'audio/mp3' => 'audio/mpeg',        // SPEC-021
    'audio/x-flac' => 'audio/flac',     // SPEC-023: predates registration
    'video/avi' => 'video/x-msvideo',   // SPEC-023: common, unregistered
    'audio/x-wav' => 'audio/wav',       // SPEC-041: what finfo and Drupal emit
];
```

No signature changes. `SUPPORTED_MIME` in `service/server.js` is derived from the
registered types and is deliberately untouched: the service receives the media
type the client resolved, which is `audio/wav` either way.

## Open questions

- Whether the service should accept the alias on its own body as well.
  **Non-blocking, and probably no:** SPEC-025 established that the client resolves
  the media type before sending, so the service never sees an alias from this
  library. Accepting one would only serve a caller talking to the service
  directly, which is a different spec.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/Manifest/WavAliasTest.php` :: "resolves audio/x-wav to the same case audio/wav resolves to" | `src/Core/Manifest/MediaType.php` `ALIASES` |
| AC2 | `tests/Unit/Manifest/WavAliasTest.php` :: "accepts audio/x-wav however it is spelled" | `src/Core/Manifest/MediaType.php` `fromMimeType()` |
| AC3 | `tests/Unit/Manifest/WavAliasTest.php` :: "keeps audio/x-wav out of the values it reports", "still refuses audio/x-wav from tryFrom, which takes values and not spellings" | `src/Core/Manifest/MediaType.php` `ALIASES`, `docs/marking.md`, `docs/c2pa-primer.md` |
| AC4 | `tests/Unit/Manifest/WavAliasTest.php` :: "refuses spellings that only look like the alias" | `src/Core/Manifest/MediaType.php` `fromMimeType()` |