# SPEC-022: A builder entry point that says what it does

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | 2026-08-07 (maintainer)                           |
| Supersedes | — (amends the API sketch of SPEC-001)             |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`ManifestBuilder` has exactly one entry point:

```php
public static function forAiGeneratedImage(MediaType $type): self
```

SPEC-021 widened `MediaType` from two image formats to nine, including
`audio/wav`, `audio/mpeg` and `video/mp4`. So the call a user now writes is:

```php
ManifestBuilder::forAiGeneratedImage(MediaType::Mp4)   // a video
ManifestBuilder::forAiGeneratedImage(MediaType::Wav)   // an audio file
```

The name is wrong in the plainest possible way: it names a media class the
argument contradicts. This is not an internal detail — it is the first line of
every example in the README, of `bin/e2e.php`, and of the Laravel job and
command. It is the most visible identifier this package exposes.

Nothing is broken by it. That is precisely why it is worth a spec rather than a
quiet rename: the cost is entirely in a public API that will be copied into user
code, and once 0.8.0 ships, "MP4 through a method called
`forAiGeneratedImage`" becomes a state we published and have to carry.

### Why now, and not later

The mismatch is created by SPEC-021, which is on `main` and unreleased. Fixing
it in the same release means the awkward pairing is never a shipped state. Doing
it afterwards means a rename against an API users already copied — the same
correction, at a higher price, for no additional benefit.

The class docblock has the same defect ("marks an **image** as AI-generated")
and is fixed by the same change.

### What this is not

Not a behaviour change. The manifest this builder emits is fixed by SPEC-001 and
by the domain rules (`docs/c2pa-primer.md` §1–2): one `c2pa.actions.v2`
assertion, first action `c2pa.created`, the full IPTC `trainedAlgorithmicMedia`
URI, a `softwareAgent`. That assertion is the Article 50 marking *and* what makes
a claim-v2 manifest well-formed, and a rename must not touch a byte of it. AC3
exists to prove that rather than assume it.

## Scope

**In scope**

- `ManifestBuilder::forAiGenerated(MediaType $type): self` — the canonical entry
  point, identical in behaviour to the existing one.
- `forAiGeneratedImage()` **kept**, delegating to it, marked as no longer
  canonical. Removing it is not in scope and not planned; see Open questions.
- The class docblock, which describes the output as an image.
- Call sites in `src/`: `Laravel\Jobs\SignAssetJob`, `Laravel\Console\SignCommand`.
- Documentation and executable examples: `README.md`, `bin/e2e.php`,
  `docs/c2pa-primer.md` where it appears.
- Migrating the test suite to the new name, with the deliberate exception of the
  back-compatibility test — that one keeps calling the old name, because that is
  what it exists to prove.

**Out of scope** (each needs its own spec before it may be built)

- Additional `digitalSourceType` values — the manipulated case (Article 50(4))
  and the authenticity case (`digitalCapture`). That is the substantive gap, it
  is bigger than this, and it deserves its own release rather than a seat in
  this one. See Open questions for why the name chosen here has to survive it.
- Any change to the emitted manifest, the assertion, or `MediaType`.
- Removing `forAiGeneratedImage()`, now or on a schedule.
- Renaming anything else in the public API. This spec covers one identifier that
  a released change made wrong; it is not a naming review.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-022')`.

- **AC1 — the two names produce the same manifest, for every media type**
  - Given each case of `MediaType`
  - When a manifest is built through `forAiGenerated()` and through
    `forAiGeneratedImage()`, with identical subsequent calls
  - Then the two `toArray()` results are equal
  - *(Derived from `MediaType::cases()`, not two hand-picked examples. A pair of
    examples would pass while a third case diverged, and the enum is now nine
    long and expected to grow.)*

- **AC2 — the old name still works** *(back-compatibility)*
  - Given code written against 0.7.0 that calls `forAiGeneratedImage()`
  - When it is run against this version
  - Then it returns a `ManifestBuilder` and produces the manifest 0.7.0 produced
  - And no error, warning or notice is raised
  - *(This is the criterion that makes "additive" a fact rather than a claim.
    The test calls the old name directly and is exempt from the migration in
    Scope — a suite that no longer exercises the alias cannot detect it
    breaking.)*

- **AC3 — the manifest is unchanged**
  - Given `forAiGenerated(MediaType::Png)` with a software agent
  - When `build()->toArray()` is inspected
  - Then it equals, key for key, the array SPEC-001 AC1 fixes: one
    `c2pa.actions.v2` assertion, first and only action `c2pa.created`, the full
    `trainedAlgorithmicMedia` URI, the `softwareAgent`, and no other key
  - *(A rename that silently changes the Article 50 marking is the only real
    risk in this spec. Whole-array equality, so an added key fails too.)*

- **AC4 — no runtime deprecation is emitted** *(error path)*
  - Given an error handler that converts `E_USER_DEPRECATED` into a failure
  - When `forAiGeneratedImage()` is called
  - Then nothing is raised
  - *(An alias we intend to keep must not shout. Applications that promote
    notices to exceptions — a common strict setup, and PHPUnit's own default for
    deprecations — would break on a purely cosmetic change. Asserted rather than
    left to convention, so that nobody adds a `trigger_error` later without
    revisiting this decision.)*

- **AC5 — the old name is marked, and no longer shown**
  - Given the public API and the documentation
  - When `forAiGeneratedImage()` is looked at in an IDE or by static analysis
  - Then its docblock marks it as superseded and names `forAiGenerated()`
  - And no example in `README.md`, `bin/e2e.php` or `docs/` uses the old name
  - *(Matched as phrases against whitespace-normalised text: the README is
    hard-wrapped, so a phrase can carry a newline — NOTES Step 21.)*

## API sketch

Illustrative only.

```php
namespace Provemark\ContentCredentials\Core\Manifest;

final class ManifestBuilder
{
    /**
     * Start a manifest marking an asset as AI-generated (SPEC-001, SPEC-022).
     */
    public static function forAiGenerated(MediaType $type): self
    {
        return new self($type);
    }

    /**
     * @deprecated since 0.8.0 — use forAiGenerated(). The name predates
     *             SPEC-021, which added audio and video media types; it is kept
     *             indefinitely and raises no runtime notice.
     */
    public static function forAiGeneratedImage(MediaType $type): self
    {
        return self::forAiGenerated($type);
    }
}
```

## Open questions

Both are for the maintainer at approval time. Neither blocks writing tests.

- ~~**Does `forAiGeneratedImage()` ever get removed?**~~ **Settled at approval,
  2026-08-07: no. The alias stays, indefinitely.** A three-line alias costs
  nothing to keep; removing it in some future 1.0 would break working code for
  cosmetics. Consequence for the implementation: `@deprecated` here means "no
  longer the canonical name", *not* "will be deleted", so the sentence after the
  tag must carry that meaning rather than let a reader infer a removal that is
  not coming. AC4 stands as written — an alias with no end date must not warn.

- **Does the name survive the next spec?** The `digitalSourceType` work will add
  the manipulated case (Article 50(4)) and probably the authenticity case. If
  those arrive as `forAiManipulated()` and `forCaptured()`, then
  `forAiGenerated()` is one of a coherent family and this rename is final. If
  they instead arrive as a general
  `ManifestBuilder::for(DigitalSourceType $source, MediaType $type)`, then
  `forAiGenerated()` becomes a convenience wrapper on day one — still correct,
  but it would have been worth knowing. Deciding the shape of the family **now**
  is what prevents a second rename; that decision belongs here, even though the
  siblings are out of scope.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/Manifest/BuilderEntryPointTest.php` :: "produces the same manifest under either name, for every media type" | `src/Core/Manifest/ManifestBuilder.php` `forAiGenerated()`, `forAiGeneratedImage()` |
| AC2 | `tests/Unit/Manifest/BuilderEntryPointTest.php` :: "still accepts code written against the previous name", "returns a builder from the old name, so the fluent chain is unbroken" | `src/Core/Manifest/ManifestBuilder.php` `forAiGeneratedImage()` |
| AC3 | `tests/Unit/Manifest/BuilderEntryPointTest.php` :: "emits exactly the manifest SPEC-001 fixes" | `src/Core/Manifest/ManifestBuilder.php` `build()` |
| AC4 | `tests/Unit/Manifest/BuilderEntryPointTest.php` :: "raises no runtime deprecation from the old name", "sees the handler fire when something does raise, so the check is real" | `src/Core/Manifest/ManifestBuilder.php` `forAiGeneratedImage()` |
| AC5 | `tests/Unit/Manifest/BuilderEntryPointTest.php` :: "marks the old name as superseded and names its replacement", "shows only the canonical name in the documentation and examples", "uses the canonical name where the package calls itself" | `src/Core/Manifest/ManifestBuilder.php` docblock, `README.md`, `bin/e2e.php`, `src/Laravel/Jobs/SignAssetJob.php`, `src/Laravel/Console/SignCommand.php` |

## Implementation notes (2026-08-07)

- **AC4 needed a control case.** A test that installs an error handler and then
  asserts nothing was raised passes just as happily when the handler was never
  installed — the seventh instance of the shape this repository keeps
  documenting. So a second test triggers `E_USER_DEPRECATED` deliberately
  through the same mechanism and asserts it *is* seen. Without it, AC4 is a
  test that cannot fail.
- **The bulk rename hit the alias itself.** A `perl -0pi -e` sweep across
  `src/`, `tests/`, `bin/` and the docs renamed the declaration of
  `forAiGeneratedImage()` too, producing a method that called itself under a
  duplicate name. Caught immediately by the IDE and by a fatal, but worth
  recording: a rename whose whole point is that *one* occurrence must survive
  cannot be done with a blanket substitution.
- **AC5 asserts the new name is present, not only that the old one is absent.**
  "No `forAiGeneratedImage(` in the README" is satisfied by a README that stopped
  mentioning the builder at all. Both directions, in one test.
- **The old name survives in `specs/`** — SPEC-001, SPEC-011 and SPEC-021 quote
  it, and those are `implemented` and frozen. That is correct: a spec records
  what was true when it was approved. Only this spec describes the current name.
