# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Laravel 12 and 13 are now supported and covered by CI.** The Laravel
  integration was only ever tested against Laravel 11, while nothing stopped an
  application on 12 or 13 from installing the package — `illuminate/*` sits in
  `require-dev` and `suggest`, never in `require`, so Composer imposed no
  constraint on consumers. Anyone on a newer Laravel was therefore running
  untested code. The dev constraints are now `^11.0|^12.0|^13.0` and the CI
  matrix runs `composer check` across PHP 8.3/8.4/8.5 × Laravel 11/12/13.
  No source change was required: the provider, facade, jobs and artisan
  commands pass unmodified on all three majors.

### Fixed

- **Deprecation notices under Laravel 11 + PHP 8.5.** The artisan command tests
  emitted `Using null as an array offset is deprecated` from
  `illuminate/console`. This is upstream code, fixed in Laravel 12; running the
  suite on 12 or 13 clears it.

### Service (requires `git pull` + `docker compose up -d --build`)

- **`@contentauth/c2pa-node` 0.8.0 → 0.8.1** in the signing service, which
  brings c2pa-rs 0.90.0 → 0.90.4. Verified end to end against a live service:
  signing, read-back, the async TSA timestamp path, and `bin/verify.sh`
  (c2patool, trust enabled) all pass, and the signed manifest still carries
  exactly one `c2pa.actions.v2` assertion with `c2pa.created` +
  `digitalSourceType = trainedAlgorithmicMedia`.
- **Resolves a high-severity advisory** in `brace-expansion` (DoS via unbounded
  expansion, GHSA-mh99-v99m-4gvg / GHSA-rgw5-rvv9-x895), pulled in transitively
  through `@contentauth/c2pa-node → unzipper → fstream → rimraf → glob →
  minimatch`. `npm audit` on the service now reports 0 vulnerabilities.

This last section is a service-side change only. The distributed PHP package
(`src/`) is unchanged, so installing via Composer makes no difference — update
the service from a clone of the repository.

## [0.4.2] - 2026-07-29

### Documentation

- **README clarifies what is (and isn't) in the Composer package.** The signing
  service, test certificates and verification tooling (`service/`, `certs/`,
  `bin/`) are `export-ignore`d from the dist, so a note now states they live in
  the source repository, not the installed package. Links to `specs/`, `docs/`
  and `NOTES.md` are now absolute GitHub URLs so they resolve from an installed
  copy in `vendor/` as well, and the test-certificate wording no longer implies a
  ready-to-use cert+key pair is bundled (the public chain and trust settings are
  committed; the private key is fetched). Docs only — no code change.

## [0.4.1] - 2026-07-29

### Fixed

- **Manifest-less reads no longer error (SPEC-010).** `POST /v1/read` in the
  signing service returned HTTP 500 (`Cannot read properties of null`) for an
  asset with no C2PA manifest, because `Reader.fromAsset()` resolves to `null`
  in that case. It now returns an empty manifest store (HTTP 200), which the
  client already parses into an empty `ManifestReport` (`hasManifest() ===
  false`) — honouring the SPEC-003 "absence is not an error" contract end to
  end. Found by a new Eris property-based test suite (a stateful provenance
  chain driven against the real service). This is a service-side change
  (`service/server.js`); the distributed PHP package (`src/`) is unchanged, so
  installers via Composer see no difference.

## [0.4.0] - 2026-07-28

### Security

- **Hardening (SPEC-009).** Constant-time bearer-token comparison in the signing
  service (SHA-256 digest + `timingSafeEqual`); the PHP client bounds the
  response size it will buffer (`max_response_bytes`, default 96 MiB) instead of
  reading an unbounded body; the service returns **400** (not 500) for client
  errors — content that is not valid base64, or a `mime_type` outside
  `image/png` / `image/jpeg`.

### Added

- **HTTP timeouts for the signing-service client (SPEC-008).** The package builds
  its default HTTP client with a bounded request timeout (10s) and connect
  timeout (5s), configurable via `CONTENTAUTH_TIMEOUT` /
  `CONTENTAUTH_CONNECT_TIMEOUT`, so a hung signing service no longer blocks the
  caller or queue workers indefinitely. A PSR-18 client you bind yourself keeps
  its own timeouts (PSR-18 has no timeout API to override).

### Fixed

- **Silent write failures.** `content-credentials:sign` and `SignAssetJob` now
  surface a failed write (missing/unwritable destination) as an error /
  exception, instead of reporting success and firing `AssetSigned` for a file
  that was never written.
- **`base_url` validation.** The Laravel provider throws
  `MissingConfigurationException` for a blank
  `content-credentials.service.base_url`, symmetric with the existing `api_key`
  check, instead of failing later with an opaque HTTP error.

## [0.3.0] - 2026-07-28

### Added

- **Trusted timestamps (SPEC-007).** The signing service adds an RFC 3161
  timestamp when `CONTENTAUTH_TSA_URL` is set (unset = unchanged: no timestamp);
  it **fails closed** if the TSA is unreachable, never returning an untimestamped
  signature. `GET /health` now reports `timestamping`. The reader gains
  `ManifestReport::hasTimestamp()` to verify a read manifest carries a timestamp
  (present + parseable `signature_info.time`; malformed/absent ⇒ false).
  `bin/e2e.php` asserts timestamp presence against the service's `/health` flag.
  Backwards-compatible: no timestamp is added unless `CONTENTAUTH_TSA_URL` is set.

## [0.2.1] - 2026-07-28

A documentation and tooling patch. No API, behaviour or dependency changes —
fully backwards-compatible with 0.2.0.

### Added

- **README "Going to production" section**: how to move from the bundled test
  certificates to a certificate a public verifier trusts (C2PA conformance
  program / SSL.com free tier), linking the certificates guide.

### Changed

- **CI** now also runs `composer check` on **PHP 8.5** (matrix: 8.3, 8.4, 8.5);
  the suite is verified green on 8.5. Runtime target is unchanged (`^8.3`).

## [0.2.0] - 2026-07-27

### Changed

- **BREAKING — root namespace** is now `Provemark\ContentCredentials\` (was
  `ContentCredentials\`), matching the Composer vendor / GitHub org and avoiding
  collisions with the generic "content credentials" term. Update imports, e.g.
  `use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;`. The package
  name (`provemark/content-credentials`), the `ContentCredentials` facade
  class/alias, the public API and all behaviour are otherwise unchanged.

## [0.1.0] - 2026-07-27

Initial release — a spec-driven rebuild of a proven end-to-end signing-chain
spike. `composer check` (Pint + PHPStan level max + Pest + Deptrac) is green.

### Added

- **Core manifest builder** (SPEC-001): fluent, immutable `ManifestBuilder`
  producing the claim-v2 AI-generated actions assertion (`c2pa.actions.v2` →
  `c2pa.created` with `digitalSourceType = trainedAlgorithmicMedia`) for PNG and
  JPEG.
- **Signing** (SPEC-002): `SignerInterface` + `SigningServiceSigner`, a PSR-18
  client for the signing service `/v1/sign` contract.
- **Reading & verification** (SPEC-003): `ReaderInterface` +
  `SigningServiceReader` parsing the manifest store into a typed
  `ManifestReport` (`isAiGenerated()`, `digitalSourceTypes()`, `signer()`,
  `validationStatusCodes()`, `isTrusted()`).
- **Signature-validity verdict** (SPEC-005): `ManifestReport::isSignatureValid()`
  and `validationState()`, keyed off the c2pa-rs `validation_state`
  (`Valid`/`Invalid`/`Trusted`) — distinct from trust.
- **Laravel integration** (SPEC-004): service provider, config, `ContentCredentials`
  facade, and package auto-discovery; PSR-18 client resolved via the container or
  `php-http/discovery`.
- **Queued job & artisan commands** (SPEC-006): `content-credentials:sign` and
  `content-credentials:read` commands, a `SignAssetJob` (`ShouldQueue`, bounded
  retries) and an `AssetSigned` event.
- **Signing service** (`service/`): a minimal Node service on
  `@contentauth/c2pa-node`, plus `bin/verify.sh` for authoritative c2patool trust
  verification, and `bin/e2e.php` to run the whole chain with the real library.
- Architecture boundary (`Core` must not depend on Laravel/Illuminate) enforced
  by Deptrac.
- Documentation: `specs/`, `docs/adr/` (ADR-0001 PSR-18 injection, ADR-0002 HTTP
  client discovery), `docs/c2pa-primer.md`, and `NOTES.md`.

[Unreleased]: https://github.com/provemark/content-credentials/compare/v0.4.2...main
[0.4.2]: https://github.com/provemark/content-credentials/releases/tag/v0.4.2
[0.4.1]: https://github.com/provemark/content-credentials/releases/tag/v0.4.1
[0.4.0]: https://github.com/provemark/content-credentials/releases/tag/v0.4.0
[0.3.0]: https://github.com/provemark/content-credentials/releases/tag/v0.3.0
[0.2.1]: https://github.com/provemark/content-credentials/releases/tag/v0.2.1
[0.2.0]: https://github.com/provemark/content-credentials/releases/tag/v0.2.0
[0.1.0]: https://github.com/provemark/content-credentials/releases/tag/v0.1.0
