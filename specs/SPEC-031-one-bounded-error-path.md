# SPEC-031: One bounded error path for both clients

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon — 2026-08-08                     |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`SigningServiceSigner::extractError()` and `SigningServiceReader::extractError()`
are the same method. They decode the service's JSON body, pull out `error`, and
fall back to `'unknown error'`. One of them caps the string at 256 characters and
the other does not.

SPEC-025 AC4 added the cap, with its reasoning attached: whatever answers on that
URL controls this string, it ends up in an application's log through the
exception message, and the service caps every caller-supplied string it records
for the same reason — so the client reciprocates. All of that is equally true of
a read.

### How it fell through, which is the part worth recording

SPEC-025's Scope says **"Capping the service error text copied into an
exception"** — generic, no client named. Its AC4 then says "When the client
raises `SigningFailedException`". The implementation followed the criterion, and
the criterion was narrower than the scope that authorised it.

That is the mirror of NOTES Step 38's SPEC-028 AC8, where a criterion said
"accepted **or refused**" and only the accepted half was built. There the
criterion was broad and the work was narrow; here the scope was broad and the
*criterion* was narrow. Both produce the same outcome — a spec marked
`implemented` over a gap — and only one of the two is visible to `spec-check`,
which compares criteria to tests and cannot compare a criterion to the scope
above it.

SPEC-025 is `implemented` and frozen outside its Traceability section, so this
closes the gap rather than amending it.

### What is actually exposed

`ReadFailedException`'s message is bounded only by `maxResponseBytes` — 32 MiB
since SPEC-025 AC1. A hostile or broken service can therefore put up to 32 MiB
into one log line through a read, on a path that needs no signing key and that
SPEC-019 and SPEC-020 encourage applications to use widely, including from
request handlers.

### A second defect in the same method

The cap that does exist truncates with `substr()`, which cuts by bytes. A UTF-8
error message truncated at byte 256 can end mid-codepoint, so the exception
carries an invalid byte sequence into a log — and log pipelines that assume valid
UTF-8 handle that in ways nobody enjoys debugging. Same method, same change, so
it belongs here rather than in a follow-up.

## Scope

**In scope**

- One shared, capped, multibyte-safe error extractor in `Core/Support`, used by
  both `SigningServiceSigner` and `SigningServiceReader`.
- Truncation on character boundaries, so a truncated message is always valid
  UTF-8.
- Preserving both current contracts exactly otherwise: the exception type, the
  status code in the message, and `'unknown error'` for a body that is not JSON
  or carries no string `error`.

**Out of scope** (each needs its own spec before it may be built)

- Changing the cap's value, or making it configurable. 256 characters was
  settled by SPEC-025 and nothing here reopens it.
- Any change to what the service returns, or to `maxResponseBytes`.
- The other duplication between the two clients — request construction, the
  bounded-body read, base64 handling. One shared helper for one shared defect;
  merging the two classes is a larger question with no defect behind it.
- `ExtC2paReader`, which wraps a local exception message and reaches no network.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-031')`.

- **AC1 — a read error is capped** *(error path)*
  - Given a service returning a non-2xx with a very large `error` string
  - When the client raises `ReadFailedException`
  - Then the message it carries is capped to the same limit the signer uses, and
    still names the status code
  - *(SPEC-025 AC4, for the client it was never applied to.)*

- **AC2 — a signing error is still capped, unchanged**
  - Given the same oversized `error` from a sign
  - When `SigningFailedException` is raised
  - Then the message is capped exactly as it is today
  - *(Regression guard. The point of a shared helper is that both answers become
    one answer; this pins that the one they become is the signer's.)*

- **AC3 — a short error is reported in full, by both**
  - Given a service returning a short `error` string
  - When either client raises
  - Then the message carries it verbatim, with no truncation marker

- **AC4 — a truncated message is valid UTF-8** *(error path)*
  - Given an `error` string of multi-byte characters, long enough to truncate,
    positioned so that a byte-wise cut would land mid-character
  - When either client raises
  - Then the message is valid UTF-8
  - *(`mb_check_encoding()` is the assertion. A test that merely compares lengths
    passes against `substr()`, which is the defect.)*

- **AC5 — a non-JSON or error-less body still yields the same fallback**
  - Given a body that is not JSON, or JSON carrying no string `error`
  - When either client raises
  - Then the message contains `unknown error`, exactly as both do today

- **AC6 — the two clients cannot drift again**
  - Given the shared extractor
  - When either client is asked for its error text
  - Then both route through it, with no second implementation left in either
    class
  - *(The criterion is the absence of the duplicate, because the duplicate is
    what made this defect possible. Testable by reflection or by asserting the
    two clients answer identically for an identical body — the latter is
    preferred: it constrains behaviour rather than structure.)*

## API sketch

Illustrative only. No public API changes: both exception types, both messages
and both fallbacks are as they are today.

```php
namespace Provemark\ContentCredentials\Core\Support;

/**
 * The service's own error text, capped and multibyte-safe.
 *
 * @internal not part of the public API
 */
final class ServiceError
{
    private const MAX_CHARS = 256;

    public static function fromBody(string $body): string { /* ... */ }
}
```

`preg_match('/^.{0,256}/us', ...)` rather than `substr()`. No `ext-mbstring` —
see Open questions for why, and for why the input is already valid UTF-8.

## Open questions

- ~~**Does this add `ext-mbstring` to `require`?**~~
  **RESOLVED (2026-08-08): no new dependency, and not even in `suggest`.** This
  package requires `php-http/discovery` and three PSR packages and nothing else;
  an extension in `require` is a real constraint on consumers, and a truncation
  helper is a poor reason to impose one.

  PCRE with the `/u` modifier gives character semantics and ships with every PHP
  build: `preg_match('/^.{0,256}/us', $error, $m)`. And the input is already
  known-good — the string comes out of `json_decode()`, which **rejects**
  malformed UTF-8 (`JSON_ERROR_UTF8`), so by the time there is an `error` string
  to cap it is valid UTF-8 by construction. That is what makes a character-wise
  cut sufficient rather than needing a repair pass afterwards, and it is worth a
  comment in the code: the guarantee comes from the decoder, not from the
  truncation.
- **Should the shared helper also carry the status-code formatting?** Both
  clients build `"... returned HTTP %d: %s"` with only the noun differing
  ("Signing service" / "Read service"). Folding that in would remove more
  duplication and would couple the helper to message wording two exception types
  own. *Non-blocker*, leaning no.
- **Is `'unknown error'` the right fallback for a read?** It is what both do
  today and AC5 preserves it, but a caller seeing "Read service returned HTTP
  500: unknown error" learns nothing the status code did not already say. Out of
  scope to change; noted because the shared helper is where someone would
  reasonably try.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/Support/ServiceErrorTest.php` :: "caps the service error text a read copies into an exception" | `src/Core/Support/ServiceError.php` `fromBody()`; `SigningServiceReader::read()` |
| AC2 | `tests/Unit/Support/ServiceErrorTest.php` :: "still caps the service error text a sign copies into an exception" | `src/Core/Support/ServiceError.php` `fromBody()`; `SigningServiceSigner::sign()` |
| AC3 | `tests/Unit/Support/ServiceErrorTest.php` :: "reports a short service error in full, from either client" (2 datasets) | `src/Core/Support/ServiceError.php` `cap()` |
| AC4 | `tests/Unit/Support/ServiceErrorTest.php` :: "truncates on a character boundary, from either client" (2 datasets) | `src/Core/Support/ServiceError.php` `cap()` — `preg_match('/^.{0,256}/us')` |
| AC5 | `tests/Unit/Support/ServiceErrorTest.php` :: "falls back to a generic message for a body it cannot read" (6 datasets) | `src/Core/Support/ServiceError.php` `FALLBACK` |
| AC6 | `tests/Unit/Support/ServiceErrorTest.php` :: "gives both clients the same answer for the same body" (4 datasets) | `src/Core/Support/ServiceError.php` — the only implementation; both clients call it |
