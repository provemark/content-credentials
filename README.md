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

Article 50(2) covers content that is *generated **or manipulated***, and both are
supported. Marking manipulation takes one extra argument — **the original
asset** — because C2PA records an edit as a `c2pa.opened` action pointing at an
ingredient whose hash covers the original's bytes, not a filename or a digest
you can supply instead. See [What you can mark](docs/marking.md).

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

Listed as the PHP library under *Related projects* in the Content Authenticity
Initiative's [community resources](https://opensource.contentauthenticity.org/docs/community-resources/).
That is a listing, not a conformance claim — see
[Going to production](docs/production.md) for what the C2PA Conformance Program
covers and why no library can appear on the Conforming Products List.

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
- The **signing service** running (see [Running the signing service](docs/service.md)).

## Quickstart

Ten minutes from nothing to a signed image you can verify. Two pieces are
involved: a **signing service** that holds the private key, and the **PHP
library** that talks to it. The service comes first — without it, the library
has nothing to call.

### 1. Run the signing service

It lives in this repository, not in the Composer package, so clone the repo:

```bash
git clone https://github.com/provemark/content-credentials.git
cd content-credentials

cp .env.example .env
# Generate a shared secret and put it in .env as CONTENTAUTH_API_KEY:
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"

# The private test key is deliberately not committed. Fetch the c2pa-rs sample —
# test material only, never a real key:
curl -sfSL https://raw.githubusercontent.com/contentauth/c2pa-rs/main/cli/sample/es256_private.key \
  -o certs/es256_private.key

docker compose up -d --build
curl -s http://127.0.0.1:3000/health
```

You should see `{"status":"ok","signing_alg":"es256",...}`. If not, stop here —
nothing below will work.

### 2. Install the library in your application

```bash
composer require provemark/content-credentials
```

In **Laravel** the service provider and facade register automatically. Point it
at the service with the same secret you generated above:

```dotenv
CONTENTAUTH_SERVICE_URL=http://localhost:3000
CONTENTAUTH_API_KEY=the-value-from-your-.env
```

### 3. Sign an image

```php
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Laravel\ContentCredentials;

$manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
    ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
    ->build();

$signed = ContentCredentials::sign(
    new Asset(file_get_contents('image.png'), MediaType::Png),
    $manifest,
);

file_put_contents('signed.png', $signed->bytes);
```

> ⚠️ **Write those bytes as they are.** Any re-encode, resize, optimiser or CDN
> image transform invalidates the credential — the signature covers the file's
> bytes. This is the single most common way a working integration breaks, and it
> fails silently: the image still displays, the credential is simply gone.

### 4. Check that it worked

```php
$report = ContentCredentials::read(new Asset($signed->bytes, MediaType::Png));

$report->isVerifiedAiGenerated();  // true — marked AND the signature checked out
$report->signer()?->issuer;        // "C2PA Test Signing Cert"
```

Or from the repository, using the authoritative tool:

```bash
bin/verify.sh signed.png
```
```
Signed by      : C2PA Test Signing Cert / CN=C2PA Signer [Es256]
Signature valid: PASS (claimSignature.validated)
Cert trusted   : PASS (signingCredential.trusted)
AI Art.50 mark : PASS (digitalSourceType=trainedAlgorithmicMedia)
Remaining status/failures: none
```

`Cert trusted: PASS` here means the bundled **test** anchors trust the bundled
**test** certificate — `bin/verify.sh` passes them to c2patool deliberately. A
public verifier, using the production trust list, will say untrusted. That is
correct and expected; see below.

### What you have, and what you do not

The signature is **cryptographically valid**, and the image carries the EU AI
Act Article 50 marking: a `c2pa.actions.v2` assertion with
`digitalSourceType = trainedAlgorithmicMedia`.

What you do not have yet is a certificate anyone else trusts. The bundled one is
c2pa-rs **test** material — public verifiers will report the signature as valid
and the certificate as untrusted. Replacing it is the one step between this and
production; see [Going to production](docs/production.md).

## Where the rest lives

The quickstart above is the whole of the happy path. Everything else has its own
page, so this one stays readable:

| Page | What is on it |
|---|---|
| [Usage](docs/usage.md) | Building manifests, signing and reading — Laravel and plain PHP, configuration, the facade, jobs and commands |
| [What you can mark](docs/marking.md) | The thirteen media types, the `digitalSourceType` terms, what each one actually claims, and marking manipulated content |
| [Choosing a reader](docs/readers.md) | Verifying without the signing service, binding the in-process reader, and the trade-off between the two |
| [Running the signing service](docs/service.md) | Audit logging, rate limits, sizing the container, assertion limits, rotating the key |
| [Going to production](docs/production.md) | Certificates a public verifier will trust, trust-list verification, C2PA Conformance Program alignment |
| [Stability and support](docs/stability.md) | What is public API, which PHP and Laravel versions are supported, the deprecation policy, and what 1.0 would require |

Deeper background: [`docs/c2pa-primer.md`](docs/c2pa-primer.md) for the domain
rules this package is built on, and [`docs/adr/`](docs/adr/) for the decisions
that shaped it.

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
- **One service, one caller.** The service authenticates a single shared token,
  so everything derived from it is shared too. Audit records identify *which
  token* signed something, not which application — with one token that is the
  same value on every record. The rate limit is likewise one budget for
  everyone holding it.

  That is fine for the common case of one application per service. It bites the
  moment two callers share an instance — staging and production pointed at the
  same service is the usual way this happens, because certificates are not
  cheap enough to duplicate. Then a runaway job in staging spends production's
  budget, rotating the token stops both at once, and a leak from either is a
  leak from both. If that describes your setup, **run a service per caller**, or
  [open an issue](https://github.com/provemark/content-credentials/issues) —
  named per-client credentials are specified and waiting for a real deployment
  to shape them.
- **Verify before you trust what you read.** `isAiGenerated()`,
  `signer()` and `digitalSourceTypes()` report what a manifest *claims* — they
  do not imply the signature checked out. Gate on `isSignatureValid()` (and
  `isTrusted()` where trust matters) before acting on a credential.

## License

[MIT](LICENSE) © Maurice van Loon
