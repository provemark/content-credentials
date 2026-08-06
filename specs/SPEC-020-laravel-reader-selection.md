# SPEC-020: Choosing a reader from Laravel config

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — (draft)                                         |
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

So autodetection is offered, because it is genuinely the sensible default for
most people — but it must be **stated in config, reported at runtime, and
overridable**.

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
- Changing the default from the service reader to autodetection *for existing
  installs*. See Open questions — this is the one decision with a behaviour
  consequence.
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

- **AC3 — `auto` follows availability**
  - Given `content-credentials.reader` is `auto`
  - When `ReaderInterface` is resolved
  - Then it is an `ExtC2paReader` where the extension is loaded and a
    `SigningServiceReader` where it is not
  - And resolving twice returns the same instance (the binding stays a singleton)

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

- **What should `auto` be — the default, or opt-in?** Defaulting to `auto` is
  friendlier and probably what people expect. But it means an application that
  installs `ext-c2pa` for an unrelated reason silently changes which c2pa-rs
  version decides its trust verdicts, on a `composer update` that touched
  nothing. Leaning **`service` as the default** for existing installs, with
  `auto` documented as the recommended setting — the same reasoning that made
  v0.5.0 a minor rather than a patch: no behaviour change nobody asked for.
  *Blocker for implementation; decide at approval.*
- **How does AC6 surface the answer?** A method on `ReaderFactory`, a value
  object, or an artisan command (`content-credentials:doctor`) that also reports
  the service health. Leaning the factory method plus a line in the existing
  artisan output, and leaving a doctor command to its own spec. *Non-blocker.*
- **Where do trust anchors come from in Laravel?** The service reader gets trust
  verification from the *service*'s configuration, not the application's, so this
  key only affects the extension reader. That asymmetry is real and needs
  documenting rather than hiding — the two readers are configured in different
  places for the same concept. *Non-blocker, but it must be in the README.*

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |