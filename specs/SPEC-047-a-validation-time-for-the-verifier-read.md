# SPEC-047: A validation time for the verifier read

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon, 2026-11-13                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`C2paVerifierReader` (SPEC-042, SPEC-046) always judges at the verifier's
clock. `provemark/c2pa-verifier` can judge at a given time instead
(`Verifier::verify($stream, $settings, $validationTime)`, verifier
SPEC-073): where no trusted timestamp gives the signing time, the signer's
certificate, the chain and any OCSP response are checked at that time. The
reader never passes one. SPEC-046 put this out of scope ("The reader judges
at the verifier's clock, as now").

The C2PA Conformance Program asks a validator product for a harness with
four inputs: the asset, a C2PA trust list, a TSA trust list and a
**validation time**. It answers in crJSON (verifier step 535, the
programme's v0.2 documents). The Drupal module `content_credentials` is
the candidate product. It reads through this reader, so that a
conformance answer and an upload are judged by the same code: the same
media-type check (`supports()`), the same trust settings
(`TrustSettings::fromTrustLists()`, SPEC-046 AC7), the same mapping to
`ManifestReport`. Without a time on the reader, the module's harness has
two wrong ways:

- ignore the given time and judge at the clock. Its answers then age: a
  test asset whose signer expires gives a different verdict next month;
- call the verifier directly, past this reader. The harness then does not
  test the path an upload takes.

The verifier's API takes any `DateTimeInterface`. Its command line refuses
a time whose UTC year lies outside 0001–9999 (verifier SPEC-073 amendment
2), because RFC 3339 cannot write it. The API does not refuse it, and
writes it into the crJSON as it is. Measured on verifier 0.11.1
(2026-11-13): with `@253402300800`, the first second of the year 10000,
crJSON's `validationTime` is `10000-01-01T00:00:00Z`, which is not
RFC 3339.

## Scope

**In scope**

- `readVerified()` takes an optional validation time, a
  `\DateTimeInterface`, and hands it to `Verifier::verify()`. Without one
  it judges at the clock, as now.
- The `VerifiedRead` it returns is of that one call: `report` and
  `verification` judged at the given time, and `verification` keeping it
  (its crJSON and evidence carry it, as the verifier writes them).
- A time whose UTC year lies outside 0001–9999 is refused before
  anything is read, as the verifier's command line refuses it.

**Out of scope** (each needs its own spec before it may be built)

- `read()` and `ReaderInterface`. `read()` keeps its signature and judges
  at the clock. A caller that needs a time uses `readVerified()`, which
  also gives the `VerificationReport` a harness needs for crJSON.
- A validation time for `SigningServiceReader` and `ExtC2paReader`.
  Neither engine takes one through this library.
- The verifier's rules for the time: which checks follow it, that a
  trusted timestamp wins, how a fraction is rounded (verifier SPEC-073).
  This library passes the time and does not judge it.
- The harness itself, its command and its crJSON output. Those are the
  Drupal module's.
- A time set on the reader for every read (in the constructor). See open
  question 1.

## Behavior

The fixture throughout is `spec046-cs-parent.png`, judged against
`spec046-throw-away-root.pem` (SPEC-046). Its signer is valid from
2026-10-09T14:26:58Z to 2036-10-06T14:26:58Z, and the file carries no
time-stamp. Measured with the verifier's command line on 0.11.1
(2026-11-13):

- at 2026-11-13T12:00:00Z it is `Trusted`;
- at 2037-01-01T00:00:00Z it is `Invalid`, with
  `signingCredential.expired` ("checked at the given validation time (no
  timestamp)") and `signingCredential.untrusted`;
- at 2020-01-01T00:00:00Z it is `Invalid`, with the same two codes.

Through the verifier's API with the root as both lists, also on 0.11.1:
at 2030-01-01T00:00:00Z and at 2030-01-01T05:00:00+05:00 it is `Trusted`
and crJSON's `validationTime` is `2030-01-01T00:00:00Z`; at
0001-01-01T00:00:00Z and 9999-12-31T23:59:59Z it is `Invalid`, and each
time is written as given.

- **AC1 — a time inside the validity**
  - Given the fixture, the reader built with
    `TrustSettings::fromTrustLists($root, $tsas)`: the root as the signer
    list, `certs/trust_anchors.pem` as the TSA list, as SPEC-046 AC7's test
    builds it
  - When `readVerified($asset, new DateTimeImmutable('2030-01-01T00:00:00Z'))`
    is called
  - Then `report` is trusted and `verification`'s state is `Trusted`;
    `verification->validationTime` is 2030-01-01T00:00:00Z.

- **AC2 — a time after the validity**
  - Given the same
  - When the time is 2037-01-01T00:00:00Z
  - Then `report` is not trusted, its validation status holds
    `signingCredential.expired`, and `verification`'s state is `Invalid`.
    Read at the clock, the same reader and asset are trusted, so the given
    time changed the verdict.

- **AC3 — the time reaches the documents a harness writes**
  - Given the `VerifiedRead` of AC1, and one with
    `2030-01-01T05:00:00+05:00`
  - When `verification->toCrJson()` is decoded
  - Then the active manifest's `validationResults.validationTime` is
    `2030-01-01T00:00:00Z` for both: the given time, written in UTC.

- **AC4 — without a time, nothing moves**
  - Given the existing SPEC-042 and SPEC-046 tests
  - When they run, and `readVerified($asset)` is called without a time
  - Then they pass unchanged, and `read()` and `readVerified()` return
    what they returned before. `verification->validationTime` is the
    clock, between two readings taken around the call.

- **AC5 — a time RFC 3339 cannot write is refused** *(required: error
  path)*
  - Given a time whose UTC year is 10000 (`@253402300800`), and one whose
    UTC year is 0 (`@-62135596801`)
  - When `readVerified($asset, $time)` is called
  - Then it throws `InvalidValidationTimeException` (an
    `\InvalidArgumentException` and a `ContentCredentialsException`) whose
    message names the time and the range 0001–9999. The verifier was not
    called: a `Verifier` given to the reader with a recording revocation
    source records nothing. No `VerifiedRead` is returned.
  - And the edges are accepted: `0001-01-01T00:00:00Z` and
    `9999-12-31T23:59:59Z` each give a `VerifiedRead`.

## API sketch

```php
namespace Provemark\ContentCredentials\Core\Reading;

final class C2paVerifierReader implements ReaderInterface
{
    public function read(Asset $asset): ManifestReport;   // unchanged: the clock

    /**
     * @param  \DateTimeInterface|null  $validationTime  the time to judge at where no trusted
     *                                                   timestamp gives one (verifier SPEC-073); null is the clock
     *
     * @throws InvalidValidationTimeException when its UTC year lies outside 0001–9999
     * @throws ReadFailedException as read() does
     */
    public function readVerified(Asset $asset, ?\DateTimeInterface $validationTime = null): VerifiedRead;
}

namespace Provemark\ContentCredentials\Core\Reading\Exception;

final class InvalidValidationTimeException extends \InvalidArgumentException implements ContentCredentialsException {}
```

The parameter is last and optional, so existing calls keep working. The
class is `final`, so no subclass breaks. One new public class
(`InvalidValidationTimeException`) and one new parameter.

## Open questions

1. **Per call, not per reader (non-blocker).** The time could also be set
   once, in the constructor. A harness answers one asset with one time,
   while the module builds its reader once (`ReaderFactory`) for every
   upload. Proposal: per call, on `readVerified()` only.
   *Status 2026-11-13: decided by Maurice van Loon, as proposed.*
2. **Refuse, or leave it to the verifier (non-blocker).** The verifier's
   API accepts a year outside 0001–9999 and writes it into crJSON as no
   RFC 3339 time. Refusing here keeps that out of what a harness writes.
   The verifier itself could refuse it in its API too, which is a matter
   for the verifier, not for this spec. Proposal: refuse here (AC5), and
   name it in the verifier's notes as a candidate.
   *Status 2026-11-13: decided by Maurice van Loon, as proposed.*
3. **Release (non-blocker).** One public class and one parameter are
   added; nothing breaks. In this library an addition to the API went in a
   minor (0.20.0, SPEC-046), and a patch has carried no API change
   (0.19.6, 0.20.1). The module requires `^0.20`, which admits no 0.21,
   and it must raise its floor to use the parameter whichever number this
   gets. Proposal: 0.21.0, and the module moves to `^0.21` in the issue
   that builds its harness.
   *Status 2026-11-13: decided by Maurice van Loon, as proposed.*

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/Reading/ValidationTimeTest.php` :: "AC1: a time inside the validity judges the signer trusted" | `src/Core/Reading/C2paVerifierReader.php` `readVerified()` |
| AC2 | `tests/Unit/Reading/ValidationTimeTest.php` :: "AC2: a time after the validity judges the signer expired, where the clock does not" | `src/Core/Reading/C2paVerifierReader.php` `readVerified()` |
| AC3 | `tests/Unit/Reading/ValidationTimeTest.php` :: "AC3: the time reaches the crJSON, written in UTC" | `src/Core/Reading/C2paVerifierReader.php` `readVerified()` |
| AC4 | `tests/Unit/Reading/ValidationTimeTest.php` :: "AC4: without a time, the read is judged at the clock"; `tests/Unit/Reading/VerifiedReadTest.php` and `tests/Unit/Reading/C2paVerifierReaderTest.php` unchanged | `src/Core/Reading/C2paVerifierReader.php` `readVerified()`, `read()` |
| AC5 | `tests/Unit/Reading/ValidationTimeTest.php` :: "AC5: a time RFC 3339 cannot write is refused before anything is read"; "AC5: the edges of the range are read" | `src/Core/Reading/C2paVerifierReader.php` `checkTime()`; `src/Core/Reading/Exception/InvalidValidationTimeException.php` |

The tests were run red first: 8 of 9 failed, because `readVerified()`
ignored the extra argument; AC4 was green, as a guard should be. Mutations,
each failing: the time not handed to the verifier (6 red), no check (2 red),
the upper edge at 10000 (1 red), the check after the result is mapped (2
red). One mutation survived: the check after `verify()` but before the
result is mapped. The verifier answers a truncated file with `general.error`
rather than an exception, and the library refuses it only when it maps the
result, so AC5's truncated asset cannot tell those two places apart. That
the verifier is not called at all is not observable: `Verifier` is final,
and its revocation source is not asked about a signer that is not trusted.
AC5's "records nothing" is therefore the weaker check the test names.
`composer check`: exit 0, 539 passed, 7 skipped, 18 deprecated (the same
18 as step 80).
