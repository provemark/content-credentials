# Step 61 — `audio/x-wav`, found from outside (2026-09-09)

SPEC-041 implemented: a fourth alias, one line in a table that has had three
since SPEC-023. What is worth recording is not the line but where it came from
and why the case for it is stronger than for the three before it.

## The library could not read a WAV from any caller that asks its framework

`MediaType::Wav` is `audio/wav`. Measured against `tests/Fixtures/fixture.wav`:

| Source | Reports |
|---|---|
| PHP `mime_content_type()` | `audio/x-wav` |
| PHP `finfo(FILEINFO_MIME_TYPE)` | `audio/x-wav` |
| `file --mime-type` | `audio/x-wav` |
| Drupal core's `ExtensionMimeTypeGuesser` | `audio/x-wav` |

Four out of four, including PHP's own `finfo` database. The three earlier
aliases cover spellings *some* software emits; this one covers the spelling a
caller gets by doing the ordinary thing — asking the platform what the file is.

The failure was not a silent one here. `fromMimeType('audio/x-wav')` threw
`UnsupportedMediaTypeException` listing `audio/wav` among the supported types,
which reads to the caller as though they got the file wrong rather than the
spelling.

## ⚠️ It was silent downstream, which is how it survived

`provemark/content-credentials-drupal` gates uploads on what this library
accepts, and an unsupported media type is an ordinary upload there — a PDF is
not a fault, so nothing is logged. Every `.wav` uploaded to a Drupal site was
therefore skipped without a trace, and one of the thirteen supported types was
unreachable on that platform.

Nobody found it by reading either codebase. It surfaced when a real signed
`spec021.wav` was pushed through that module's reader for the first time. **Both
projects had thirteen media types in their tests and neither test suite could
see this**, because both were built on the same list and the list was not where
the problem was.

## What the tests pin, beyond the happy path

AC1 and AC2 are the alias resolving, and normalising like the others — after
trimming, lowercasing and parameter stripping, not before. Two more matter:

- **AC3: the alias is an input spelling and never an output.** `MediaType::Wav->value`
  stays `audio/wav`, no case reports `audio/x-wav`, and `tryFrom('audio/x-wav')`
  is still `null` — because `tryFrom()` takes backed values and knowing nothing
  about aliases is correct for it. An alias leaking into the value would put an
  unregistered type into a manifest and onto the wire, which is a worse defect
  than the one being fixed.
- **AC4: near misses stay refused.** `audio/x-wave`, `audiox-wav`, `x-wav`,
  `audio/x-wav-`. The table is an exact map, not a prefix match.

`audio/wave` and `audio/vnd.wave` are in AC4's refusal list rather than in the
alias table, and deliberately: both are plausible, neither was measured as
emitted by anything here, and the note beside the SPEC-023 aliases already says
it — *"the cost of an alias is not the line of code but the claim that we accept
a spelling we have never seen in the wild."*

## Verified

`composer check` green: PHPStan no errors, **372 passed** (7 skipped, 18
deprecated, all pre-existing), Deptrac 0 violations. `vendor/bin/pest
--group=SPEC-041` is 14 passed — and was 6 failed / 8 passed before the alias
line, which is the shape tests-first should produce: AC3 and AC4 already held,
AC1 and AC2 did not.

`php bin/spec-check.php` reports 0 errors and 0 unresolved, with the warning
count unchanged at 23 — measured before and after, so this spec adds nothing to
that debt.

`SUPPORTED_MIME` in `service/server.js` is untouched on purpose. The client
resolves the media type before sending, so the service never sees an alias from
this package; accepting one there would serve a caller talking to it directly,
which is a different spec.

---

One consequence to expect downstream: `content-credentials-drupal` carries
`assertFalse(SupportedMedia::accepts('audio/x-wav'))`, pinning the present truth
rather than the desired one. That assertion turns red on the release carrying
this, which is what it was written for.