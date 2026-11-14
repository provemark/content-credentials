# SPEC-046: The verifier's report of the same read

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon, 2026-10-28                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`C2paVerifierReader` (SPEC-042) calls `provemark/c2pa-verifier`, turns its
report into JSON, and gives the caller a `ManifestReport`. Everything the
verifier recorded beyond that shape is dropped at that point: the time the
signer was judged at, the anchor and the trust list that made it trusted,
the certificates, time-stamp tokens and OCSP responses as the file carried
them (verifier SPEC-081 to SPEC-085), and since verifier 0.10 the same as a
document to keep (`VerificationReport::evidenceToJson()`, verifier
SPEC-089). `docs/readers.md` says so since 0.19.6.

A caller that wants to keep that evidence next to the verdict it shows — an
archive of what a published image was judged on — has two ways today, and
both are wrong:

- call the verifier itself a second time. That is a second read, at a
  second moment, possibly with other settings: the evidence it keeps is not
  the evidence of the verdict it shows. A revocation answer or the clock can
  differ between the two;
- skip this reader and use the verifier directly, losing the
  `ManifestReport` every other part of the integration is written against.

The reader also always builds its own `new Verifier`. A caller cannot give
it a verifier configured differently, such as one with a revocation source
(verifier SPEC-086), so the reader route never asks one (`docs/readers.md`).

## Scope

**In scope**

- `C2paVerifierReader` accepts an optional, already configured `Verifier`;
  without one it builds `new Verifier` as now.
- A second read method, `readVerified()`, that returns the `ManifestReport`
  and the verifier's `VerificationReport` of one and the same `verify()`
  call. `read()` keeps its signature and its result and is that method's
  `ManifestReport`.
- The trust settings also as the verifier's `TrustSettings` object, so that
  a caller can pass `TrustSettings::fromTrustLists()` and the evidence names
  the lists it was judged against (open question 1).
- The verifier range moves to `^0.10` (`require-dev`, `conflict`), because
  `evidenceToArray()` and `evidenceToJson()` exist from verifier 0.10 on: an
  amendment to SPEC-042 and the ADR-0007 range addendum, in the same step.

**Out of scope** (each needs its own spec before it may be built)

- Storing, signing or sending the evidence. Keeping it, and recording which
  file it belongs to, is the caller's task (the Drupal module's archive).
- Anything for `SigningServiceReader` and `ExtC2paReader`: neither has
  such a report to give. `ReaderInterface` does not change.
- A validation time passed to the reader (verifier SPEC-073). The reader
  judges at the verifier's clock, as now.
- Anything in `ManifestReport`: its accessors and its shape stay as they are.

## Behavior

- **AC1 — one read, two reports**
  - Given a signed fixture and the reader with trust settings
  - When `readVerified($asset)` is called
  - Then it returns a `VerifiedRead` whose `report` equals, accessor by
    accessor, what `read($asset)` returns for the same asset, and whose
    `verification` has the same `validation_state` as `report`; the
    verifier's `verify()` ran once for it.

- **AC2 — the evidence of that read**
  - Given the `VerifiedRead` of AC1
  - When `verification->evidenceToJson()` is called
  - Then it is format `c2pa-verifier/evidence`, version 1, with the same
    `validation_state` and `active_manifest` as the `ManifestReport`.

- **AC3 — the verifier given is the one that reads**
  - Given a `Verifier` built with a revocation source that records each
    request, and an asset with a trusted signer whose status the verifier
    asks that source for
  - When `readVerified($asset)` is called on a reader built with it
  - Then the source was asked, and the evidence lists the response under
    `source: "fetched"`.

- **AC4 — read() is unchanged**
  - Given the existing SPEC-042 tests and the reader built without a
    `Verifier`
  - When they run
  - Then they pass unchanged, and `read()` returns what it returned before.

- **AC5 — a refusal is a refusal, never half a result** *(error path)*
  - Given an asset of a type the reader refuses (TIFF, SVG), and an asset
    the verifier cannot read (truncated)
  - When `readVerified($asset)` is called
  - Then it throws the same `ReadFailedException` as `read()` does, with
    the same message, and returns no `VerifiedRead`.

- **AC6 — the range is the verifier that has the evidence document**
  *(error path)*
  - Given `composer.json`
  - When `VerifierVersionRangeTest` runs
  - Then every verifier version below 0.10 or from 0.11 on is refused, and
    the tested pin is a 0.10 release.

- **AC7 — the lists judged against are named**
  - Given a signer list and a TSA list as PEM, passed as
    `TrustSettings::fromTrustLists($signers, $tsas)`
  - When `readVerified($asset)` is called
  - Then the evidence's `trust_lists` holds the two lists, `manifest` first,
    each with the `sha256` of the PEM as given; and `report` equals what the
    same lists passed as a settings document give.

- **AC8 — an object the reader cannot use is refused** *(error path)*
  - Given `$trustSettingsJson` that is neither null, a string nor a
    `TrustSettings`
  - When the reader is built
  - Then PHP's own `TypeError` is thrown; a blank string and null still
    mean "without trust".

- **AC9 — the report carried is the one refused** *(error path; added by
  Amendment 1, which also extends AC5 — the full text is there)*
  - Given the truncated asset and `spec046-child-overruns.png`
  - When `readVerified()` is called
  - Then it throws `VerifierRefusalException`, whose message equals the
    message the mapping gives for its `verification`; no `VerifiedRead` is
    built from a refused report.

## Amendment 1 (2026-11-14, approved by Maurice van Loon the same day: "goedgekeurd, open vragen zoals voorgesteld")

Asked by the Drupal module's SPEC-046, *A conformance harness*, open
question 1, which Maurice van Loon decided on 2026-11-14 ("goedgekeurd,
open vragen zoals voorgesteld"): a library amendment first.

**The finding.** AC5 says a refusal from `readVerified()` is the same
`ReadFailedException` as from `read()`, with no `VerifiedRead`. Where the
verifier *did* answer, its `VerificationReport` exists at that point, and
the refusal drops it. Measured on 2026-11-14 with 0.21.0 and verifier
0.11.2, on three of the verifier's own fixtures:

- `avi/lbox-differs.avi`: "Could not read the asset: LBox 13464 differs
  from the chunk length 13463 (C2PA chunk at offset 11700)";
- `jumbf/child-overruns.png`: "… box at offset 32831 ends at 33036, past
  its parent, which ends at 33026";
- `absence/created-empty.png`: "… claim: created_assertions is not a
  non-empty list".

For each, the verifier writes crJSON: `manifests: []` and a root
`validationResults` carrying the failure. This is verifier SPEC-075
amendment 4, released in 0.11.1 so that a harness reading crJSON alone
tells "does not verify" from "no Content Credentials". The module's
harness reads through this package, as an upload does. Today it gets
nothing to write for these files. Under the conformance tool's trust
setup there are 57 such fixtures (verifier step 535).

**The change.**

- `ReadFailedException` loses `final`.
- A new `final class VerifierRefusalException extends
  ReadFailedException` carries `public readonly VerificationReport
  $verification`.
- `readVerified()` throws it wherever `verify()` returned a report and the
  mapping (`VerifierOutcome::toManifestReport()`) refused it. The message
  is the same as before, word for word.
- Everything else stays a plain `ReadFailedException`:
  - a media type the reader refuses (no `verify()`);
  - no temporary stream;
  - an exception out of `verify()`;
  - and, since verifier 0.11.2, a validation time it refuses. That last
    one is in any case caught earlier, as SPEC-047's
    `InvalidValidationTimeException`.
- `read()` is `readVerified()->report`, so it throws the same subclass.
  A caller that catches `ReadFailedException` sees no difference.

**AC5 (amended) — a refusal is a refusal, never half a result; where the
verifier answered, its report goes with it** *(error path)*

- Given an asset of a type the reader refuses (TIFF, SVG); the truncated
  asset; and a copy of the verifier's `jumbf/child-overruns.png`
- When `readVerified($asset)` and `read($asset)` are called
- Then each throws a `ReadFailedException` with the same message as
  before, and returns no `VerifiedRead`:
  - for the TIFF and SVG it is exactly that class;
  - for the truncated asset and `child-overruns.png` it is a
    `VerifierRefusalException`, whose `verification` is the report of that
    one `verify()` call. Its state is `Invalid`; `toCrJson()` holds
    `manifests: []` and a root `validationResults.activeManifest.failure`
    that is not empty.

**AC9 (new) — the report carried is the one refused** *(error path)*

- Given the refused fixtures above
- When `readVerified()` is called
- Then the exception's message equals the message the mapping gives for
  `verification->toArray()`. Nothing in `ManifestReport` changes, and no
  `VerifiedRead` is built from a refused report.

**Not changed:** `ReaderInterface`, `SigningServiceReader`,
`ExtC2paReader`, `ManifestReport` and the verifier range (`^0.11`).
`VerifierRefusalException` names a verifier class, as `VerifiedRead`
does. PHP loads it only when a refusal is thrown on this route, so a host
without the verifier is unaffected (ADR-0007).

**Open questions.**

1. **Subclass, or a property on `ReadFailedException`** (blocking).
   - *Proposal:* the subclass. `ReadFailedException` is thrown by every
     reader, and a property naming a verifier class on it would put that
     class in front of the service and extension routes.
   - *Alternative:* `?VerificationReport $verification` on
     `ReadFailedException` itself, null on the other routes. *Status 2026-11-14: decided as proposed.*
2. **The name** (not blocking). *Proposal:* `VerifierRefusalException`.
   *Alternatives:* `RefusedReadException`, `ReadRefusedException`. *Status 2026-11-14: decided as proposed.*
3. **Release** (not blocking).
   - *Proposal:* 0.22.0, a minor: a public class is added and a `final` is
     removed. The module asks for `^0.22` in its harness issue, in place of
     the `^0.21` it would have taken.
   - *Alternative:* 0.21.1. It breaks nothing, but this package has not
     carried an API addition in a patch before (0.19.6, 0.20.1). *Status 2026-11-14: decided as proposed.*

## API sketch

```php
namespace Provemark\ContentCredentials\Core\Reading;

final class C2paVerifierReader implements ReaderInterface
{
    public function __construct(
        string|TrustSettings|null $trustSettingsJson = null,   // the object is new
        ?Verifier $verifier = null,      // new, last: existing calls keep working
    ) {}

    public function read(Asset $asset): ManifestReport;          // = readVerified($asset)->report

    /** @throws ReadFailedException as read() does */
    public function readVerified(Asset $asset): VerifiedRead;    // new
}

final readonly class VerifiedRead
{
    public function __construct(
        public ManifestReport $report,
        public \Provemark\C2paVerifier\Verifier\VerificationReport $verification,
    ) {}
}
```

`VerifiedRead` names a verifier class in its signature. PHP loads it only
when the class is used, so a host without the verifier is unaffected
(ADR-0007): it cannot build this reader in the first place.

## Open questions

1. **Trust settings as an object too (non-blocker).** The reader takes
   settings only as JSON (`TrustSettings::fromJson()`). The verifier names
   the trust lists in its evidence only when they come through
   `TrustSettings::fromTrustLists()` (verifier SPEC-081); a settings
   document gives `trust_lists: []`. The Drupal module builds a settings
   document from two PEM lists, so its archive would not name the lists it
   judged against. Proposal: `$trustSettingsJson` also accepts a
   `TrustSettings`, and the module passes `fromTrustLists()`; one more AC.
   *Status 2026-10-28: decided by Maurice van Loon, as proposed (AC7, AC8).*
2. **The name `readVerified()` (non-blocker).** Alternatives:
   `readWithReport()`, `inspect()`. Proposal: `readVerified()`.
   *Status 2026-10-28: decided by Maurice van Loon, as proposed.*
3. **A fixture for AC3 (blocker before tests).** It needs a signer that is
   trusted and carries an OCSP url the verifier asks the source for. To be
   measured before the tests are written; the verifier's SPEC-086
   fixtures are the candidates.
   *Status 2026-10-28: measured against verifier `main` (`7a2098e`). The
   verifier's `revocation/cs-parent.png`, judged against
   `revocation/throw-away-root.pem` (as its settings document and as
   `fromTrustLists()`), is `Trusted`, and its signer P is asked of the
   source once per read. Answered with P's good response (the one in
   `revocation/cs-good.png` whose SHA-256 starts `427b01dc`), the evidence
   lists it as `fetched`, `chosen`, `good`; its `nextUpdate` is
   2036-10-06, so the test does not age before then. The library copies
   `cs-parent.png`, the root and that response (as DER), with a README
   naming their source. The same fixture serves AC7.*
4. **Release (non-blocker).** Library 0.20.0, a minor: the verifier range
   narrows to `^0.10` and a method and a class are added. It can only be
   tagged after verifier 0.10 is on Packagist.

## Traceability

Filled 2026-10-29. AC8 was green before the implementation: PHP refused a non-string already. It guards against a later widening; AC7 holds what is new.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/Reading/VerifiedReadTest.php` :: "AC1: one read gives the ManifestReport and the verifier report of the same verify() call" | `src/Core/Reading/C2paVerifierReader.php` `readVerified()`, `read()`; `src/Core/Reading/VerifiedRead.php` |
| AC2 | `tests/Unit/Reading/VerifiedReadTest.php` :: "AC2: the verifier report holds the evidence of that read" | `src/Core/Reading/VerifiedRead.php` `$verification` |
| AC3 | `tests/Unit/Reading/VerifiedReadTest.php` :: "AC3: the verifier given is the one that reads, and its source answers" | `src/Core/Reading/C2paVerifierReader.php` `__construct()` `$verifier`; `tests/Fixtures/spec046-README.md` |
| AC4 | `tests/Unit/Reading/VerifiedReadTest.php` :: "AC4: read() without a Verifier gives what readVerified() gives as its report"; `tests/Unit/Reading/C2paVerifierReaderTest.php` (unchanged) | `src/Core/Reading/C2paVerifierReader.php` `read()` |
| AC5 | `tests/Unit/Reading/VerifiedReadTest.php` :: "AC5: a refusal is the same refusal, never half a result" | `src/Core/Reading/C2paVerifierReader.php` `readVerified()` |
| AC6 | `tests/Unit/VerifierVersionRangeTest.php` :: "tests against the verifier that verifies the box hash in a JPEG" | `composer.json` `require-dev`, `conflict`; SPEC-042 Amendment 11; `docs/adr/ADR-0007-the-verifier-as-an-optional-reader.md` |
| AC7 | `tests/Unit/Reading/VerifiedReadTest.php` :: "AC7: lists given as TrustSettings::fromTrustLists() are named in the evidence" | `src/Core/Reading/C2paVerifierReader.php` `__construct()` `string|TrustSettings|null` |
| AC8 | `tests/Unit/Reading/VerifiedReadTest.php` :: "AC8: anything but null, a string or a TrustSettings is a TypeError from PHP itself; blank and null mean without trust" | `src/Core/Reading/C2paVerifierReader.php` `__construct()`, `buildSettings()` |
| AC5 (Amendment 1) | `tests/Unit/Reading/VerifiedReadTest.php` :: "AC5 (Amendment 1): a refusal the verifier answered carries its report; one it did not stays a plain ReadFailedException" | `src/Core/Reading/C2paVerifierReader.php` `readVerified()`; `src/Core/Reading/Exception/VerifierRefusalException.php`; `src/Core/Reading/Exception/ReadFailedException.php` (no longer `final`); `tests/Fixtures/spec046-child-overruns.png` |
| AC9 (Amendment 1) | `tests/Unit/Reading/VerifiedReadTest.php` :: "AC9: the report carried is the one refused" | `src/Core/Reading/C2paVerifierReader.php` `readVerified()` |

Amendment 1 (step 83): the six new cases red first, 4 of 6; the TIFF and
SVG cases were green, as guards of what held before. AC5's original test is
unchanged and green. Mutations, each failing: the refusal rethrown without
its report (4 red), the message changed (2 red, AC9). `composer check`:
exit 0, 545 passed, 7 skipped, 18 deprecated (the same 18); spec-check
clean.
