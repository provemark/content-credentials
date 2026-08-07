# Usage

How to build a manifest, sign an asset and read one back — from Laravel, or
from plain PHP with any PSR-18 client. Start with the quickstart in the
[README](../README.md) if you have not signed anything yet.

## Installation and configuration

The Quickstart covers the short version. This section is the full set of knobs.

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

## Usage (Laravel)

```php
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Laravel\ContentCredentials;

$bytes = file_get_contents('image.png');

// 1. Describe the asset as AI-generated (EU AI Act Art. 50 marking).
$manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
    ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
    ->withClaimGenerator(config('app.name'), '1.0.0')
    ->build();

// 2. Sign it via the service.
$signed = ContentCredentials::sign(new Asset($bytes, MediaType::Png), $manifest);
file_put_contents('signed.png', $signed->bytes); // never re-encode these bytes

// 3. Read the credential back.
$report = ContentCredentials::read(new Asset($signed->bytes, MediaType::Png));

// The marking, verified: true only if the signature also checked out.
$report->isVerifiedAiGenerated(); // true

// The individual pieces. Note isAiGenerated() reports what the manifest
// CLAIMS — it does not imply the signature verified, so gate on
// isSignatureValid() before acting on it.
$report->isSignatureValid();     // true — integrity verdict
$report->isAiGenerated();        // true — the claim
$report->digitalSourceTypes();   // ['http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia']
$report->signer()?->issuer;      // e.g. "C2PA Test Signing Cert"
$report->hasTimestamp();         // true when signed with a trusted timestamp (see "Going to production")
$report->isTrusted();            // true only when the service verified against a trust list
```

> **Claims versus verdicts.** `isAiGenerated()`, `signer()` and
> `digitalSourceTypes()` describe what a manifest *asserts*; they answer for a
> tampered or unverifiable manifest too. `isSignatureValid()` and `isTrusted()`
> are the verdicts. `isVerifiedAiGenerated()` combines the marking with the
> signature verdict, so the safe check is also the short one to write.

## Usage (plain PHP / any framework)

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

$manifest = ManifestBuilder::forAiGenerated(MediaType::Jpeg)
    ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
    ->build();

$signed = $signer->sign(new Asset(file_get_contents('in.jpg'), MediaType::Jpeg), $manifest);
file_put_contents('out.jpg', $signed->bytes);
```

Reading works the same way with `SigningServiceReader` → `ManifestReport`.
