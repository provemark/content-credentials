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
production; see [Going to production](#going-to-production).

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

### Rate limiting and concurrency

The service bounds how much work it will accept at once. Excess requests get
**429** with `Retry-After`, and nothing is signed. It refuses rather than
queues: the PHP client bounds a request at 10 seconds, so a queued request would
time out client-side while still holding a slot server-side — the caller has
given up and the service is still paying for it.

`GET /health` reports the effective limits and how many signs are in flight:

```json
{"in_flight": 2, "limits": {"max_concurrent_signs": 4, "rate_limit_requests": 60,
 "rate_limit_window_ms": 60000, "request_timeout_ms": 15000, "headers_timeout_ms": 10000}}
```

That last part matters more than it looks. Signing does **not** block the event
loop — measured, six concurrent signatures complete in roughly the time of two —
so a saturated instance answers `/health` just as fast as an idle one. Without
`in_flight`, an orchestrator cannot tell them apart and keeps routing to an
instance about to run out of memory.

Limits are **on by default**; a protection that ships off is one nobody turns
on. Setting one to `0` disables it explicitly, and `/health` says so.

Tune with `MAX_CONCURRENT_SIGNS`, `RATE_LIMIT_REQUESTS`, `RATE_LIMIT_WINDOW_MS`,
`REQUEST_TIMEOUT_MS` and `HEADERS_TIMEOUT_MS`.

### Sizing the container

Signing is memory-hungry in a way that is easy to underestimate. A request holds
the parsed base64 string, the decoded buffer, the signed file read back from
disk, and its base64 in the response — and measured against an idle baseline of
about 18 MiB, the real cost is roughly **7×** the asset, not the four copies
that suggests:

| Asset | Peak at 4 concurrent | Per request | Multiplier |
|---|---|---|---|
| 1.0 MB | 66 MiB | 12.1 MiB | 12.1× |
| 4.1 MB | 161 MiB | 35.9 MiB | 8.7× |
| 11.4 MB | 332 MiB | 78.6 MiB | 6.9× |

The ratio falls as assets grow, because fixed per-request overhead amortises.
So, to size a container:

```
peak memory ≈ MAX_CONCURRENT_SIGNS × 7 × largest asset you accept
```

`MAX_BODY_SIZE` (default **20 MB**) is what caps that last term. Base64 inflates
an asset by a third, so 20 MB of body carries roughly a 15 MB asset — well above
the 11.4 MB a 2000×2000 PNG of incompressible pixels measures — and peaks around
420 MB at the default concurrency cap. The previous default of 50 MB carried a
~37 MB asset and peaked near 1 GB, which does not fit in the 512 MB container
many people would give it.

A body over the limit is refused with **413** before it reaches the signing
path. Raise `MAX_BODY_SIZE` if you sign larger assets, but raise the container's
memory with it — the concurrency cap will not save you, because the body is
buffered *before* any limit is consulted. That is also why lowering this setting
does more for memory than anything else here.

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

### Rotating the signing key

The service reads `SIGNING_CERT_PATH` and `SIGNING_KEY_PATH` **once at startup**.
There is no reload endpoint and no file watching, by design: a restart is simple,
atomic, and cannot half-apply. Rotation is three steps.

```bash
# 1. Note the certificate you are replacing, so you can tell it changed.
curl -s localhost:3000/health | jq .signing_cert

# 2. Put the new certificate and key where the mounts point, then restart.
docker compose up -d --force-recreate service

# 3. Confirm the new certificate is the one now in use.
curl -s localhost:3000/health | jq .signing_cert
```

```json
{
  "fingerprint_sha256": "6fb5eddb353a82fa8720b1d54a4925eaa20e128b10cc4b3fa4d3e9e920c04001",
  "not_after": "Aug 26 18:46:40 2030 GMT"
}
```

**Step 3 is not optional.** A mount that did not take, a stale image layer, a
path typo — each leaves the service happily signing with the *old* key while
looking, from the outside, exactly like a successful rotation. The fingerprint is
the SHA-256 digest of the loaded leaf certificate; compare it against the one you
installed (`openssl x509 -in your.crt -noout -fingerprint -sha256`). `not_after`
is the expiry, so you can see a rotation coming rather than discover it.

Two things to plan for:

- **In-flight requests are lost.** Signing is not resumable, and the restart does
  not drain. Rotate during a quiet window, or take the instance out of rotation
  first. A caller sees a connection error, not a corrupt asset.
- **Assets signed with the old certificate stay valid.** A signature is checked
  against the certificate embedded in the manifest, not against whatever the
  service holds today. Rotation does not invalidate history — but if the old
  certificate was rotated because it was *compromised*, that is exactly the
  problem, and revocation is a matter for the issuing CA.

This restart-based procedure is what the C2PA Generator Product Security
Requirements mean by being **capable of rotating** the claim signing key (O.2,
Assurance Level 1); hot reload is not required.

### Conformance alignment

The C2PA runs a [Conformance Program](https://github.com/c2pa-org/conformance-public)
with a public **Conforming Products List**. Worth being precise about who that
applies to, because it is easy to get backwards.

**This library cannot be on that list, and neither can any library.** A
*Generator Product* is defined as the set of software and configuration that
works together as a system to produce assets, and it "is always the Signer" and
the entity named on the list. That is **your deployment** — your application,
this service, and your certificate. The programme explicitly allows a Generator
Product to rely on a claim-generator service "created by the Applicant **or by a
different entity**", which is the role this package plays. You would be the
applicant; we would be a component.

If you do apply, you must submit a Generator Product Security Architecture
document. Here is how this service maps onto the Level 1 requirements that
concern the signing key (**O.2**), so you can describe it rather than reverse
engineer it:

| Requirement | How this architecture answers it |
|---|---|
| The key is held by a discrete component "with an unrelated attack surface" | The signing service is a separate process, in its own container, published on loopback only. The key never enters your PHP application. |
| Access follows least privilege | Certificate and key are read-only mounts; the service reads them once and exposes no endpoint that returns them. |
| Capable of rotating the claim signing key | Restart-based rotation, above, with `/health` reporting the live certificate so a rotation is verifiable. |

Dependency scanning (**O.3**) is covered in [SECURITY.md](SECURITY.md).

Two things this does **not** claim. Assurance **Level 2** requires
hardware-backed key storage and attestation, which a PEM on a mounted volume is
not. And nothing here has been assessed by the Conformance Program — it is a
mapping to published requirements, not a conformance claim.

The Quickstart above is the shortest path. These sections are the reference:
the full set of accessors, and what each one does and does not tell you.

## Reading without the signing service

**Verification needs no signing service.** It needs no private key and no
certificate either — checking a credential is a function of the asset bytes plus,
optionally, a trust list. Until now it needed a service anyway, because reading
and signing shared one transport. `ExtC2paReader` removes that.

```bash
pie install ericmann/ext-c2pa      # https://github.com/php/pie
```

```php
use Provemark\ContentCredentials\Core\Reading\ExtC2paReader;

$reader = new ExtC2paReader($anchorsPem);   // PEM contents, not a path
$report = $reader->read(new Asset($bytes, MediaType::Png));

$report->isVerifiedAiGenerated();   // no HTTP, no service, no key
```

Both readers implement `ReaderInterface` and return the same `ManifestReport`,
so choosing between them is an installation decision, not an API one:

```php
$reader = ExtC2paReader::isAvailable()
    ? new ExtC2paReader($anchorsPem)
    : new SigningServiceReader($client, $factory, $factory, $config);
```

Construction throws `ExtensionMissingException` when the extension is absent. It
deliberately does **not** fall back to the service reader: a caller who asked for
in-process reading and silently got HTTP cannot tell, and the fallback would need
a URL and token they never supplied.

### In Laravel

Set the mode in `config/content-credentials.php`; everything resolved from the
container — the facade, the jobs, the artisan commands — follows it.

```dotenv
CONTENTAUTH_READER=auto            # service (default) | extension | auto
CONTENTAUTH_TRUST_ANCHORS=/path/to/anchors.pem   # or the PEM contents
```

⚠️ **The default is `service`, so installing the extension does nothing until you
set this.** That is deliberate. The two readers run different c2pa-rs versions,
and an extension installed for an unrelated reason should not silently change
which engine decides your trust verdicts. `auto` is the setting most people want
— but as a choice you made, not one that happened to you.

`php artisan content-credentials:read <file>` prints the mode it resolved, so
"which engine produced this report?" is answerable without reading config:

```
reader             : extension
hasManifest        : true
```

`CONTENTAUTH_TRUST_ANCHORS` accepts PEM contents **or** a path — a path is read
for you, because every trust surface underneath this one takes contents and
silently verifies nothing when handed a path.

It applies to the **extension reader only**. The service reader's trust
verification is configured on the service, through `CONTENTAUTH_TRUST_SETTINGS`.
Same concept, two places: if you set `CONTENTAUTH_TRUST_ANCHORS` and the service
reader still reports `isTrusted()` false, that is why.

### What you are taking on

- **[`ericmann/ext-c2pa`](https://github.com/ericmann/ext-c2pa) is at `v0.1.0`.**
  It is an Automattic VIP product built for a WordPress plugin, not neutral
  infrastructure, and its API may move. The adapter is the containment: a break
  is one class to fix, and callers see nothing.
- **The two readers run different engines.** The extension carries **c2pa-rs
  0.89.0**; the signing service carries **0.90.4**. They agree today — an
  integration test compares both readers accessor by accessor on the same asset,
  and that test is what would tell us they had stopped. Run it with
  `vendor/bin/pest --group=SPEC-019` before relying on a mixed setup.
- **Signing still goes through the service.** The extension can sign too, and
  this library does not expose that: it would put the private key in your web
  process, which is the one thing this architecture exists to avoid. Reading
  in-process while signing through the service is a supported, and probably the
  best, combination.

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

### Supported media types

| Media type | `MediaType` case | File extensions |
|---|---|---|
| `image/png` | `Png` | `.png` |
| `image/jpeg` | `Jpeg` | `.jpg`, `.jpeg` |
| `image/webp` | `Webp` | `.webp` |
| `image/avif` | `Avif` | `.avif` |
| `image/gif` | `Gif` | `.gif` |
| `image/tiff` | `Tiff` | `.tif`, `.tiff` |
| `image/svg+xml` | `Svg` | `.svg` |
| `audio/wav` | `Wav` | `.wav` |
| `audio/mpeg` | `Mp3` | `.mp3` |
| `audio/flac` | `Flac` | `.flac` |
| `video/mp4` | `Mp4` | `.mp4` |
| `video/quicktime` | `Mov` | `.mov` |
| `video/x-msvideo` | `Avi` | `.avi` |

`audio/mp3`, `audio/x-flac` and `video/avi` are accepted as input spellings and
normalised to the registered `audio/mpeg`, `audio/flac` and `video/x-msvideo`. Anything outside this table is refused — by the client with
`UnsupportedMediaTypeException`, and by the service with a **400** naming what it
does accept. A running service publishes its own list at `GET /health`
(`media_types`), so you can check a deployment rather than trust this table.

**Size applies to every media type, not per format.** `MAX_BODY_SIZE`
(default 20 MB) and the ~7× memory multiplier described under
[Sizing the container](#sizing-the-container) are the same for a PNG and for an
MP4. That comfortably covers images and short audio — but note that **lossless
audio is not short audio**: a few minutes of FLAC approaches or exceeds the
20 MB body limit, so `MAX_BODY_SIZE` is the first thing to check before signing
music rather than voice clips.

It does **not** cover real video. `video/mp4`, `video/quicktime` and
`video/x-msvideo` are supported as *containers* — they sign, read back and carry
the Article 50 marking exactly like an image — but they are **bounded to small
files**, because the transport is base64 in one HTTP body: the asset is inflated by a third, buffered whole, and held several
times over while signing. A body over the limit is refused with a **413** that
says so. Signing video of a realistic length needs a different transport
(streaming or path-based signing), which is a separate piece of work and not
something this version does.

#### Signing SVG: sign the deliverable, not the build asset

SVG is the one format whose ordinary tooling destroys the credential by default,
so it needs more than the general rule that post-sign edits invalidate a
manifest. Measured against a signed SVG:

| Operation | Result |
|---|---|
| **SVGO with default settings** | the manifest is removed **silently** — the image renders identically and a verifier cannot tell it from a file that was **never signed** |
| **Any tool that re-serialises the XML** | the namespace prefix is rewritten and the file no longer parses as C2PA |

`preset-default` includes `removeMetadata`, and every common bundler
(`vite-svg-loader`, `svgr`, webpack's SVGO loaders) runs SVGO with defaults. So
an SVG signed and then added to a front-end build arrives at the browser
unsigned, with nothing anywhere reporting a problem.

The rule that follows: sign SVG as a final deliverable, **not as a build asset**
— a generated diagram or illustration handed over as a file, rather than one
about to enter an asset pipeline. If you must do the latter, disable
`removeMetadata` and verify the output.

#### Formats this package does not accept

Not excluded on principle — each has a reason:

| Format | Why not |
|---|---|
| `application/pdf` | c2pa-rs can **read** C2PA from PDF but not write it (`pdf_io.rs` returns "PDF write functionality will be added in a future release"). The C2PA specification does define PDF embedding, so this is an upstream gap, not a specification one. |
| `video/webm` | Matroska. c2pa-rs has no handler at all, which is why it fails on reading too. |
| `image/x-adobe-dng` | Unmeasured. A TIFF renamed `.dng` proves nothing, because the engine reads the format from the bytes. |
| JPEG XL | A handler exists upstream; we have not measured it. |

This project does not ship what it has not seen work.

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
