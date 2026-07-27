# Content Credentials

A PHP library for **C2PA Content Credentials**: build, sign, read and verify
manifests for media assets. Its primary purpose is the machine-readable marking
of **AI-generated content** required by the **EU AI Act, Article 50** — a
`c2pa.actions.v2` / `c2pa.created` assertion with
`digitalSourceType = trainedAlgorithmicMedia`.

It ships as two pieces:

- a **framework-agnostic Core** (`ContentCredentials\Core\*`) that builds
  manifests and talks to a signing service over HTTP (PSR-18), and
- an optional **Laravel integration** (`ContentCredentials\Laravel\*`) — a
  service provider + facade that wires everything from config.

The private key never lives in PHP: signing is delegated to a small **Node
signing service** (`service/`, based on `@contentauth/c2pa-node`) that you run
next to your app.

> **Status:** this is a spec-driven rebuild of a proven end-to-end spike. The
> design, decisions and trade-offs are documented in [`specs/`](specs/),
> [`docs/`](docs/) and [`NOTES.md`](NOTES.md).

## Requirements

- PHP **8.3+**
- A **PSR-18 HTTP client** and **PSR-17 factories**. In Laravel these are
  discovered automatically (Guzzle ships with Laravel); in plain PHP you inject
  your own.
- The **signing service** running (see [Signing service](#signing-service)).

## Installation

```bash
composer require contentcredentials/content-credentials
```

### Installing from GitHub (before Packagist)

Until this package is published on Packagist, install it straight from the Git
repository by adding a VCS repository to your project's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/OWNER/content-credentials" }
    ]
}
```

Then require it by branch (or by tag once releases exist):

```bash
composer require contentcredentials/content-credentials:dev-main
# or, once tagged:  composer require contentcredentials/content-credentials:^0.1
```

Replace `OWNER` with the repository owner. Everything else below works
identically; Composer reads the package name and `extra.laravel` auto-discovery
from the repository's own `composer.json`.

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
```

## Quick start (Laravel)

```php
use ContentCredentials\Core\Manifest\ManifestBuilder;
use ContentCredentials\Core\Manifest\MediaType;
use ContentCredentials\Core\Signing\Asset;
use ContentCredentials\Laravel\ContentCredentials;

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
```

## Quick start (plain PHP / any framework)

Core depends only on PSR interfaces — inject any PSR-18 client and PSR-17
factories:

```php
use ContentCredentials\Core\Manifest\ManifestBuilder;
use ContentCredentials\Core\Manifest\MediaType;
use ContentCredentials\Core\Signing\Asset;
use ContentCredentials\Core\Signing\SigningServiceConfig;
use ContentCredentials\Core\Signing\SigningServiceSigner;
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

The service holds the signing key and performs the C2PA operation. Run it with
Docker Compose using the bundled **c2pa-rs ES256 test certificates**:

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
"trusted certificate". See [`docs/c2pa-primer.md`](docs/c2pa-primer.md) §5.

## Development

```bash
composer install
composer check   # Pint (style) + PHPStan (level max) + Pest + Deptrac
```

`composer check` is the single definition of green. The architecture boundary
(`Core` must not depend on Laravel/Illuminate) is enforced by Deptrac.

## Security

- **Never commit real private keys or production certificates.** `certs/` and
  the tests use c2pa-rs **test** material only; `es256_private.key` is
  gitignored. Trust-settings files contain only public test CA certs.
- The `CONTENTAUTH_API_KEY` and service URL come from the environment; the
  library never logs the token or key material.
- All manifest/service input is treated as untrusted and validated.

## License

[MIT](LICENSE) © Maurice van Loon
