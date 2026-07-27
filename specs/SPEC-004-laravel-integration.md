# SPEC-004: Laravel integration (provider, config, facade)

| Field      | Value                                   |
|------------|-----------------------------------------|
| Status     | implemented                             |
| Author     | Maurice van Loon (maintainer)           |
| Approved   | Maurice van Loon — 2026-07-27           |
| Supersedes | —                                       |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Core exposes `SignerInterface` (SPEC-002) and `ReaderInterface` (SPEC-003), but a
Laravel app has no way to resolve a configured signer/reader without hand-wiring
a PSR-18 client, PSR-17 factories and a `SigningServiceConfig`. `src/Laravel` is
empty; the `Laravel → Core` Deptrac rule is defined but never exercised.

This spec adds the Laravel adapter layer: a **service provider** that binds the
Core interfaces from config, a published **config file** driven by env, and a
**facade** for ergonomic access — all in `src/Laravel`, depending only on Core +
`illuminate/*` (never the reverse). It is the first code to live in the Laravel
layer and the first real test of the architecture boundary.

## Scope

**In scope**

- `Provemark\ContentCredentials\Laravel\ContentCredentialsServiceProvider`
  (extends `Illuminate\Support\ServiceProvider`):
  - merges + publishes `config/content-credentials.php`;
  - binds `SigningServiceConfig` from config (base URL + API key);
  - binds `SignerInterface` → `SigningServiceSigner` and `ReaderInterface` →
    `SigningServiceReader`, resolving a PSR-18 client + PSR-17 factories from the
    container when bound, otherwise via auto-discovery;
  - registered through Laravel **package auto-discovery** (composer `extra`).
- `config/content-credentials.php` — `service.base_url`
  (`CONTENTAUTH_SERVICE_URL`) and `service.api_key` (`CONTENTAUTH_API_KEY`),
  matching `.env.example`.
- A thin `ContentCredentialsManager` (proxies `sign()` / `read()` to the bound
  Core interfaces) and a `ContentCredentials` facade over it.
- A Laravel-layer exception
  `Provemark\ContentCredentials\Laravel\Exception\MissingConfigurationException`
  (implements `Core\Support\ContentCredentialsException`) for missing/blank
  required config.
- Dependencies: `php-http/discovery` (runtime — see ADR-0002) for
  client/factory discovery; `illuminate/container` + `illuminate/config` +
  `guzzlehttp/guzzle` in require-dev (bare provider-test harness — D4);
  `illuminate/support` stays require-dev **and** is added to composer `suggest`.

**Out of scope** (each needs its own spec)

- Queued jobs and artisan commands (CLAUDE.md lists them for the Laravel layer)
  — a later spec.
- Higher-level convenience API (e.g. a one-call `signAiImage(bytes, type,
  agent)` combining `ManifestBuilder` + signer) — Open Q2.
- Trust configuration, asset types beyond PNG/JPEG, CAWG, TSA.
- Changes to Core (the boundary is Laravel → Core only).

## Dependencies & ADR (require maintainer sign-off with this spec)

- **New runtime dependency:** `php-http/discovery`, to locate an installed
  PSR-18 client and PSR-17 factories (Laravel ships Guzzle 7, which discovery
  finds). Recorded in `docs/adr/ADR-0002-http-client-discovery.md`. Container
  bindings always win over discovery (AC5).
- **require-dev:** `illuminate/container` + `illuminate/config` (a bare harness
  to register and inspect the provider — D4), `vlucas/phpdotenv` (so Laravel's
  `env()` resolves when the config file is merged in the bare harness), and
  `guzzlehttp/guzzle` (a concrete PSR-18 client so discovery resolves in tests).
- **PHPStan note (D4 consequence):** the bare harness passes a plain
  `Illuminate\Container\Container` where `Facade::setFacadeApplication()` and
  `ServiceProvider::__construct()` are phpdoc-typed for the `Application`
  contract. This is runtime-valid but PHPStan-max rejects it, and no real
  `Application` is installable here (`illuminate/foundation` is not published
  standalone at ^11; `laravel/framework`/testbench are blocked). A single
  **annotated** `ignoreErrors` entry in `phpstan.neon`, scoped to
  `identifier: argument.type` in the one Laravel test file, covers this
  test-only false positive; `src/` analysis stays strict.
- `illuminate/support`: require-dev + `suggest` (the host Laravel app provides it
  at runtime); the provider is never loaded outside a Laravel app.

## Behavior

Given/When/Then; each maps to a Pest test tagged `->group('SPEC-004')`, run inside
a bare `illuminate/container` + `illuminate/config` harness that registers the
provider (D4). No live signing service is contacted — bindings are resolved and
inspected; AC5's override is proven with a mock PSR-18 client. AC6 is the required
error/misconfiguration path.

- **AC1 — resolves a configured signer**
  - Given the provider is registered and config sets
    `service.base_url = https://sign.test`, `service.api_key = secret`
  - When `app(SignerInterface::class)` is resolved
  - Then it is a `SigningServiceSigner`, and it carries a `SigningServiceConfig`
    with that base URL and API key.

- **AC2 — resolves a configured reader**
  - Given the same setup
  - When `app(ReaderInterface::class)` is resolved
  - Then it is a `SigningServiceReader` configured with the same base URL/key.

- **AC3 — config is driven by env and publishable**
  - Given `CONTENTAUTH_SERVICE_URL` and `CONTENTAUTH_API_KEY` set in the
    environment
  - When the merged `content-credentials` config is read
  - Then `service.base_url` / `service.api_key` reflect those env values; and the
    provider registers the config file for publishing to the app's `config/`.

- **AC4 — facade proxies to the bound services**
  - Given a fake `SignerInterface` bound in the container returning a known
    `SignedAsset`
  - When `ContentCredentials::sign($asset, $manifest)` is called via the facade
  - Then the fake is invoked and its `SignedAsset` is returned (and likewise
    `ContentCredentials::read()` proxies to `ReaderInterface`).

- **AC5 — a container-bound PSR-18 client overrides discovery**
  - Given the app binds its own `Psr\Http\Client\ClientInterface` instance
  - When `SignerInterface` is resolved and used
  - Then the signer uses the container-bound client (not a discovered one).

- **AC6 — missing required config fails clearly** *(required error path)*
  - Given `service.api_key` is empty/absent
  - When `SignerInterface` (or `ReaderInterface`) is resolved
  - Then a `MissingConfigurationException` (implements
    `ContentCredentialsException`) is thrown naming the missing key; and the
    exception message does not contain any configured secret.

- **AC7 — architecture boundary holds** *(build-enforced, not a unit test)*
  - Given the new Laravel-layer code
  - When `composer deptrac` runs
  - Then Core has **zero** dependencies on `Provemark\ContentCredentials\Laravel` or
    `Illuminate\*`; only `Laravel → Core` edges exist. (Enforced by `composer
    check`; noted here for traceability.)

## API sketch

Illustrative only. `declare(strict_types=1)`; `final` where possible; PHPStan
level max. Lives in `src/Laravel` (Laravel layer — may use `illuminate/*`).

```php
namespace Provemark\ContentCredentials\Laravel;

use Provemark\ContentCredentials\Core\Reading\ReaderInterface;
use Provemark\ContentCredentials\Core\Reading\SigningServiceReader;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Signing\SigningServiceSigner;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class ContentCredentialsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/content-credentials.php', 'content-credentials');

        $this->app->singleton(SigningServiceConfig::class, /* build from config, throw if api_key blank */);
        $this->app->singleton(SignerInterface::class, fn ($app) => new SigningServiceSigner(
            $app->make(ClientInterface::class),          // container or discovered
            $app->make(RequestFactoryInterface::class),
            $app->make(StreamFactoryInterface::class),
            $app->make(SigningServiceConfig::class),
        ));
        $this->app->singleton(ReaderInterface::class, /* new SigningServiceReader(...) */);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/content-credentials.php' => config_path('content-credentials.php'),
        ], 'content-credentials-config');
    }
}

final class ContentCredentialsManager
{
    public function __construct(
        private readonly SignerInterface $signer,
        private readonly ReaderInterface $reader,
    ) {}
    // sign(Asset, Manifest): SignedAsset ; read(Asset): ManifestReport
}

/** @method static \Provemark\ContentCredentials\Core\Signing\SignedAsset sign(...) */
final class ContentCredentials extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor(): string { return ContentCredentialsManager::class; }
}
```

Auto-discovery (composer.json):

```json
"extra": {
  "laravel": {
    "providers": ["Provemark\ContentCredentials\\Laravel\\ContentCredentialsServiceProvider"],
    "aliases": { "ContentCredentials": "Provemark\ContentCredentials\\Laravel\\ContentCredentials" }
  }
}
```

## Decisions (resolved at approval, 2026-07-27)

The draft's open questions were resolved as proposed; recorded here so the
approved spec is self-contained.

- **D1 — PSR-18 resolution.** Container-or-discovery via `php-http/discovery`
  (best DX with Laravel's bundled Guzzle); a container-bound `ClientInterface`
  and PSR-17 factories always win over discovery (AC5). New runtime dep →
  ADR-0002.
- **D2 — facade thickness.** Thin proxy (`sign` / `read`) for v1; a convenience
  `signAiImage(...)` combining `ManifestBuilder` + signer is deferred.
- **D3 — config keys / env names.** `service.base_url` ←
  `CONTENTAUTH_SERVICE_URL`, `service.api_key` ← `CONTENTAUTH_API_KEY` (matches
  `.env.example`).
- **D4 — test harness.** *(Amended 2026-07-27.)* A minimal bootstrap:
  `illuminate/container` + `illuminate/config` (require-dev), building a
  container + config repository to register the provider and assert
  bindings/facade. `orchestra/testbench` is **not installable here** — it pulls
  `laravel/framework`, which *replaces* our pinned `illuminate/support` and is
  additionally blocked by security advisories. `illuminate/support` stays
  require-dev + `suggest`.
- **D5 — jobs / artisan.** Deferred to a later spec.
- **D6 — when to fail on missing config.** At resolve time, when building
  `SigningServiceConfig` with a blank `api_key`
  (`MissingConfigurationException`, AC6).

No open questions remain.

## Traceability

Implemented 2026-07-27. Tests in
`tests/Unit/Laravel/ContentCredentialsServiceProviderTest.php`, tagged
`->group('SPEC-004')` (7 tests). `composer check` green: Pint + PHPStan level max
(one annotated test-only ignore, above) + Pest (56 total) + Deptrac (AC7:
`Core → Laravel`/`Illuminate` edges = 0). ADR-0002 records `php-http/discovery`.

| Criterion | Test (`it …`) | Source (file / symbol) |
|-----------|---------------|------------------------|
| AC1 | resolves a configured signer from the container | `ContentCredentialsServiceProvider::register()`, `SigningServiceConfig` binding |
| AC2 | resolves a configured reader from the container | `ContentCredentialsServiceProvider::register()` |
| AC3 | drives config from env and registers it for publishing | `register()` (`mergeConfigFrom`), `boot()` (`publishes`), `config/content-credentials.php` |
| AC4 | proxies sign() through the facade to the bound signer | `ContentCredentials` (facade), `ContentCredentialsManager::sign()` |
| AC5 | uses a container-bound PSR-18 client over discovery | `ContentCredentialsServiceProvider::resolveClient()` |
| AC6 | throws MissingConfigurationException when api_key is blank; names the key | `register()` (`SigningServiceConfig` closure), `Exception\MissingConfigurationException` |
| AC7 | deptrac (`composer check`) | `deptrac.yaml` (Laravel → Core), enforced build-side |
