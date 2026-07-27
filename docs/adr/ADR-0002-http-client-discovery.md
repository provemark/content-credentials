# ADR-0002: Discover the PSR-18 client / PSR-17 factories in the Laravel layer

| Field    | Value                          |
|----------|--------------------------------|
| Status   | accepted                       |
| Date     | 2026-07-27                     |
| Spec     | SPEC-004 (Laravel integration) |
| Deciders | Maurice van Loon (maintainer)  |

## Context

SPEC-002/003 keep Core client-agnostic: `SigningServiceSigner` and
`SigningServiceReader` receive a PSR-18 `ClientInterface` and PSR-17 factories by
constructor injection (ADR-0001). The Laravel service provider (SPEC-004) must
supply concrete implementations when it binds `SignerInterface` /
`ReaderInterface`.

Laravel does not bind a PSR-18 `ClientInterface` or PSR-17 factories in its
container by default, though it ships Guzzle 7 (which *is* a PSR-18 client and,
via `guzzlehttp/psr7`, provides PSR-17 factories). We want the package to work
out of the box in a typical Laravel app while still letting an app override the
client (custom middleware, timeouts, mocking in tests).

## Decision

The provider resolves the PSR-18 client and PSR-17 factories in two tiers:

1. **Container first.** If `Psr\Http\Client\ClientInterface` (or a PSR-17
   factory) is bound in the container, use it. Apps override by binding their
   own — this is also how tests inject a mock client (SPEC-004 AC5).
2. **Auto-discovery fallback.** Otherwise use **`php-http/discovery`**
   (`Psr18ClientDiscovery`, `Psr17FactoryDiscovery`) to locate an installed
   implementation — Guzzle in a stock Laravel app.

`php-http/discovery` is added to `require`. `guzzlehttp/guzzle` is added to
require-dev so discovery resolves during the package's own tests.

## Consequences

- **Positive:** zero-config in a normal Laravel app (Guzzle is present); full
  override via a container binding; Core stays client-agnostic and untouched;
  no hard dependency on a specific client in `require`.
- **Cost:** one small runtime package (`php-http/discovery`). If an app has *no*
  discoverable PSR-18 client and binds none, resolution throws
  `Http\Discovery\Exception\NotFoundException` — the provider will surface this
  as a clear configuration error.
- **Rejected alternatives:** (a) hard-depend on `guzzlehttp/guzzle` in `require`
  — forces Guzzle on all consumers; (b) require every app to bind
  `ClientInterface` manually — worse DX, defeats auto-discovery; (c) depend on
  Laravel's HTTP client — it is not a PSR-18 `ClientInterface`.

## Scope note

Discovery is a Laravel-layer concern only. Core never calls discovery — it
always receives the client by injection (ADR-0001). Timeouts/retries remain the
injected client's responsibility (SPEC-002 D7).
