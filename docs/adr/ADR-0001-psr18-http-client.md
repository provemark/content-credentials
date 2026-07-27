# ADR-0001: Use PSR-18 / PSR-17 HTTP abstractions for the signing client

| Field    | Value                          |
|----------|--------------------------------|
| Status   | accepted                       |
| Date     | 2026-07-27                     |
| Spec     | SPEC-002 (Signing)             |
| Deciders | Maurice van Loon (maintainer)  |

## Context

SPEC-002 needs `SigningServiceSigner` (Core) to make HTTP calls to the `service/`
`/v1/sign` endpoint. Adding an HTTP client is a new **runtime** dependency, which
CLAUDE.md requires be recorded in an ADR.

Constraints:

- **Core must stay framework-agnostic.** Deptrac forbids Core depending on
  Laravel/illuminate. Core also should not hard-wire a specific HTTP client
  (Guzzle, Symfony HttpClient) — that is the consuming application's choice, and
  the Laravel layer will bind one later.
- The spike (`bin/spike.php`) used raw ext-curl inline; that is not reusable,
  testable, or client-agnostic.
- We want to unit-test the signer without a live network or signing service.

## Decision

Depend only on the **PSR HTTP interface** packages, and inject the concrete
implementations:

- `psr/http-client` (PSR-18) — `ClientInterface`
- `psr/http-factory` (PSR-17) — `RequestFactoryInterface`, `StreamFactoryInterface`
- `psr/http-message` (PSR-7) — request/response/stream types

`SigningServiceSigner` receives a `ClientInterface` and the PSR-17 factories via
its constructor. Core ships **no** concrete client.

For tests (require-dev): `nyholm/psr7` (PSR-7/PSR-17 implementation) and
`php-http/mock-client` (a PSR-18 `ClientInterface` that records requests and
returns canned responses), so every SPEC-002 acceptance criterion is exercised
with no I/O.

## Consequences

- **Positive:** Core stays client-agnostic and Laravel-free; the signer is fully
  unit-testable via the mock client; consumers pick any PSR-18 client (Guzzle,
  Symfony, etc.); no direct ext-curl coupling in library code.
- **Cost:** callers must provide a PSR-18 client + PSR-17 factories (the Laravel
  layer will wire defaults via auto-discovery / container bindings in a later
  spec). Three small interface packages are added to `require`.
- **Rejected alternatives:** (a) direct Guzzle dependency — forces a client on
  consumers, harder to swap; (b) ext-curl in Core — untestable without network,
  not injectable; (c) `php-http/httplug` (HTTPlug) — superseded by PSR-18 for
  synchronous clients, extra abstraction we do not need.

## Scope note

Timeouts, retries and connection pooling are the injected client's concern, not
Core's (SPEC-002 D7). Revisit only if a later spec introduces a Core-level
resilience policy.
