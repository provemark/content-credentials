# Content Credentials

[![CI](https://github.com/provemark/content-credentials/actions/workflows/ci.yml/badge.svg)](https://github.com/provemark/content-credentials/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/provemark/content-credentials)](https://packagist.org/packages/provemark/content-credentials)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3-777bb4)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

A PHP library for **C2PA Content Credentials**: build, sign, read and verify
manifests for media assets. Its primary purpose is the machine-readable marking
of **AI-generated content** required by the **EU AI Act, Article 50** — a
`c2pa.actions.v2` / `c2pa.created` assertion with
`digitalSourceType = trainedAlgorithmicMedia`.

It ships as two pieces:

- a **framework-agnostic Core** (`Provemark\ContentCredentials\Core\*`) that builds
  manifests and talks to a signing service over HTTP (PSR-18), and
- an optional **Laravel integration** (`Provemark\ContentCredentials\Laravel\*`) — a
  service provider + facade that wires everything from config.

**The private signing key never touches your web application.** Signing is
delegated to a small **Node signing service** (`service/`, based on
`@contentauth/c2pa-node`) that you run separately — keeping the signing key
isolated from the app process. (This is the deliberate trade-off versus an
in-process native extension, which puts the key on the web server.)

> **Status:** this is a spec-driven rebuild of a proven end-to-end spike. The
> design, decisions and trade-offs are documented in
> [`specs/`](https://github.com/provemark/content-credentials/tree/main/specs),
> [`docs/`](https://github.com/provemark/content-credentials/tree/main/docs) and
> [`NOTES.md`](https://github.com/provemark/content-credentials/blob/main/NOTES.md).

## Requirements

- PHP **8.3+**
- **Laravel 11, 12 or 13** — only if you use the service provider, facade, jobs
  or artisan commands. The core library is framework-agnostic and needs no
  Laravel at all; `illuminate/*` is never a runtime dependency of this package.
  Each major is covered by CI.
- A **PSR-18 HTTP client** and **PSR-17 factories**. In Laravel these are
  discovered automatically (Guzzle ships with Laravel); in plain PHP you inject
  your own.
- The **signing service** running (see [Signing service](#signing-service)).

## Installation

```bash
composer require provemark/content-credentials
```

For the latest unreleased changes, require the `main` branch:

```bash
composer require provemark/content-credentials:dev-main
```

In **Laravel** the service provider and `ContentCredentials` facade are
registered automatically (package auto-discovery). Publish the config if you
want to tweak it:

```bash
php artisan vendor:publish --tag=content-credentials-config
```

Set the service location and shared secret in your `.env`:

```dotenv
CONTENTAUTH_SERVICE_URL=http://localhost:3000
CONTENTAUTH_API_KEY=your-shared-secret
# Optional HTTP timeouts (seconds) for the signing-service calls:
CONTENTAUTH_TIMEOUT=10
CONTENTAUTH_CONNECT_TIMEOUT=5
# Optional cap (bytes) on a service response the client will buffer (default 96 MiB):
CONTENTAUTH_MAX_RESPONSE_BYTES=100663296
```

These timeouts apply to the HTTP client this package builds for you. If you bind
your own PSR-18 client into the container, that client owns its timeouts — PSR-18
has no timeout API, so the package cannot set one on a client it did not
construct.

## Quick start (Laravel)

```php
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Laravel\ContentCredentials;

$bytes = file_get_contents('image.png');

// 1. Describe the asset as AI-generated (EU AI Act Art. 50 marking).
$manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Png)
    ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
    ->withClaimGenerator(config('app.name'), '1.0.0')
    ->build();

// 2. Sign it via the service.
$signed = ContentCredentials::sign(new Asset($bytes, MediaType::Png), $manifest);
file_put_contents('signed.png', $signed->bytes); // never re-encode these bytes

// 3. Read the credential back.
$report = ContentCredentials::read(new Asset($signed->bytes, MediaType::Png));

$report->isAiGenerated();        // true
$report->digitalSourceTypes();   // ['http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia']
$report->signer()?->issuer;      // e.g. "C2PA Test Signing Cert"
$report->hasTimestamp();         // true when signed with a trusted timestamp (see "Going to production")
```

## Quick start (plain PHP / any framework)

Core depends only on PSR interfaces — inject any PSR-18 client and PSR-17
factories:

```php
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Signing\SigningServiceSigner;
use GuzzleHttp\Client;                       // any PSR-18 client
use Nyholm\Psr7\Factory\Psr17Factory;        // any PSR-17 factory

$factory = new Psr17Factory();
$signer = new SigningServiceSigner(
    new Client(),
    $factory,
    $factory,
    new SigningServiceConfig('http://localhost:3000', getenv('CONTENTAUTH_API_KEY')),
);

$manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Jpeg)
    ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
    ->build();

$signed = $signer->sign(new Asset(file_get_contents('in.jpg'), MediaType::Jpeg), $manifest);
file_put_contents('out.jpg', $signed->bytes);
```

Reading works the same way with `SigningServiceReader` → `ManifestReport`.

Supported formats in this version: **PNG and JPEG**.

## Signing service

> **Note:** the signing service, test certificates and verification tooling
> (`service/`, `certs/`, `bin/`) live in the
> [source repository](https://github.com/provemark/content-credentials) — they
> are **not** part of the Composer package. Clone the repo to run them; the
> installed package is the PHP client that talks to the service.

The service holds the signing key and performs the C2PA operation. Run it from a
clone of the repository with Docker Compose. The **public** c2pa-rs ES256 test
**certificate chain and trust settings are committed in `certs/`**; the matching
private key is intentionally **not** — fetch it below:

```bash
cp .env.example .env          # set a CONTENTAUTH_API_KEY value

# The private test key is intentionally NOT committed. Fetch the c2pa-rs sample
# key for local development (test material only — never a real key):
curl -sfSL https://raw.githubusercontent.com/contentauth/c2pa-rs/main/cli/sample/es256_private.key \
  -o certs/es256_private.key

docker compose up -d --build  # service on http://localhost:3000
```

`POST /v1/sign` and `POST /v1/read` are Bearer-authenticated with
`CONTENTAUTH_API_KEY`; `GET /health` is public.

### Audit logging

Every `/v1/sign` request writes one line of JSON to **stdout**, for accepted and
refused requests alike, so you can answer the question an incident starts with:
*did we sign this, when, and at whose request?* Without a record, a fabricated
credential carrying your certificate cannot be told apart from a genuine one —
which makes every credential you ever issued suspect.

```json
{"ts":"2026-08-05T09:14:22.104Z","cid":"01J…","event":"sign","outcome":"signed",
 "token_id":"9f2a41c0b7de","mime_type":"image/png","input_sha256":"3b1f…",
 "input_bytes":1699,"output_sha256":"c07e…","creator_name":"ACME GenAI Image Model",
 "assertion_labels":["c2pa.actions.v2"],"digital_source_types":["…trainedAlgorithmicMedia"],
 "timestamped":true}
```

Every response also carries an `X-Correlation-Id` header, repeated as `cid` in
error bodies. Service errors return a generic message — the detail belongs in
the record, not in a client-side exception — so quote the `cid` in a bug report.

**What is deliberately never recorded:** the bearer token, key material, the
base64 content, the signed bytes, full assertion payloads, or the manifest
store. Callers are identified by `token_id`, a salted one-way digest: two
requests with the same token correlate, and the token cannot be recovered from
it. Caller-supplied strings are length-capped, so nobody can write unbounded
data into your log.

> **Personal data.** `creator_name` is supplied by the caller and reproduced in
> records. In normal use it is application metadata (a tool or model name), but
> if your deployment puts a person's name there, **you are processing personal
> data in your logs** and the retention decision is yours. Records go to stdout;
> collecting, rotating and expiring them is your platform's job.

If the audit write ever fails, the request still succeeds — a logging outage
must not become a signing outage — and `GET /health` reports
`"audit_degraded": true` until the process restarts, so the loss is visible to
monitoring rather than silent.

### Assertion limits

The service constrains what it will attest to. At most **one** `c2pa.actions`
assertion (two would be contradictory, and which one a verifier honours is
undefined), a bounded number of assertions, and bounds on each assertion's size
and nesting depth. Violations return **400** naming the constraint, and nothing
is signed. Tune with `MAX_ASSERTIONS`, `MAX_ASSERTION_BYTES`,
`MAX_ASSERTION_DEPTH` and `MAX_CREATOR_NAME`.

The service takes **no position on `digitalSourceType`** by default. Requiring
`trainedAlgorithmicMedia` would not make an attestation truer — the service can
verify it no better than a camera-capture claim — while excluding the
authenticity use case entirely. If your certificate exists solely to mark
AI-generated content, set `REQUIRE_AI_MARKING=true`; `GET /health` reports the
effective policy.

### Trust-list verification

By default the service does **not** verify the signing certificate against a
trust list. A signed asset then reads back as `Valid` with
`signingCredential.untrusted`, and `ManifestReport::isTrusted()` is `false` **by
design, not by failure** — the read simply never established trust. Signature
validity is unaffected: use `isSignatureValid()` for the integrity verdict.

Set `CONTENTAUTH_TRUST_SETTINGS` to a c2pa settings document to switch it on;
Docker Compose mounts the bundled **test** anchors ready to use:

```dotenv
CONTENTAUTH_TRUST_SETTINGS=/run/secrets/c2pa-trust.settings.json
```

`GET /health` then reports `"trust_verification": true`, and a certificate the
anchors cover reads back as `Trusted` with `isTrusted() === true`.

The service **refuses to start** if the document is unreadable, does not parse,
or could not actually verify — `verify.verify_trust` plus a non-empty
`trust.trust_anchors` or `trust.allowed_list`. That last check matters:
`verify_trust` without trust material verifies nothing *silently*, producing
reads indistinguishable from having configured nothing at all. Failing at
startup is what stops you believing trust is on when it is not.

The bundled anchors trust only the c2pa-rs **test** certificates. Replace them
with the trust list your verifier uses before production.

## Verifying the output

`bin/verify.sh` runs [`c2patool`](https://github.com/contentauth/c2pa-rs) with
the test trust settings and reports signature validity, cert trust and the AI
marking:

```bash
bin/verify.sh out/signed.png
# Signature valid: PASS   Cert trusted: PASS   AI Art.50 mark: PASS
```

Note: test certificates produce a cryptographically **valid signature** but are
not on any production trust list — "valid signature" is not the same as
"trusted certificate". See
[`docs/c2pa-primer.md`](https://github.com/provemark/content-credentials/blob/main/docs/c2pa-primer.md)
§5.

## Going to production

The test certificates above are only trusted against the bundled test settings.
For a signature a public verifier will trust, you need a certificate from a CA on
the C2PA trust list, issued through the C2PA conformance program. As of 2026,
[SSL.com](https://www.ssl.com/products/content-authenticity/content-credentials/c2pa/)
issues production-ready C2PA-conformant certificates, and its free tier includes
a Level&nbsp;1 signing certificate plus trusted timestamps — note it still
requires a valid C2PA conformance record ID at application.

For the full picture of certificates, trust lists and the valid-vs-trusted
distinction, see the write-up:
[**Valid ≠ trusted: a practical guide to C2PA signing certificates**](https://provemark.github.io/articles/c2pa-certificates/).
Whichever certificate you use, the private key stays isolated behind the signing
service — it never enters your web application.

**Trusted timestamps.** Set `CONTENTAUTH_TSA_URL` on the signing service to an
RFC 3161 Time Stamping Authority (e.g. `http://timestamp.digicert.com`) and every
signature carries a trusted timestamp, so its validity survives certificate
expiry. Unset, no timestamp is added (the default); if the TSA is unreachable the
signing request **fails closed** rather than producing an untimestamped
signature. `GET /health` reports `timestamping`, and
`ManifestReport::hasTimestamp()` confirms a read manifest is timestamped. A
timestamp's *trust* still depends on the TSA's own certificate chain.

## Development

```bash
composer install
composer check   # Pint (style) + PHPStan (level max) + Pest + Deptrac
```

`composer check` is the single definition of green. The architecture boundary
(`Core` must not depend on Laravel/Illuminate) is enforced by Deptrac.

To exercise the whole chain against a **running** service with the real library
code (build → sign → read → c2patool verify):

```bash
docker compose up -d --build   # service must be running (see above)
php bin/e2e.php                 # signs tests/fixture.png -> out/signed.png, then verifies
```

## Security

- **Never commit real private keys or production certificates.** `certs/` and
  the tests use c2pa-rs **test** material only; `es256_private.key` is
  gitignored. Trust-settings files contain only public test CA certs.
- The `CONTENTAUTH_API_KEY` and service URL come from the environment; the
  library never logs the token or key material.
- All manifest/service input is treated as untrusted and validated.
- **The signing service publishes on `127.0.0.1` only.** It speaks plain HTTP
  and holds the signing key, so it must not be exposed directly. To reach it
  from another host, put TLS termination in front of it and restrict the network
  path — do not simply widen the port binding in `docker-compose.yml`.
- **Treat `CONTENTAUTH_API_KEY` as equivalent to the signing key.** Anyone who
  can call `/v1/sign` can have assertions signed by your certificate. The
  service constrains *what* it will attest to (see below), but it cannot tell an
  authorised caller from a stolen token. Rotate it like a key, scope it per
  application, and never share one token across environments.
- **Verify before you trust what you read.** `isAiGenerated()`,
  `signer()` and `digitalSourceTypes()` report what a manifest *claims* — they
  do not imply the signature checked out. Gate on `isSignatureValid()` (and
  `isTrusted()` where trust matters) before acting on a credential.

## License

[MIT](LICENSE) © Maurice van Loon
