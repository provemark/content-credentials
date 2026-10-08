# SPEC-044: The verifier reader says which media types it reads

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | —                                                 |
| Supersedes | — (extends SPEC-042 AC3)                          |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-042 AC3 makes `C2paVerifierReader` refuse `image/tiff` and
`image/svg+xml` before reading, with `ReadFailedException`, and keeps the set in
a private constant. A caller can only learn the set by reading a file and
catching the exception.

That is the wrong moment for a caller that decides *before* reading whether to
read at all. The Drupal module `drupal/content_credentials` is one: it queues a
read per uploaded file and skips types the library cannot read, deriving that
set from `MediaType` rather than restating it (its SPEC-001 AC9). It is adding
the verifier as a third reading route (its draft SPEC-013, 2026-10-08). On that
route every TIFF or SVG upload would be queued, read, refused, and logged as a
read failure — a type that is unsupported, reported as a fault. To skip them
instead, the module would have to copy the two cases into its own code, which is
exactly the restatement its AC9 was written to prevent, and which goes stale the
day a verifier release widens what it reads.

The library already owns the measurement behind the set (SPEC-042 AC3: a signed
TIFF read anyway comes back "no manifest"). It should also own the answer.

## Scope

**In scope**

- A public static `C2paVerifierReader::supports(MediaType $type): bool`, true
  for every case the reader would read and false for the refused set.
- `read()` refusing through the same answer, so the two cannot disagree.
- `docs/readers.md` and the CHANGELOG naming the method.

**Out of scope** (each needs its own spec before it may be built)

- A method on `ReaderInterface`. Adding one breaks every implementation outside
  this package; the other two readers read every case, so the question only
  exists for this one.
- Widening the refused set. SPEC-042 AC3: that needs a measurement and an
  amendment there.
- `supports()` on `ExtC2paReader` or `SigningServiceReader`.

## Behavior

- **AC1 — the refused set answers false, everything else true**
  - Given each `MediaType` case
  - When `C2paVerifierReader::supports($type)` is asked
  - Then it is `false` for `Tiff` and `Svg` and `true` for the other eleven.
  - Asserted per case, both ways, over `MediaType::cases()`, so the existing
    thirteen-cases tripwire (SPEC-042) still forces a measurement for a
    fourteenth.

- **AC2 — it answers without the verifier installed**
  - Given `provemark/c2pa-verifier` absent (`isAvailable()` false)
  - When `supports()` is asked
  - Then it answers as in AC1 and throws nothing — no `VerifierMissingException`.
  - A caller asks this while deciding whether the route is usable at all, which
    may be before the package is installed. Static and free of the verifier's
    classes is what makes that hold.

- **AC3 — `read()` and `supports()` cannot disagree** *(error path)*
  - Given a type for which `supports()` is false
  - When it is read
  - Then SPEC-042 AC3's `ReadFailedException` is raised, unchanged in message.
  - And for a type for which `supports()` is true, the up-front refusal does not
    fire (SPEC-042's "accepts every other media type" still holds).
  - Implemented by `read()` calling `supports()`, not by a second list.

## API sketch

```php
final class C2paVerifierReader implements ReaderInterface
{
    private const UNSUPPORTED = [MediaType::Tiff, MediaType::Svg];

    /** Whether read() reads this type at all; false means it refuses before reading. */
    public static function supports(MediaType $type): bool
    {
        return ! in_array($type, self::UNSUPPORTED, true);
    }

    public function read(Asset $asset): ManifestReport
    {
        if (! self::supports($asset->mediaType)) {
            throw new ReadFailedException(/* SPEC-042 AC3's message, unchanged */);
        }
        // ...
    }
}
```

## Open questions

- **Release.** New public API is a minor here by this project's own precedent
  (0.10.1: patch means "no new public API"), so this ships as **0.17.0**. The
  Drupal module then raises its requirement from `^0.16` to `^0.17`.
  Non-blocking; noted because a 0.16.1 would reach the module without a
  constraint change and is the tempting wrong answer.
- **Name.** `supports()` reads well at the call site
  (`C2paVerifierReader::supports($type)`). `reads()` was considered and is
  ambiguous with `read()`. Non-blocking.

## Traceability

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
