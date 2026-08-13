# SPEC-020: Choosing a reader from Laravel config

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented (amended 2026-08-13 — see Amendment)  |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon — 2026-08-06                     |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-019 added a second `ReaderInterface` implementation and confined itself to
`Core`. The Laravel integration was not part of that scope, so it still binds one
reader unconditionally:

```php
// src/Laravel/ContentCredentialsServiceProvider.php
$container->singleton(ReaderInterface::class, fn (Container $app): SigningServiceReader => new SigningServiceReader(
    $this->resolveClient($app), ..., $app->make(SigningServiceConfig::class),
));
```

So a Laravel application that installs `ext-c2pa` still gets HTTP. Everything
resolved from the container — the facade, `ContentCredentialsManager`, the jobs,
the artisan commands — keeps talking to the signing service. To use the in-process
reader the developer must construct it by hand and bypass the integration, which
is the part of this package Laravel users are here for.

That is a gap this project created: v0.6.0 shipped a capability that the
framework layer cannot reach. It is not a defect in SPEC-019 — reaching it was
out of its scope — but leaving it is shipping half a feature.

### Why a config key rather than autodetection alone

Tempting to bind `ExtC2paReader` whenever `extension_loaded('c2pa')` is true and
be done. That is wrong as the *only* behaviour, for a reason this repository has
met repeatedly: it makes which engine answered a question invisible.

The two readers run different c2pa-rs versions — **0.89.0** in the extension,
**0.90.4** in the service. They agree today, and SPEC-019 AC2 exists precisely
because that is not guaranteed. If installing an unrelated extension silently
changed which engine decides `isTrusted()` for a production application, the
resulting support conversation would begin with nobody knowing that anything had
changed.

So autodetection is offered as a mode, because it is genuinely what most people
will want — but it is **chosen, not assumed**: stated in config, reported at
runtime, and overridable. The default stays the service reader, so nothing about
an existing application changes because an extension appeared on the machine.

## Scope

**In scope**

- A `reader` config key with three modes: `auto`, `service`, `extension`.
- Binding `ReaderInterface` accordingly in the service provider, with `Core`
  untouched.
- A way for an application to ask which reader it actually got, without
  reaching into the container and type-checking.
- A clear failure for `extension` when the extension is absent, rather than a
  silent fall back to HTTP.
- Config documentation, README and CHANGELOG.

**Out of scope** (each needs its own spec before it may be built)

- Any signer selection. `SignerInterface` keeps its single binding; in-process
  signing is refused for the reasons in SPEC-019, and the extension cannot
  timestamp (`tsa_url` is hardcoded `None`).
- Changing the default away from the service reader. Settled at approval: the
  default stays `service`, so this spec introduces no behaviour change for any
  existing install. Revisiting that is a separate decision.
- Any change to `Core`, to either reader, or to the service.
- Per-call reader selection (a caller wanting both can resolve them explicitly).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-020')`. The Laravel tests use the
existing testbench setup; criteria needing the extension skip where it is absent,
except AC4, which is about absence.

- **AC1 — `service` binds the HTTP reader**
  - Given `content-credentials.reader` is `service`
  - When `ReaderInterface` is resolved
  - Then it is a `SigningServiceReader`, **even when the extension is loaded**
  - *(The half that makes the setting a control rather than a hint.)*

- **AC2 — `extension` binds the in-process reader**
  - Given `content-credentials.reader` is `extension` and the extension is loaded
  - When `ReaderInterface` is resolved
  - Then it is an `ExtC2paReader`, and it is configured with the application's
    trust anchors if any are set

- **AC3 — `auto` follows availability, and is not the default**
  - Given `content-credentials.reader` is `auto`
  - When `ReaderInterface` is resolved
  - Then it is an `ExtC2paReader` where the extension is loaded and a
    `SigningServiceReader` where it is not
  - And resolving twice returns the same instance (the binding stays a singleton)
  - And with **no** `reader` set at all, the binding is the service reader even
    where the extension is loaded — installing an extension must not change an
    existing application's behaviour on its own

- **AC4 — `extension` without the extension fails loudly** *(error path)*
  - Given `content-credentials.reader` is `extension` and the extension is **not**
    loaded
  - When `ReaderInterface` is resolved
  - Then it throws, naming the extension and how to install it, and does **not**
    fall back to the service reader
  - *(An application that asked for in-process reading and silently got HTTP
    cannot tell — the same reason SPEC-019 AC5 refuses to fall back.)*

- **AC5 — an unrecognised mode is refused** *(error path)*
  - Given `content-credentials.reader` is anything other than the three modes —
    including an empty string or a typo such as `ext`
  - When `ReaderInterface` is resolved
  - Then it throws, listing the modes it accepts
  - *(Defaulting a typo to `auto` would be the silent-degradation shape again.)*

- **AC6 — the application can see which reader it got**
  - Given any configuration
  - When the application asks
  - Then it can learn the active mode and the engine behind it without resolving
    the container and comparing class names
  - *(Two c2pa-rs versions are in play. "Which engine answered?" must be
    answerable in a bug report.)*

- **AC7 — trust anchors reach the in-process reader**
  - Given trust anchors configured for the application
  - When the extension reader is bound
  - Then it verifies against them, so `isTrusted()` can be true
  - And given none, it is constructed without anchors and `isTrusted()` is false
    by design rather than by failure — matching SPEC-014's distinction

## API sketch

Illustrative only. Confined to `src/Laravel/` and `config/`.

```php
// config/content-credentials.php
'reader' => env('CONTENTAUTH_READER', 'service'),

// Trust anchors as PEM CONTENTS, or a path this package reads for you.
// (NOTES Step 11: every trust surface in this project takes contents, and a
// path silently verifies nothing or throws, depending on the layer.)
'trust_anchors' => env('CONTENTAUTH_TRUST_ANCHORS'),
```

```php
// src/Laravel/ContentCredentialsServiceProvider.php
$container->singleton(ReaderInterface::class, function (Container $app): ReaderInterface {
    return $app->make(ReaderFactory::class)->make();
});
```

```php
// src/Laravel/ReaderFactory.php — the decision in one testable place
final readonly class ReaderFactory
{
    public function mode(): string;          // the resolved mode, after `auto`
    public function make(): ReaderInterface;
}
```

## Open questions

- **What should `auto` be — the default, or opt-in?** **Settled: `service` is the
  default.** `auto` is friendlier and probably what people expect, but as a
  default it means an application that installs `ext-c2pa` for an unrelated
  reason silently changes which c2pa-rs version decides its trust verdicts, on an
  update that touched nothing of ours. Same reasoning that made v0.5.0 a minor
  rather than a patch: no behaviour change nobody asked for. `auto` is documented
  as the recommended setting, so the choice is made by a person, once, visibly.

  Consequence to accept: **installing the extension does nothing until the
  application also sets `reader`.** That will read as a bug to someone. The
  README and the mode reporting from AC6 are what stop it becoming one, so
  neither is optional garnish.
- **How does AC6 surface the answer?** **`ReaderFactory::mode()`**, plus the mode
  printed by `content-credentials:read` (`src/Laravel/Console/ReadCommand.php`),
  which is where someone is already standing when they wonder which engine
  answered. A `content-credentials:doctor` command is a reasonable idea and gets
  its own spec if it is ever wanted; it is not needed to satisfy this criterion.
- **Where do trust anchors come from in Laravel?** A `trust_anchors` config key,
  accepting PEM **contents** or a path this package reads for the caller. It
  affects the **extension reader only** — the service reader's trust verification
  is configured on the *service*, via `CONTENTAUTH_TRUST_SETTINGS`, and the
  application cannot influence it. Two readers, one concept, two configuration
  locations. Documented in the README and in the config file's comment rather
  than smoothed over: someone who sets `trust_anchors` and keeps getting
  `isTrusted() === false` from the service reader must be able to find out why.

## Amendment (2026-08-13)

**AC6 asks for two things and the API returns one.** The criterion reads "it can
learn the active mode **and the engine behind it**", with the parenthetical that
"which engine answered?" must be answerable in a bug report. `ReaderFactory`
exposes a single `mode()`, which resolves `auto` before returning:

    configured=service   mode() returns: service
    configured=auto      mode() returns: extension

So `mode()` answers the engine and destroys the mode. `content-credentials:read`
prints `reader             : extension`, and nobody reading that output can tell
whether the extension was **chosen** or **detected**.

That distinction is not incidental to this spec — it is the decision the spec was
written around. `auto` is deliberately not the default because an application
that installs the extension for an unrelated reason must not silently change
which c2pa-rs version decides its trust verdicts. A report that cannot separate
deliberate from detected removes the evidence for exactly the failure the design
guards against, and it does so in the one place built to answer the question.

**`ReaderFactory::configuredMode(): string` is new.** It returns the validated
configured value — `service`, `extension` or `auto` — and applies the same
refusal as `mode()` for anything else, so a typo cannot reach either accessor.
`mode()` is unchanged and keeps returning the resolved engine; the pair is what
AC6 asked for.

**AC8 is new** *(reading, CLI)*

- Given `content-credentials.reader` set to `auto`, with the extension available
- When `content-credentials:read` runs
- Then the output reports the resolved engine **and** that the mode was `auto`,
  distinguishably
- And given the mode set explicitly to `extension` with the same engine
  resolved, the two outputs differ — so the report can never be read as a
  configuration it did not have

**What this amendment deliberately does not change.** A review of 2026-08-13
raised a second concern: the command reports the factory's answer rather than the
identity of the `ReaderInterface` the container actually returned, so an
application that rebinds that interface would be reported wrongly. The provider
binds `ReaderInterface` from `ReaderFactory::make()`, and rebinding it is
documented nowhere — not in `docs/`, not in the README. Guarding an override
nobody advertises would mean duplicating the mode-to-engine mapping inside the
command, where it would go stale the moment a third reader exists; `docs/readers.md`
already contemplates one. Recorded here so the concern is not re-raised as new.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Unit/Laravel/ReaderSelectionTest.php` :: "binds the service reader when the mode is service"; "still binds the service reader when the extension is available" | `src/Laravel/ReaderFactory.php` `make()` |
| AC2 | `tests/Unit/Laravel/ReaderSelectionTest.php` :: "binds the in-process reader when the mode is extension" | `src/Laravel/ReaderFactory.php` `make()` |
| AC3 | `tests/Unit/Laravel/ReaderSelectionTest.php` :: "binds the in-process reader under auto when the extension is available"; "falls back to the service reader under auto when the extension is absent"; "defaults to the service reader when no mode is configured"; "binds the reader once" | `src/Laravel/ReaderFactory.php` `mode()`, provider singleton |
| AC4 | `tests/Unit/Laravel/ReaderSelectionTest.php` :: "throws when the extension mode is set and the extension is missing"; "does not quietly fall back to the service reader" | `src/Core/Reading/ExtC2paReader.php` constructor |
| AC5 | `tests/Unit/Laravel/ReaderSelectionTest.php` :: "refuses a mode it does not recognise"; "names the modes it accepts when refusing" | `src/Laravel/ReaderFactory.php` `mode()` |
| AC6 | `tests/Unit/Laravel/ReaderSelectionTest.php` :: "reports the resolved mode without inspecting class names"; "reports the mode with no reader configured"; "prints the resolved reader mode in the read command" | `src/Laravel/ReaderFactory.php` `mode()`, `src/Laravel/Console/ReadCommand.php` |
| AC7 | `tests/Unit/Laravel/ReaderSelectionTest.php` :: "passes configured trust anchors to the in-process reader"; "accepts trust anchors given as a path as well as as contents" | `src/Laravel/ReaderFactory.php` `trustAnchors()`, `config/content-credentials.php` |

### Implementation notes

- **A docblock landed on the wrong method.** Inserting `configRepository()` above
  `serviceConfig()` orphaned the latter's `@return array{...}` onto it, and
  PHPStan reported five errors in code that had not been touched. Caught by the
  analyser, not by review.
- **One existing test needed a container binding, not a contract change.**
  `ReadCommand::handle()` now takes `ReaderFactory`, and SPEC-006's harness
  builds a bare container rather than registering the provider. The binding was
  added there. The alternative — making the dependency optional so the harness
  keeps working — would have let a test shape the design, and a missing
  diagnostic line is exactly what AC6 exists to prevent.
- **`Command::run()` needs `runningUnitTests()`**, which a bare
  `Illuminate\Container\Container` does not have. SPEC-006 solved this with a
  subclass; the same shim is now in this suite's harness.
- **AC4 cannot be exercised where the extension is installed.** It runs in CI,
  which has no extension in three of four profiles, and skips locally. Same split
  as SPEC-019 AC5.
