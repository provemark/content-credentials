# SPEC-008: HTTP timeout for the signing-service client

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon — 2026-07-28                     |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`SigningServiceSigner` and `SigningServiceReader` make a blocking `sendRequest()`
to the signing service. Neither imposes a timeout — by design, SPEC-002 D7 left
timeouts "to the injected client". In practice the injected/discovered client is
usually Guzzle, whose **default timeout is infinite** (`0`). So a slow or hung
signing service blocks the calling thread indefinitely: a web request hangs, and
every `SignAssetJob` queue worker that hits it is tied up until killed. For a
network dependency on the critical signing path, "no timeout" is a production
liability (raised as the top finding in the 2026-07-28 architecture review).

**Constraint that shapes the design: PSR-18 has no timeout API.** The
`Psr\Http\Client\ClientInterface` contract exposes only `sendRequest()`; a
timeout is a property of the *concrete* client and can only be set when the
client is **constructed**. The Core signer/reader therefore cannot portably
enforce a timeout themselves — doing so would either coincidentally couple Core
to a specific client (forbidden: Core must stay framework/-client-agnostic,
CLAUDE.md) or require unsafe tricks (e.g. `pcntl` alarms). The timeout belongs at
**client construction**, which in this package happens in the Laravel provider's
`resolveClient()` (`ContentCredentialsServiceProvider`). Core stays untouched.

## Scope

**In scope**

- A **safe default timeout** so the package is not infinite-by-default: when the
  provider builds the HTTP client itself (no `ClientInterface` bound), it applies
  a bounded request timeout and connect timeout.
- **Configurable** via `config/content-credentials.php`
  (`service.timeout`, `service.connect_timeout`), env-driven.
- **Respect an injected client.** When the application binds its own
  `ClientInterface`, the provider uses it unchanged — the application owns that
  client's timeout. The package never mutates a client it did not construct.
- **Documentation** of the above, including the PSR-18 constraint (an injected
  client's timeout is the app's responsibility) in the README/config comments.
- Core (`SigningServiceSigner`/`SigningServiceReader`) is **unchanged**; this is
  a Laravel-layer + config + docs change (Deptrac boundary preserved).

**Out of scope** (each needs its own spec before it may be built)

- **Retry / backoff** on transient failures (5xx, transport) — a separate policy
  spec. `SignAssetJob` already retries at the queue level (SPEC-006); the
  synchronous facade/command path has no retry, which that spec would address.
- Circuit-breaking, per-call timeout overrides, deadlines/budgets.
- Applying a timeout to a **non-constructable** discovered client that cannot
  accept one (see OQ4).
- Any timeout mechanism inside Core / framework-agnostic use (no Laravel): there,
  the caller constructs and owns the PSR-18 client, so the timeout is theirs.
  Documented, not enforced.

## Behavior

Given/When/Then; each maps to a Pest test tagged `->group('SPEC-008')` using the
bare container harness (SPEC-004 D4). AC4 is the required error path. Verifying
the *effective* timeout on a live socket is integration-level and out of the unit
suite (see OQ1/verification note); the unit ACs assert the wiring/decision.

- **AC1 — a safe default timeout is applied when the package builds the client**
  - Given no `ClientInterface` is bound and no timeout is configured
  - When the provider resolves the HTTP client
  - Then it is constructed with a bounded, non-zero request timeout and connect
    timeout (the documented defaults), not the infinite default.

- **AC2 — a configured timeout overrides the default**
  - Given `content-credentials.service.timeout` = N and `connect_timeout` = M,
    and no bound client
  - When the provider resolves the client
  - Then the constructed client carries N / M.

- **AC3 — an injected client is used unchanged**
  - Given the application binds its own `ClientInterface`
  - When the provider resolves the client
  - Then that exact instance is used and its configuration is not altered (the
    package does not wrap or re-instantiate it).

- **AC4 — invalid timeout configuration fails clearly** *(required error path)*
  - Given a non-numeric or negative `service.timeout`
  - When the provider resolves the client
  - Then a `MissingConfigurationException` (or the same family) is thrown naming
    the offending key — not a silent fallback that hides a misconfiguration.

## API sketch

Illustrative only. Config additions (env-driven), consumed only at client
construction in the Laravel provider; Core is untouched.

```php
// config/content-credentials.php
'service' => [
    'base_url'        => env('CONTENTAUTH_SERVICE_URL', 'http://localhost:3000'),
    'api_key'         => env('CONTENTAUTH_API_KEY'),
    'timeout'         => env('CONTENTAUTH_TIMEOUT', 10),          // seconds, total
    'connect_timeout' => env('CONTENTAUTH_CONNECT_TIMEOUT', 5),   // seconds
],
```

```php
// ContentCredentialsServiceProvider::resolveClient() — sketch
private function resolveClient(Container $app): ClientInterface
{
    if ($app->bound(ClientInterface::class)) {
        return $app->make(ClientInterface::class);   // AC3: app owns its timeout
    }

    // Build a timeout-configured client when we can (see OQ1/OQ4).
    return $this->buildDefaultClient($this->timeouts($app));
}
```

## Decisions (resolved at approval, 2026-07-28)

Resolved as proposed in the draft; recorded so the approved spec is
self-contained.

- **D1 — Guzzle in the Laravel provider (was OQ1).** When no `ClientInterface` is
  bound, the provider builds `new GuzzleHttp\Client(['timeout' => …,
  'connect_timeout' => …])` if `GuzzleHttp\Client` exists. The Guzzle reference
  lives only in `src/Laravel` — Core stays client-agnostic (Deptrac preserved).
- **D2 — defaults (was OQ2).** `timeout` 10s (total), `connect_timeout` 5s.
- **D3 — both timeouts (was OQ3).** Expose `service.timeout` and
  `service.connect_timeout`.
- **D4 — discovered-client caveat (was OQ4).** When no client is bound and Guzzle
  is absent, fall back to `Psr18ClientDiscovery::find()` (timeout **not** applied)
  with a documented README/config note. Do not hard-fail — a working (if
  un-timed-out) client beats an exception.
- **D5 — verification seam (was OQ5).** The resolved timeouts are a bindable,
  readonly `HttpClientOptions` value object (validated at resolution — AC4). Unit
  tests assert that object (the wiring), not the socket; effective enforcement is
  Guzzle's. `SigningServiceConfig` stays timeout-free (timeout is a client
  concern, not part of the signing contract).
- **D6 — retry is separate (was OQ6).** Retry/backoff stays its own future spec;
  `SignAssetJob` already retries at the queue level (SPEC-006).

No open questions remain.

## Traceability

Implemented 2026-07-28. Tests in `tests/Unit/Laravel/HttpTimeoutTest.php`
(`->group('SPEC-008')`, 5 tests incl. the AC4 dataset), reusing the SPEC-004
harness. `composer check` green (Pint + PHPStan level max + Pest + Deptrac 0 —
the Guzzle reference is confined to `src/Laravel`, Core untouched). Per D5 the
unit tests assert the resolved `HttpClientOptions` (the wiring); effective
socket enforcement is Guzzle's.

| Acceptance criterion | Test (`it …`) | Source (file/symbol) |
|-----------------------|---------------|----------------------|
| AC1 | applies a safe default timeout when none is configured | `ContentCredentialsServiceProvider::timeoutSeconds()` (defaults 10/5), `HttpClientOptions` |
| AC2 | applies a configured timeout over the default | `ContentCredentialsServiceProvider::httpClientOptions()/timeoutSeconds()`, `config/content-credentials.php` |
| AC3 | uses an injected PSR-18 client unchanged | `ContentCredentialsServiceProvider::resolveClient()` (bound-client branch) |
| AC4 | throws MissingConfigurationException for an invalid timeout (negative / non-numeric) | `ContentCredentialsServiceProvider::timeoutSeconds()` (validation) |
| D1/D4 | (integration) | `resolveClient()` builds `GuzzleHttp\Client` when present, else `Psr18ClientDiscovery::find()` |