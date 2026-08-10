# Step 25 — SPEC-021: seven more media types, and a third allow-list (2026-08-07)

Implemented the spec drafted the previous day. Nine media types now sign and
read: PNG, JPEG, WEBP, AVIF, GIF, TIFF, WAV, MP3 and MP4. Nothing about the
manifest changed — same single `c2pa.actions.v2` assertion, different container.

### The third list

The spec's Problem section names two hand-written allow-lists (`MediaType`,
`SUPPORTED_MIME`). There are **three**: `Laravel\Console\InfersMediaType` maps a
file *extension* for the artisan commands. Widening the enum without it would
have shipped an enum accepting `image/webp` and a command refusing `photo.webp`.

Added as AC8 while the spec was still `draft`, rather than smuggled in as an
obvious consequence. All three now derive their error messages from the list
itself instead of restating it — the enum's refusal names every supported type,
the extension map names every extension, and the service interpolates
`SUPPORTED_MIME`. The old messages said "supported: image/png, image/jpeg" in
three places, which is how they go stale.

### ⚠️ Four existing tests used a now-supported type as their counter-example

`image/gif` was the stock "unsupported" MIME in SPEC-001, SPEC-010 and SPEC-012,
and `.gif` in SPEC-006. Making it supported turned four unrelated tests red —
including `/v1/read` returning **500** where 400 was asserted, because garbage
bytes declared as a *supported* type reach c2pa-rs and throw, which is
pre-existing behaviour that the old test never touched.

Retargeted at `image/bmp` / `.bmp`. The criteria did not change; the example
had to. Worth noting for next time: a test whose counter-example is drawn from
"things we happen not to support" is coupled to the scope, not to the rule.

`Gen::mediaType()` now derives from `MediaType::cases()`, so the property suite
widens with the enum rather than lagging it.

### AC7: the 413 cannot know it is a video

The criterion asks that an oversized MP4 be refused with a message about video
being size-bounded rather than a bare byte count. It cannot be conditional: the
body parser refuses **before any route**, so the request is never decoded and
there is no `mime_type` to branch on — the same ordering SPEC-017 found for the
correlation id. So the message names video unconditionally. That is the honest
reading: the person who needs the sentence is exactly the one whose body never
got parsed.

### Verified

`composer check` green (178 passed). Full integration suite **80 passed / 5
skipped** against a rebuilt service (`RATE_LIMIT_REQUESTS=1000` — the suite still
trips a default 60/minute budget, NOTES Step 17). `bin/e2e.php` sign+read OK with
the Art.50 mark and `hasTimestamp` true. `php bin/spec-check.php`: 0 errors.

And then, because our own reader agreeing with our own service proves less than
it looks like: a signed **WEBP, WAV and MP4** put through `bin/verify.sh`
(c2patool 0.27.3, trust on) — signature valid PASS / cert trusted PASS / Art.50
mark PASS / no remaining failures on all three.

### Two small findings from the fixtures

- **The manifest dominates a small asset.** A 312-byte WEBP signs to 108 KB; a
  2.8 KB MP4 to 24 KB. c2pa-node's auto-added `c2pa.thumbnail.claim` is most of
  it. Irrelevant at realistic sizes, confusing at fixture sizes.
- **`$http_response_header` is deprecated on PHP 8.4+**, and its replacement
  (`http_get_last_response_headers()`) does not exist on the 8.3 this package
  targets — so there is no version-portable way to read a status code off
  `file_get_contents`. The raw-POST helper uses Guzzle instead. Caught because
  Pest reports deprecations; it would otherwise have been noise in CI on 8.4/8.5.

### What this does NOT do

`video/mp4` is a **container**, not video support. `MAX_BODY_SIZE` (20 MB) and
the ~7× multiplier apply to every media type, and the transport is base64 in one
HTTP body. Streaming or path-based signing is the change that would matter, and
it is a separate project with no spec. The README, the CHANGELOG and the 413 all
say so; three places, because this is the claim most likely to be misread.

---

[← Step 24](step-24-correcting-step-23-what-it-unlocks.md) · [index](../NOTES.md) · [Step 26 →](step-26-spec-022-builder-entry-point-name.md)
