# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/provemark/content-credentials/compare/v0.3.0...main
[0.3.0]: https://github.com/provemark/content-credentials/releases/tag/v0.3.0
[0.2.1]: https://github.com/provemark/content-credentials/releases/tag/v0.2.1
[0.2.0]: https://github.com/provemark/content-credentials/releases/tag/v0.2.0
[0.1.0]: https://github.com/provemark/content-credentials/releases/tag/v0.1.0
