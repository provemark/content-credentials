# SPEC-025: The bounds the client keeps for itself

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | 2026-08-07 (maintainer)                           |
| Supersedes | — (amends SPEC-009's response bound)              |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

A review of the whole codebase on 2026-08-07 (NOTES Step 29) found that the
service has been hardened five times — SPEC-011, 012, 014, 015, 017, 024 — while
the client it serves has been hardened once, and that one bound is now sized
against a limit which no longer exists.

Four findings, all on the PHP side, all about the client protecting itself.

### 1. The response bound is sized to a superseded limit

`SigningServiceConfig::$maxResponseBytes` defaults to **96 MiB**, documented in
two places as "headroom over the service's 50 MB request cap". SPEC-017 lowered
that cap to 20 MB. What the service can actually return:

| | |
|---|---|
| body limit | 20 MiB |
| largest asset it will accept | ~15 MiB (base64 inflates by 4/3) |
| largest response it can produce | ~20 MiB plus JSON overhead |
| what the client currently permits | **96 MiB** |

So the guard that exists to stop a hostile or broken service exhausting PHP
memory allows about **five times** what a correct service can send. Worse, 96 MiB
is far above the `memory_limit = 128M` that a great many PHP deployments still
run: the process dies before the guard it was given ever fires, which is the one
outcome the guard exists to prevent.

### 2. The request is not bounded at all

The client bounds the response and not the request. `SignAssetJob` and
`SignCommand` read a file of any size, and `SigningServiceSigner::sign()` then
holds the raw bytes, their base64, and the encoded JSON body — roughly **3.7×**
the file — before a single byte goes over the wire. A caller signing something
too large learns the limit as an HTTP 413 *after* paying that, or does not learn
it at all because the worker died first.

The service publishes `max_body_bytes` on `/health`, so the number is knowable.

### 3. Nothing objects to sending the bearer token in clear

`base_url` defaults to `http://localhost:3000`, which is right: the service is
loopback-only by design. But nothing distinguishes that from
`http://signer.example.com:3000`, where the same code sends the API key across a
network in plain text on every request. The README warns; the code does not.

### 4. A hostile service's error text is copied into an exception unbounded

`SigningServiceSigner::extractError()` lifts the service's `error` string
verbatim into an exception message, bounded only by `maxResponseBytes`. If the
configured URL is not the service anyone thinks it is, that string reaches
application logs at whatever size and content the responder chose. The service
caps every caller-supplied string it records for exactly this reason; the client
does not reciprocate.

### And two smaller ones from the same review

- **The signed file is written non-atomically.** `SignAssetJob` and `SignCommand`
  use `file_put_contents()`, so a crash mid-write leaves a truncated file that
  looks signed. The job's own comment claims "a failure leaves no partial file",
  which is true of signing failures and not of this one.
- **`ExtC2paReader` parses untrusted input inside the web process**, and no
  document says so. ADR-0003 isolates the signing *key* in a separate service;
  the extension reader moves the parsing of hostile assets in the other
  direction, from a disposable container into the PHP worker, through a native
  extension. That is a defensible trade — it is not a documented one, and
  SPEC-020's `auto` mode makes it near-default for anyone who installs the
  extension for an unrelated reason.

## Scope

**In scope**

- `maxResponseBytes` default sized to what the service can return, and both
  stale comments corrected.
- A request bound: refuse an asset too large for the service **before** encoding
  it, with a typed exception.
- Making insecure transport visible rather than silent.
- Capping the service error text copied into an exception.
- Atomic writes in `SignAssetJob` and `SignCommand`.
- Documenting the extension reader's process-boundary trade-off.

**Out of scope** (each needs its own spec before it may be built)

- Discovering limits from `/health` at runtime. It would remove the duplicated
  number below, and it costs a network round-trip per sign plus a new failure
  mode; the duplication is cheap because of what it is for — see AC2.
- Retry, backoff or circuit-breaking on a failing service.
- TLS termination, certificate pinning, or anything about how the service is
  deployed.
- Any change to the service.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-025')`.

- **AC1 — the response bound matches what the service can send**
  - Given the default configuration
  - When `maxResponseBytes` is inspected
  - Then it is sized to the service's 20 MB body limit plus overhead, not to the
    superseded 50 MB one
  - And a response above it is still refused before being buffered, as SPEC-009
    requires

- **AC2 — an oversized asset is refused before it is encoded** *(error path)*
  - Given an asset larger than the configured request bound
  - When `sign()` is called
  - Then it throws a typed exception naming the size and the limit
  - And no HTTP request is made, and no base64 copy is built
  - *(The duplicated number is deliberate and safe: the service enforces its own
    limit regardless, so drift between the two costs a worse error message and
    never a wrong outcome. That is what makes a configured value acceptable here
    where it would not be for a security control.)*

- **AC3 — insecure transport to a non-loopback host is visible** *(error path)*
  - Given a `base_url` that is neither `https` nor a loopback host
  - When the container builds the client
  - Then the application is told: a warning by default, an exception when strict
    transport is configured
  - And loopback over `http` stays silent, because that is the documented
    deployment
  - *(A private hostname over `http` — `http://signer:3000` between containers on
    one network — must NOT be fatal by default. It is the shape our own
    docker-compose produces, and breaking it would punish the deployment the
    README recommends.)*

- **AC4 — a service error message cannot flood a log** *(error path)*
  - Given a service, or something answering in its place, returning a very large
    or hostile `error` string
  - When the client raises `SigningFailedException`
  - Then the message it carries is capped, and the exception still names the
    status code

- **AC5 — a signed file appears whole or not at all**
  - Given a destination path
  - When `SignAssetJob` or the `sign` command writes the signed bytes
  - Then the file at that path is never observed partially written
  - *(Write to a temporary file in the same directory, then rename. The same
    directory matters: a rename across filesystems is a copy, which is exactly
    the non-atomic write being replaced.)*

- **AC6 — the extension reader's trade-off is documented**
  - Given the README and the primer
  - When someone chooses between the two readers
  - Then both say that the extension parses untrusted assets inside the
    application process, that the service reader keeps that in a separate one,
    and that this is the mirror image of the key-isolation argument in ADR-0003

## API sketch

Illustrative only.

```php
final readonly class SigningServiceConfig
{
    public function __construct(
        public string $baseUrl,
        public string $apiKey,
        // 32 MiB: the ~20 MiB a maximum-size signing response carries, plus
        // room for JSON overhead. Was 96 MiB, sized against a 50 MB body limit
        // SPEC-017 replaced with 20 MB.
        public int $maxResponseBytes = 33_554_432,
        // 15 MiB: what fits in the service's 20 MB body once base64 inflates it.
        public int $maxRequestBytes = 15_728_640,
        public bool $requireSecureTransport = false,
    ) {}
}
```

```php
// New, in Core\Signing\Exception
final class AssetTooLargeException extends \InvalidArgumentException
    implements ContentCredentialsException {}
```

## Open questions

- ~~**Warn or throw on insecure transport?**~~ **Settled before approval,
  2026-08-07: warn by default, throw when `require_secure_transport` is set.** Throwing by default is
  the stricter reading of SPEC-015's "a protection that ships off is one nobody
  turns on", and it is wrong here: `http://signer:3000` between two containers on
  one private network is what this project's own `docker-compose.yml` produces,
  and it is not a leak. A default that breaks the documented deployment would be
  turned off by everyone within a day, which is worse than a warning nobody
  disables. The Laravel provider has a logger; Core stays silent because it has
  none by design.

- ~~**Should `maxRequestBytes` be discoverable instead of configured?**~~
  **Settled: no, not in this spec.** The client
  could read `max_body_bytes` from `/health` once and cache it, removing the
  duplicated number. It buys a smaller
  window for drift at the cost of a network call before the first sign, a new
  failure mode when `/health` is unreachable, and a cache whose lifetime is
  another decision. The duplication is tolerable precisely because the service
  still enforces its own limit — see AC2. Revisit if a deployment ever runs a
  non-default `MAX_BODY_SIZE` and the mismatch produces a confusing error; that
  is the signal, not the theory.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/Signing/ClientBoundsTest.php` :: "sizes the response bound to what the service can actually return" | `src/Core/Signing/SigningServiceConfig.php` `$maxResponseBytes`, `src/Laravel/ContentCredentialsServiceProvider.php` `maxResponseBytes()` |
| AC2 | `tests/Unit/Signing/ClientBoundsTest.php` :: "refuses an asset larger than the request bound without sending anything", "names both the size and the limit when refusing", "signs normally when the asset is within the bound" | `src/Core/Signing/SigningServiceSigner.php` `sign()`, `src/Core/Signing/Exception/AssetTooLargeException.php` |
| AC3 | `tests/Unit/Signing/ClientBoundsTest.php` :: "recognises which base URLs send the token in clear"; `tests/Unit/Laravel/ClientBoundsWiringTest.php` :: "warns when the service is reached over plain HTTP across a network", "stays silent for loopback over plain HTTP", "refuses to build the config when strict transport is required", "does not crash when the application has no logger" | `src/Core/Signing/SigningServiceConfig.php` `usesInsecureTransport()`, `src/Laravel/ContentCredentialsServiceProvider.php` `warnOnInsecureTransport()` |
| AC4 | `tests/Unit/Signing/ClientBoundsTest.php` :: "caps the service error text it copies into an exception", "still reports a short service error in full" | `src/Core/Signing/SigningServiceSigner.php` `extractError()` |
| AC5 | `tests/Unit/Laravel/ClientBoundsWiringTest.php` :: "leaves no temporary file behind after a successful write", "writes nothing at all when the destination directory does not exist", "replaces an existing file without an intermediate empty state" | `src/Laravel/Support/AtomicWrite.php`, `src/Laravel/Jobs/SignAssetJob.php`, `src/Laravel/Console/SignCommand.php` |
| AC6 | `tests/Unit/ReaderTradeOffGuidanceTest.php` :: "says where the extension reader parses untrusted input", "ties the trade-off back to the decision it mirrors" | `README.md` § Which reader, `docs/c2pa-primer.md` §9 |

## Implementation notes (2026-08-07)

- **AC5 was implemented before its test, and the test was checked against the
  old text rather than watched go red.** Recorded rather than hidden: for AC6
  the phrases were verified absent from `origin/main`'s README before trusting
  the green, which is the same evidence by a different route. AC5's tests assert
  observable consequences — no leftover temporary file, no destination file after
  a failure, wholesale replacement — because true atomicity rests on `rename()`
  semantics and cannot be observed in-process without a race.
- **`tempnam()` in the destination's own directory, not `sys_get_temp_dir()`.**
  A rename across filesystems degrades to a copy, which is exactly the
  non-atomic write being replaced. Also `chmod` after creation, because
  `tempnam()` creates 0600 and a signed asset is an output file, not a secret.
- **AC3 is split across the layers on purpose.** `SigningServiceConfig` states
  the fact (`usesInsecureTransport()`); the provider decides what it is worth,
  because Core has no logger by design. The Core test therefore reports
  `http://signer:3000` as insecure — it cannot know that host is private — and
  the severity difference lives one layer up.
- **The warning must survive a missing logger.** A bare container has no `log`
  binding, and a protection that crashes when it cannot warn is worse than one
  that is absent.
