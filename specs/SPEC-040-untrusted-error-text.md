# SPEC-040: untrusted error text may not reach a terminal intact

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-05                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). Only the Traceability section of an
> `approved` spec may change without a new approval.

> **Approval decision, 2026-09-05.** Approved as written. The open question about
> `ServiceError` stripping as well as capping was **left open, not resolved**: no
> criterion requires it, AC3 closes the terminal either way, and stripping at the
> producer changes what a host application records — which is arguably its
> decision. Implementing it would have been building past the spec.

## Problem

SPEC-006 AC8 established that a value carried by an untrusted manifest must not
be able to rewrite the terminal of the operator inspecting it, and neutralised
the two values `content-credentials:read` prints. **The same class of value
reaches the same terminal through the exception path, where nothing neutralises
it** — and there it arrives from an asset, which is the stronger of the two
threat models.

Measured 2026-09-05 against `ericmann/ext-c2pa` 0.1.0 (c2pa-rs 0.89.0) and
`symfony/console` as this package declares it.

**c2pa-rs echoes four raw asset bytes.** The RIFF handler reports a signature
mismatch by quoting what it found:

```
error parsing RIFF: invalid file signature: invalid header: expected "RIFF", got "ZZZZ"
```

**Four bytes is enough for a complete CSI sequence.** `ESC [ <param> <final>` is
four bytes when the parameter is one character, and the useful ones fit:

| sequence | bytes | effect | lands in the message | survives `OutputFormatter::escape()` |
|---|---|---|---|---|
| `ESC[2J` | 4 | clear the screen | yes | yes |
| `ESC[7m` | 4 | reverse video | yes | yes |
| `ESC[1m` | 4 | bold | yes | yes |

`escape()` is one `preg_replace` over `<`, `>` and a trailing backslash — the
finding SPEC-006's amendment already recorded — so it stops none of them.

**And `ReadCommand` does not catch the read at all.** Line 68 calls
`$reader->read(...)` outside every `try`; the only `catch` covers
`UnsupportedMediaTypeException` from the type inference above it. So a
`ReadFailedException` leaves `handle()` and is rendered by the console. Measured
end to end, with the escape made visible:

```
In ExtC2paReader.php line 98:

  Could not read the asset: c2pa read/validate failed: error parsing RIFF: in
  valid file signature: invalid header: expected "RIFF", got "<ESC>[2J"
```

Two harms, not one. The control sequence reaches the terminal through a route
AC8's filter does not sit on — and an operator inspecting a suspect file gets an
exception dump instead of a verdict, which is the whole purpose of the command.

**One decision, three sinks, three different answers.** This is why it is one
spec and not three fixes:

| where untrusted text is handled | bounded | control characters | threat |
|---|---|---|---|
| `ReadCommand::fromManifest()` — manifest values | n/a | **stripped** (SPEC-006 AC8) | hostile asset |
| `ServiceError::fromBody()` — service error text | yes, 256 chars | **no** | hostile service |
| `ExtC2paReader::read()` — extension error text | **no** | **no** | hostile asset |

`ServiceError` adopts the threat model in its own docblock — *"Whatever answers
on the configured URL controls this string, and it reaches an application's log
through an exception message"* — and then defends length only. Fixing these
separately is how the next sink is missed, which is the argument that kept
`ManifestStoreParser` a single decoder.

**Scope of the measured harm.** It is the **extension reader** path.
`SigningServiceReader` is unaffected by the asset vector: a failed read answers
with the service's own wording (`read failed`), carrying no asset bytes.
`SignCommand` does catch (`ContentCredentialsException`) and prints through
`escape()`, so it is exposed only to the weaker hostile-service model.

**Not claimed.** The end-to-end render was measured with `symfony/console`'s
renderer. A host Laravel application renders console exceptions through
Collision; whether that strips control characters was not measured, and this
spec does not assume either answer.

## Scope

**In scope**

- `content-credentials:read` catching a failed read and reporting it as a
  command failure rather than letting it escape to the renderer.
- Neutralising control characters in every externally-sourced string both
  commands print, through the **one** implementation SPEC-006 AC8 introduced.
- Bounding the length of the message `ExtC2paReader` wraps, as `ServiceError`
  already bounds the service's.

**Out of scope** (each needs its own spec before it may be built)

- `ManifestStoreParser` and the accessors. SPEC-033 AC4 requires accessors to
  return manifest values byte-for-byte and SPEC-006 AC8 restates why; this spec
  touches exception messages, which are not accessor values, and changes neither.
- The signing service. Its `cap()` does not strip either, but every string it
  records goes through `JSON.stringify()`, which escapes control characters into
  a `\uXXXX` sequence — measured 2026-09-05: a `creator_name` carrying a `CR`
  was recorded as `"ACME\rVERTROUWD"` inside one line, which is also what makes
  that audit stream injection-proof.
- ext-c2pa's own error wording. That four bytes of the asset are quoted is
  upstream behaviour; this spec assumes it rather than trying to change it.
- Any new configuration. Neutralising is not an operator's choice.

## Behavior

Acceptance criteria as Given/When/Then, each covered by a Pest test tagged
`->group('SPEC-040')`.

- **AC1 — a crafted asset cannot move the operator's cursor**
  - Given a file named `*.webp` whose first four bytes are `ESC[2J`, read
    through `ExtC2paReader`
  - When `content-credentials:read` runs against it
  - Then no control character reaches the output stream, and the printable
    remainder of the message is still shown so the operator can see what failed
  - *This is the measured harm. Assert the presence of the neutralised text, not
    only the absence of the escape — an absence assertion passes before the code
    exists.*

- **AC2 — a failed read is a command failure, not an exception**
  - Given the same asset
  - When the command runs
  - Then it returns `FAILURE`, prints a message naming the file that could not
    be read, and no exception leaves `handle()`

- **AC3 — the same for a hostile signing service**
  - Given a service that answers `/v1/sign` with an error body containing `ESC[7m`
  - When `content-credentials:sign` runs against it
  - Then the refusal is printed with no control character in it

- **AC4 — one neutraliser, not two**
  - Given the helper SPEC-006 AC8 introduced
  - When every externally-sourced value either command prints is traced
  - Then each passes through that one implementation, and no second definition
    of "neutralised" exists in the package

- **AC5 — the wrapped extension message is bounded**
  - Given an extension error message longer than the cap
  - When `ExtC2paReader` wraps it
  - Then the resulting exception message is bounded to the same limit
    `ServiceError` uses, and says it was truncated

- **AC6 — malformed message input does not throw** *(required: error path)*
  - Given an exception message that is empty, or that is not valid UTF-8
  - When it is bounded and neutralised
  - Then a stable message results and nothing throws
  - *`ServiceError::cap()` may assume valid UTF-8 because `json_decode()`
    guarantees it. The extension's message carries no such guarantee, so the
    shared implementation may not inherit that assumption.*

- **AC7 — reading through the service is unchanged**
  - Given `content-credentials.reader = service` and an unreadable asset
  - When the command runs
  - Then the behaviour is what it is today: the service's own wording, no asset
    bytes, and the same exit status

- **AC8 — accessors still return verbatim**
  - Given a manifest whose values carry control characters
  - When `digitalSourceTypes()` and `softwareAgents()` are called directly
  - Then the values come back byte-for-byte, unchanged by this spec
    (SPEC-033 AC4 continues to hold)

## API sketch

Illustrative only. The neutraliser already exists as
`ReadCommand::fromManifest()`; this makes it shared rather than private, and
adds a bound at the producer.

```php
// src/Laravel/Console/ — the sink layer, where the terminal is. Not Core:
// SPEC-006 AC8 put it here on purpose, and nothing about exception messages
// changes that argument.
final class SafeOutput
{
    /** Strip C0/C1 controls, keep \n and \t, then escape Symfony markup. */
    public static function fromOutside(string $value): string;
}

// src/Core/Reading/ExtC2paReader.php — the producer bound only.
throw new ReadFailedException('Could not read the asset: '.ServiceError::cap($e->getMessage()), previous: $e);
```

Two layers, deliberately: the **length** bound belongs where the string is
created, because it is a resource concern that also protects a host
application's log; **neutralising** belongs at the terminal, because that is
where the harm is and where AC8 already put it.

## Open questions

- **Should `ServiceError` strip as well as cap?** It would protect a host
  application's log — a raw `CR` in a line-based log can forge a line — but it
  changes what an application records, which is arguably the host's decision and
  not this package's. AC3 closes the terminal either way. **Non-blocking**;
  maintainer decides.
- Collision's rendering is unmeasured (see Problem). If it turns out to
  neutralise already, AC1 still holds for the bare-`symfony/console` case this
  package declares, and the criterion does not change. **Non-blocking.**
- `SignCommand` also prints `$e->getMessage()` for `UnsupportedMediaTypeException`
  and for file paths it was given. Those come from the operator's own command
  line rather than from outside, so they are left alone; if that reading is
  wrong, AC4 is the criterion that would have to widen. **Non-blocking.**

## Traceability

Twelve tests, all in `tests/Unit/Laravel/UntrustedErrorTextTest.php`, group
`SPEC-040`. No extension and no running service: the readers are stubbed, so
they run in every `composer check` leg rather than in one profile.

**Seen red before green.** Against `main` the group reported **11 failed, 1
passed**; the one that passes either way is AC8, which asserts that nothing was
broken rather than that something was fixed. With the implementation: **12
passed**. `composer check` green at 358 passed / 7 skipped / 18 deprecated —
the deprecation count rises by eight because eight of these tests call
`$this->error()`, which on Laravel 11 hits the `InteractsWithIO.php:435` vendor
bug already documented; the Laravel 12 and 13 legs report none.

**One stated gap.** No test covers the *call site* in `ExtC2paReader` — reaching
it needs the extension loaded, and no real message from ext-c2pa gets near the
256-character bound, so such a test would assert a length that was never at risk.
`ServiceError::bound()` itself is covered directly, including the invalid-UTF-8
branch, which was confirmed to be the branch taken (`preg_match` with `/u`
returns `false` for that input). This is a known gap, not an assumed one.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/Laravel/UntrustedErrorTextTest.php` :: "strips control characters out of a failed read before printing", "keeps the printable remainder so the operator can see what failed", group `SPEC-040` | `src/Laravel/Console/SafeOutput.php` `fromOutside()`, `src/Laravel/Console/ReadCommand.php` catch block |
| AC2 | `tests/Unit/Laravel/UntrustedErrorTextTest.php` :: "reports a failed read as a command failure instead of throwing", group `SPEC-040` | `src/Laravel/Console/ReadCommand.php` — `read()` now inside a `try`, `catch (ContentCredentialsException)` |
| AC3 | `tests/Unit/Laravel/UntrustedErrorTextTest.php` :: "strips control characters out of a signing failure before printing", group `SPEC-040` | `src/Laravel/Console/SignCommand.php` — `SafeOutput::fromOutside()` replaces `OutputFormatter::escape()` |
| AC4 | `tests/Unit/Laravel/UntrustedErrorTextTest.php` :: "neutralises identically on the read path and on the sign path", group `SPEC-040` | `src/Laravel/Console/SafeOutput.php`; `ReadCommand::fromManifest()` delegates to it |
| AC5 | `tests/Unit/Laravel/UntrustedErrorTextTest.php` :: "leaves a message that is already within the bound untouched", "truncates a message past the bound and says that it did", "still bounds a long message that is not valid UTF-8", group `SPEC-040` | `src/Core/Support/ServiceError.php` `bound()`, called from `src/Core/Reading/ExtC2paReader.php` |
| AC6 | `tests/Unit/Laravel/UntrustedErrorTextTest.php` :: "neutralises an empty message and one that is not valid UTF-8", "survives an empty failure message", group `SPEC-040` | `src/Laravel/Console/SafeOutput.php` — byte-based pattern, no `/u` |
| AC7 | `tests/Unit/Laravel/UntrustedErrorTextTest.php` :: "reports a service-side read failure in the service own wording", group `SPEC-040` | `src/Core/Reading/SigningServiceReader.php` — unchanged |
| AC8 | `tests/Unit/Laravel/UntrustedErrorTextTest.php` :: "leaves manifest accessors returning their values byte for byte", group `SPEC-040` | `src/Core/Reading/ManifestReport.php` — unchanged (SPEC-033 AC4) |
