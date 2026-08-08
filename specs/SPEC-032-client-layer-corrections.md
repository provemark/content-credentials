# SPEC-032: Four places the client layer says something it does not do

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

The last four findings of the 2026-08-08 review (NOTES Step 40), taken together
because they are one shape: each is a place where the client layer **states**
something and then does something else. None is a crash; all four are the kind of
defect that survives because nothing contradicts it out loud.

SPEC-025 bundled six client findings on the same reasoning — "the service has
been hardened six times and the client once" — and this is the remainder of that
list.

### 1. The CLI describes a smaller package than the library

`SignCommand`'s signature says `{input : Path to the source image
(.png/.jpg/.jpeg)}` and its description says "Sign an **image** as
AI-generated". `InfersMediaType` accepts fifteen extensions, including `.mp4`,
`.wav` and `.mov`. So `artisan list` prints a supported-format list that has been
wrong since SPEC-021.

NOTES Step 37 counted four hand-written enumerations of "what counts as
supported" that had gone stale and drew the general lesson; this is the fifth,
and it is the one a user reads first. `EXTENSIONS` is in the same trait, so the
help text can be derived exactly as `MediaType::fromMimeType()` and the service's
`SUPPORTED_MIME_LIST` already are.

### 2. Two HTTP clients where the comment implies one

`ContentCredentialsServiceProvider::register()` calls `resolveClient($app)` from
the `SignerInterface` closure and again from the `SigningServiceReader` closure.
With no application-bound `ClientInterface` — the default — that is two
`new Client(...)`, two connection pools against the same host, two sets of
keep-alive connections.

Nothing is broken by it, and the provider's own comment ("An application-bound
client owns its own timeout … use it unchanged") reads as though there is one
client under discussion. A sign-then-verify round-trip, which SPEC-024 records as
the common pattern, opens connections from both.

### 3. The extension reader configures trust and never confirms it took

`ExtC2paReader::settings()` calls `$settings->withTrustAnchors($pem)` and never
asks whether it worked. This project has three separate records of trust
configuration that silently verified nothing — NOTES Steps 11, 14 and 21 — and
SPEC-014 AC5 made the service fail closed at startup for exactly that reason.
This is the one trust surface left where "configured" and "effective" are not
distinguished.

**Measured against ext-c2pa v0.1.0, 2026-08-08**, because the size of the gap
decides the criterion:

| Probe | Result |
|---|---|
| `hasTrustAnchors()` before / after `withTrustAnchors()` | `false` → `true` |
| `withTrustAnchors()` return type | `null` — a mutator, so discarding it is correct |
| `withTrustAnchors('not a pem')` | **accepted**, `hasTrustAnchors()` then `true` |
| reading with garbage anchors | **throws** `ReadFailedException: … COSE error parsing certificate` |
| reading with valid anchors | `Trusted`, no status codes |
| reading with no anchors | `Valid` + `signingCredential.untrusted` |

So the dangerous case is **narrower than feared and real**. Garbage material
fails loudly at read time, which is the opposite of the c2pa-node behaviour Step
11 recorded, and this spec does not claim to improve on it. What is unguarded is
the call **not taking effect at all** — a rename, a signature change, or the
setter becoming immutable-fluent in a v0.2.0 — after which the reader would
report `isTrusted() === false` for every asset while the operator believes trust
is configured. `hasTrustAnchors()` closes exactly that, and nothing more.

Note the direction of the failure: it fails *safe* (untrusted when it should be
trusted), which is why it has no urgency. It is still a lie about configuration,
and this package's position since SPEC-013 is that trust must be positively
established rather than assumed.

### 4. The job retries what cannot succeed

`SignAssetJob` sets `$tries = 3` with `backoff() = [10, 60, 300]`. Several
failures it can meet are deterministic: `AssetTooLargeException`,
`MediaTypeMismatchException`, `MissingParentAssetException`,
`UnexpectedParentAssetException`, and any 400 from the service (SPEC-011,
SPEC-029). Retrying those sleeps up to six minutes to fail identically, three
times, per asset — and a batch of oversized assets multiplies it.

Only transport failures, 429 and 5xx are worth a retry. The 429 is the sharper
point: the service answers it with `Retry-After`, saying exactly how long to
wait, and nothing reads it. That is out of scope here (it needs a decision about
how a job defers), but the bounded-retry half is not.

## Scope

**In scope**

- Deriving `SignCommand`'s and `ReadCommand`'s help text from
  `InfersMediaType::EXTENSIONS` rather than restating it, and correcting
  "image" to "asset" where the command handles thirteen media types.
- Resolving the HTTP client once in the provider, so signer and reader share one.
- `ExtC2paReader` asserting, after `withTrustAnchors()`, that the extension
  reports the anchors as set — failing closed if not.
- `SignAssetJob` failing immediately, without retry, for the deterministic
  exception types; and continuing to retry everything else.

**Out of scope** (each needs its own spec before it may be built)

- Honouring `Retry-After` on a 429. It needs a decision about whether a job
  releases itself back with a delay or fails, and that is a queue-semantics
  question rather than a defect.
- Validating trust-anchor *material* in `ExtC2paReader`. Measured: garbage throws
  at read time, so there is no silent case to close, and pre-parsing PEM in PHP
  to improve an error message is not worth the surface.
- Adding CLI routes for `forSynthetic()`, `forAlgorithmic()` and
  `forAiManipulated()`. The CLI genuinely offers less than the library, and that
  is a feature gap rather than a false statement — the help text will no longer
  claim otherwise.
- Any change to `service/`, or to the c2pa behaviour of either reader.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-032')`.

- **AC1 — the CLI help names every extension it accepts**
  - Given the artisan sign and read commands
  - When their definitions are inspected
  - Then every extension in `InfersMediaType::EXTENSIONS` appears in the help
    text, and the text is derived from that constant rather than restating it
  - And neither command describes its input as an "image"
  - *(Derived from the constant is the part that matters: a test comparing the
    text to a second hand-written list would go stale in the same way.)*

- **AC2 — one HTTP client, shared**
  - Given a container with no application-bound `ClientInterface`
  - When the signer and the reader are both resolved
  - Then they hold the **same** client instance
  - And given an application that binds its own `ClientInterface`
  - Then both hold that one, unchanged — SPEC-008 AC3 is untouched

- **AC3 — the extension reader confirms its anchors were applied**
  - Given trust anchors passed to `ExtC2paReader`
  - When it builds its settings
  - Then it asserts the extension reports them as set
  - *(Measured: `hasTrustAnchors()` answers false before and true after, so this
    is a real post-condition and not a tautology.)*

- **AC4 — a setter that stops taking effect fails closed** *(error path)*
  - Given a `Settings` object that accepts `withTrustAnchors()` and still reports
    no anchors — the shape a rename or an immutable-fluent redesign would produce
  - When the reader is asked to read
  - Then it throws rather than reading with trust silently disabled, and the
    message says the anchors were not applied
  - *(The whole point. Reading on would report `isTrusted() === false` for every
    asset while the operator believes trust is configured.)*

- **AC5 — a deterministic failure is not retried** *(error path)*
  - Given `SignAssetJob` whose signer raises `AssetTooLargeException`,
    `MediaTypeMismatchException`, `MissingParentAssetException` or
    `UnexpectedParentAssetException`
  - When the job runs
  - Then it marks itself failed, and does **not** leave the queue to retry it
  - And no destination file is written

- **AC6 — a transient failure is still retried**
  - Given the same job whose signer raises `SigningTransportException`
  - When the job runs
  - Then the exception propagates, so the queue retries with the existing backoff
  - *(The control case. Without it, AC5 passes just as happily against a job that
    never retries anything — an assertion that something did not happen needs a
    demonstration that it could have, NOTES Step 26.)*

## API sketch

Illustrative only. No public API changes: the command names, the reader
constructor and the job constructor are unchanged.

```php
// Laravel\Console\InfersMediaType
private function supportedExtensions(): string   // ".png, .jpg, … .avi"

// Laravel\ContentCredentialsServiceProvider
$container->singleton(ClientInterface::class, fn (Container $app) => /* ... */);
// signer and reader both resolve that binding

// Core\Reading\ExtC2paReader
$settings = new ExtSettings;
$settings->withTrustAnchors($this->trustAnchorsPem);
if (! $settings->hasTrustAnchors()) {
    throw new TrustAnchorsNotAppliedException(/* ... */);
}

// Laravel\Jobs\SignAssetJob — uses InteractsWithQueue
private const DETERMINISTIC = [AssetTooLargeException::class, /* ... */];
```

`ClientInterface::class` being registered by this package is the one thing to
weigh: an application that binds its own must still win. AC2 pins both
directions.

## Open questions

- ~~**Should the provider register `ClientInterface` in the container at all?**~~
  **RESOLVED (2026-08-08): no — private memoisation on the provider.** Binding a
  global interface from a library is a strong move: another package resolving
  `ClientInterface` would silently get ours, with our timeouts and no way to tell.
  Sharing a connection pool is the goal; owning a global binding is not, and the
  two are separable. So AC2 asserts that the signer and the reader hold the same
  instance, not that any binding exists.
- **Does `$this->fail()` work when the job is invoked directly?**
  `InteractsWithQueue::fail()` is a no-op when `$this->job` is null, which is how
  a unit test calls `handle()`. If AC5 is written naively the exception simply
  vanishes and the test asserts nothing. The test must supply a fake `Job`, or
  the implementation must both fail and rethrow. *Non-blocker*, but it decides
  whether AC5 is meaningful, so it is named here rather than discovered later.
- **Should `ReadCommand` be in scope for AC1?** It shares the trait and has the
  same stale phrasing. Included above; noted because SPEC-006 governs both
  commands and this touches its surface without amending it.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/Laravel/ClientLayerCorrectionsTest.php` :: "names every supported extension in the command help"; "does not call its input an image" | `src/Laravel/Console/InfersMediaType.php` `supportedExtensions()`; `SignCommand`/`ReadCommand` constructors |
| AC2 | `tests/Unit/Laravel/ClientLayerCorrectionsTest.php` :: "gives the signer and the reader the same http client"; "uses an application-bound client for both, unchanged" | `src/Laravel/ContentCredentialsServiceProvider.php` `$httpClient`, `resolveClient()`/`buildClient()` |
| AC3 | `tests/Unit/Laravel/ClientLayerCorrectionsTest.php` :: "accepts anchors the extension reports as applied" | `src/Core/Reading/TrustAnchorsGuard.php`; `ExtC2paReader::settings()` |
| AC4 | `tests/Unit/Laravel/ClientLayerCorrectionsTest.php` :: "fails closed when the extension does not report the anchors as applied"; "says what went wrong when anchors are not applied" | `src/Core/Reading/TrustAnchorsGuard.php`; `src/Core/Reading/Exception/TrustAnchorsNotAppliedException.php` |
| AC5 | `tests/Unit/Laravel/ClientLayerCorrectionsTest.php` :: "fails a deterministic error without leaving it to be retried" (4 datasets) | `src/Laravel/Jobs/SignAssetJob.php` `NOT_RETRYABLE`, `InteractsWithQueue` |
| AC6 | `tests/Unit/Laravel/ClientLayerCorrectionsTest.php` :: "lets a transient error propagate so the queue retries it" | `src/Laravel/Jobs/SignAssetJob.php` — rethrow for everything not listed |
