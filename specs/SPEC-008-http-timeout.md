# SPEC-008: HTTP timeout for the signing-service client

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — (while draft)                                   |
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

## Open questions

- **OQ1 (blocker — the core design decision).** How to build a timeout-configured
  default client without coupling Core to Guzzle? Guzzle is already a suggested /
  require-dev PSR-18 client, and the *Laravel layer* may depend on concrete
  packages (Deptrac only fences Core). Proposal: in the Laravel provider, if
  `GuzzleHttp\Client` exists, build `new Client(['timeout' => …,
  'connect_timeout' => …])`; otherwise fall back to `Psr18ClientDiscovery::find()`
  (see OQ4). This keeps the Guzzle touch in `src/Laravel` only. Confirm this is
  acceptable vs. a more abstract client-factory seam.
- **OQ2 (non-blocker).** Default values. Proposal: `timeout` 10s (total),
  `connect_timeout` 5s. Conservative enough to fail fast, generous enough for a
  large asset + a TSA round-trip (SPEC-007). Confirm.
- **OQ3 (non-blocker).** Expose both total and connect timeout, or just a single
  `timeout`? Proposal: both (connect failures should fail faster than a slow
  body). Confirm.
- **OQ4 (blocker for AC1/AC2 completeness).** When no Guzzle is present and the
  client comes from `Psr18ClientDiscovery::find()` (pre-constructed, no timeout
  hook), the package cannot apply a timeout. Options: (a) document the caveat and
  proceed (timeout only when we construct the client); (b) throw a configuration
  error advising the user to bind a timeout-configured client. Proposal: (a) with
  a clear README note. Confirm.
- **OQ5 (non-blocker).** Verification of the *effective* timeout: Guzzle 8 dropped
  `getConfig()`, so asserting the timeout on the built client is awkward. Options:
  a tiny internal factory whose input (the timeout array) is unit-asserted (AC1/2
  check the value passed to construction), plus an optional slow-server
  integration check in `bin/`. Confirm the unit strategy targets the wiring, not
  the socket.
- **OQ6 (non-blocker).** Confirm retry/backoff stays a *separate* spec (this one
  is timeout-only), given `SignAssetJob` already retries at the queue level.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | — | — |
| AC2 | — | — |
| AC3 | — | — |
| AC4 | — | — |