# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

_Nothing yet._

## [0.5.3] - 2026-08-06

A service-and-documentation release. **No change to `src/` or `config/`**, so
nothing the library does changes. The installed package is not byte-identical to
0.5.2 — `README.md` ships in the dist and gained two sections — but `composer
update` changes no behaviour. Update the signing service from a clone of the
repository.

⚠️ **`GET /health` gains a field, and the service now exits on a certificate it
cannot parse.** Both are below.

### Service (requires `git pull` + `docker compose up -d --build`)

- **`GET /health` now reports the loaded signing certificate (SPEC-018).** A new
  `signing_cert` block carries the SHA-256 fingerprint of the leaf certificate
  and its `notAfter`. Additive — nothing existing changed, and nothing secret is
  exposed: a certificate is public by construction, since it travels inside
  every manifest this service signs.

  This is what makes a key rotation *confirmable*. The service reads its
  certificate and key once at startup, so rotation is "replace the files and
  restart" — but until now a mount that did not take, a stale image layer or a
  path typo left the service signing with the superseded key while looking, from
  outside, exactly like a successful rotation.

- **The service now refuses to start on a certificate it cannot parse.** The
  previous check accepted any file containing the word `CERTIFICATE`, so a
  truncated or corrupt PEM started a service that could not sign.

- **express 4.22.2 → 5.2.1.** A major upgrade, previously deferred for want of
  evidence. Verified against the full integration suite (55 passed) plus
  `bin/e2e.php` and `bin/verify.sh`, and specifically on the error paths express
  5 could have changed: an oversized body still returns 413, malformed JSON still
  returns 400 with a correlation id, an excess of concurrent signs still returns
  429, and missing auth still returns 401. No API change.

### Documentation

- **"Rotating the signing key"** in the README: the three-step procedure, what
  it costs (in-flight requests are lost, and the restart does not drain), and
  why the confirmation step is not optional.
- **"Conformance alignment"** in the README, on the C2PA Conformance Program.
  The short version, because it is easy to get backwards: **a library cannot
  appear on the Conforming Products List, and neither can any library.** A
  Generator Product is the deployed system that signs and is always the Signer —
  that is *your* deployment. The section maps this service's key handling onto
  the Assurance Level 1 requirements (O.2) so you can describe it in your own
  Security Architecture document rather than reverse engineer it. It is a
  mapping to published requirements, not a conformance claim.

### Repository

- **Automated dependency scanning (SPEC-018).** Dependabot covers the `service/`
  npm tree, the root Composer tree and GitHub Actions; a weekly, deliberately
  **non-blocking** `audit` workflow additionally reports advisories that have no
  fix available, which Dependabot cannot act on and which would otherwise go
  unseen. Before this, the only scan this repository ever had was one run by
  hand during an unrelated version bump. `SECURITY.md` states the remediation
  policy for CRITICAL and HIGH.

## [0.5.2] - 2026-08-06

A service-side release. **No change to `src/` or `config/`**, so the installed
Composer package behaves exactly as 0.5.0 and 0.5.1 did — `composer update`
changes nothing you can observe. Update the signing service from a clone of the
repository.

### Service (requires `git pull` + `docker compose up -d --build`)

- **`MAX_BODY_SIZE` now defaults to 20 MB, was 50 MB (SPEC-017).** ⚠️ **A body
  above the limit is now refused with 413.** Base64 inflates an asset by a
  third, so 20 MB of body carries roughly a 15 MB image — well above the
  11.4 MB a 2000×2000 PNG of incompressible pixels measures. If you sign larger
  assets, raise `MAX_BODY_SIZE`, and raise the container's memory with it.

  The old default was a hazard. Measured at the concurrency cap against an idle
  baseline of 17.6 MiB, a signing request costs about **7× the asset** in
  memory — not the "roughly four copies" previously documented. At 50 MB that
  meant a ~37 MB asset and a peak near 1 GB, in a container many people would
  give 512 MB. The concurrency cap cannot help: the body is buffered *before*
  any limit is consulted.

  `GET /health` now reports `max_body_bytes`, and the README documents the
  measured multiplier with the sizing formula, so the limit can be computed for
  a given container rather than guessed.

### Fixed

- **The correlation id is assigned before the body is parsed.** A request that
  failed to parse — oversized, or malformed JSON — was answered with no
  correlation id, which is exactly when a caller most needs one to quote.
- **Body-parser failures are handled and recorded.** They previously fell
  through to express's default error page with nothing written to the audit
  trail. They now return 413 or 400 with the correlation id, and are recorded.

## [0.5.1] - 2026-08-05

A service-side release. **No change to `src/` or `config/`**, so the installed
Composer package behaves exactly as 0.5.0 did — `composer update` changes
nothing you can observe. (`composer.json` differs by one line: a help string in
`scripts-descriptions`, corrected because it named the wrong test group.)
Update the signing service from a clone of the repository.

### Service (requires `git pull` + `docker compose up -d --build`)

- **Rate limiting and concurrency bounds (SPEC-015).** The service accepted
  unbounded concurrent work — no rate limit, no cap in flight, no request
  timeout — against a signing path that holds roughly four copies of the asset
  at once. It was the cheapest denial of service available against the one
  process holding the signing key, and a misconfigured queue worker did it by
  accident. `/v1/sign` now answers **429** with `Retry-After` past a per-token
  rate limit or the concurrency cap, and signs nothing.

  It **refuses rather than queues**: the PHP client bounds a request at 10
  seconds, so a queued request would time out client-side while still holding a
  slot server-side — the caller has given up and the service is still paying for
  it.

  Limits are **on by default** (`MAX_CONCURRENT_SIGNS=4`,
  `RATE_LIMIT_REQUESTS=60` per minute); a protection that ships off is one
  nobody turns on. Setting one to `0` disables it explicitly, and `GET /health`
  reports that.

- **`GET /health` reports saturation.** Signing does not block the event loop —
  six concurrent signatures complete in roughly the time of two — so a saturated
  instance answered `/health` exactly as fast as an idle one, and an
  orchestrator could not tell them apart. It now reports `in_flight` and the
  effective `limits`.

- **Stalled connections are closed.** A client that announced a body and never
  sent it held its slot indefinitely. Node's `requestTimeout` does *not* cover
  that case — reproduced with no framework involved — so the socket inactivity
  timeout is now set as well.

## [0.5.0] - 2026-08-05

The first release since 0.4.0 to change `src/`, and the first to change
behaviour a caller can depend on — see **Upgrading** below before taking it.
Alongside that, the signing service gains the three controls a provenance system
needs and did not have: it constrains what it will attest to, records every
signing request, and can verify a certificate against a trust list.

### Upgrading

**This release changes what `isTrusted()` answers.** No code has to change to
compile — nothing was removed or resigned — but the verdict is stricter, and
deliberately so. Composer will not hand you this automatically: a `^0.4`
constraint does not resolve to 0.5.0, so the upgrade is opt-in.

**Do this:** search your code for `isTrusted()`. If you use it as a gate,
confirm you actually meant *"the certificate chained to a trust list"* and not
*"this file looks fine"* — the two used to be the same answer in cases where
they should not have been.

The verdict only ever moves **towards** untrusted, so nothing that was refused
before is admitted now; no upgrade weakens a check. What changes is that these
stop passing, all of which passed before:

- an asset carrying **no C2PA data at all**
- a **revoked, expired or otherwise invalid** signing certificate
- an **`Invalid`** manifest
- any manifest store reporting no status codes

For a normal signed asset read through a service without trust settings,
`isTrusted()` was already `false` and stays `false` — that path is unchanged.

**If you need it to say `true`**, configure the signing service with trust
settings (`CONTENTAUTH_TRUST_SETTINGS`, also in this release). Without them
`isTrusted()` is `false` by design, and `isSignatureValid()` is the verdict that
carries meaning.

**If you were checking the Article 50 marking**, prefer the new
`isVerifiedAiGenerated()` over a bare `isAiGenerated()`: the latter reports what
a manifest *claims* and answers for a tampered manifest too.

### Changed

- **BEHAVIOUR — `ManifestReport::isTrusted()` now fails closed (SPEC-013).** It
  was defined negatively — true unless the reader reported
  `signingCredential.untrusted` — so absence of evidence read as evidence of
  trust. **An asset carrying no C2PA data at all answered `true`**, meaning
  `if ($report->isTrusted())` used as a gate admitted every unsigned file. The
  empty report was the clearest case, not the only one: because the definition
  named exactly one status code, a **revoked or expired certificate**, an
  **`Invalid` manifest**, and any store with no codes at all also answered
  `true`.

  It is now `validation_state === Trusted` — trust must be positively
  established. Callers relying on the old behaviour will see `false`.

  Note that trust depends on the signing service being configured with trust
  settings (`CONTENTAUTH_TRUST_SETTINGS`, SPEC-014). Without them `isTrusted()`
  is `false` **by design, not by failure**, and `isSignatureValid()` is the
  meaningful verdict — both are now documented at the call site.

### Added

- **`ManifestReport::isVerifiedAiGenerated()`** — the Article 50 marking *and*
  a signature that checked out, so the safe check is also the short one to
  write. `isAiGenerated()` reports what a manifest **claims** and answers for a
  tampered or unverifiable manifest too; the README example now shows the
  verified form. Deliberately does not require `isTrusted()`, since trust
  depends on deployment configuration the library cannot see.

### Security

- **The signing service now publishes on `127.0.0.1` only** (requires `git pull`
  + `docker compose up -d --build`). `docker-compose.yml` used `"3000:3000"`,
  which publishes on `0.0.0.0` *and* `[::]` — so the one process holding the
  signing key was reachable from every network that could route to the host,
  over plain HTTP, meaning the bearer token crossed the wire in the clear. It is
  now `"127.0.0.1:3000:3000"`.

  **This can break an existing deployment, deliberately.** If you reach the
  service from another host, it will stop responding after this update, with no
  error explaining why — the connection simply will not establish. That is the
  intended outcome: the correct fix is TLS termination plus a restricted network
  path in front of the service, not widening the port binding again.

- **Documented that `CONTENTAUTH_API_KEY` carries the authority of the signing
  key.** Anyone who can call `/v1/sign` can have assertions signed by your
  certificate; the service cannot distinguish an authorised caller from a stolen
  token. Rotate and scope it as you would a key. The service now constrains
  *what* it will attest to (SPEC-011, below) and records every request
  (SPEC-012, below), but neither can tell an authorised caller from a stolen
  token — only rotation and scoping can.

- **Documented that the read-side getters report claims, not verdicts.**
  `isAiGenerated()`, `signer()` and `digitalSourceTypes()` describe what a
  manifest asserts and do not imply the signature checked out — gate on
  `isSignatureValid()` (and `isTrusted()` where trust matters) before acting on
  a credential. Documentation only; no behaviour change in this entry.

### Service (requires `git pull` + `docker compose up -d --build`)

- **Audit logging for every signing request (SPEC-012).** The service kept no
  record of what it signed. If a fabricated credential carrying your certificate
  ever surfaced, you could not answer *did we sign this, when, at whose
  request?* — and without that, every credential ever issued under that
  certificate becomes suspect. Each `/v1/sign` request now writes one line of
  JSON to stdout, for accepted **and** refused requests: input and output
  SHA-256, size, mime type, `creator_name`, assertion labels,
  `digitalSourceType`s, whether a timestamp was applied, and the outcome.

  Records are built from digests and summaries, never payloads. The token, key
  material, the base64 content, the signed bytes and full assertion data are
  never written; the caller is identified by a salted one-way `token_id`, and
  caller-supplied strings are length-capped.

  Responses now carry an `X-Correlation-Id` header (and `cid` in error bodies).
  **Service errors return a generic message instead of the underlying error
  text**, which used to leak temp-file paths and library internals into
  client-side exceptions — quote the `cid` and the detail is in the record.

  If the audit write fails the request still succeeds — a logging outage must
  not become a signing outage, or anyone able to break the write could stop all
  signing — and `GET /health` reports `audit_degraded` until restart, so the
  loss is visible rather than silent.

- **The service now constrains what it will attest to (SPEC-011).**
  `extra_assertions` went into the builder with no validation beyond "is it an
  array", so a caller with a valid token could have any structure signed by your
  certificate — an AI image was signed as a Canon EOS R5 capture, and c2patool
  reported it `Trusted`. `/v1/sign` now returns **400**, signing nothing, for:
  more than one `c2pa.actions` assertion (two are contradictory and which one a
  verifier honours is undefined), too many assertions, an assertion that is too
  large or too deeply nested, an entry that is not an object or carries no
  usable label, and a `creator_name` that is not a bounded string. Tunable via
  `MAX_ASSERTIONS`, `MAX_ASSERTION_BYTES`, `MAX_ASSERTION_DEPTH`,
  `MAX_CREATOR_NAME`.

  **The library's own path is unaffected** — `ManifestBuilder` emits exactly one
  well-formed actions assertion, well inside every limit.

  The service still takes **no position on `digitalSourceType`**. Requiring
  `trainedAlgorithmicMedia` cannot make an attestation truer — it can be
  verified no better than a camera-capture claim — and would exclude the
  authenticity use case entirely. Deployments whose certificate exists solely to
  mark AI content can opt in with `REQUIRE_AI_MARKING=true`; `GET /health`
  reports the effective policy.

- **Trust-list verification in `/v1/read` (SPEC-014).** The service read
  manifests with no settings, so c2pa-rs never checked the signing certificate
  against a trust list: every signed asset came back `Valid` with
  `signingCredential.untrusted`, whatever certificate signed it, and
  `ManifestReport::isTrusted()` could never be `true`. Set
  `CONTENTAUTH_TRUST_SETTINGS` to a c2pa settings document to switch
  verification on — Docker Compose mounts the bundled **test** anchors ready to
  use, and `GET /health` now reports `trust_verification`.

  **The default is unchanged**: no settings configured means exactly today's
  behaviour, so no deployment shifts on upgrade.

  The service **refuses to start** on a settings document it cannot use, and
  that includes one which parses but could never verify — `verify_trust` without
  any `trust_anchors` or `allowed_list` verifies nothing *silently*, producing
  reads indistinguishable from having configured nothing. Failing at startup is
  what stops an operator believing trust is on when it is not.

- **The signing-service image is now built reproducibly.** The Dockerfile copied
  only `package.json` and ran `npm install`, so every transitive dependency was
  re-resolved to whatever was newest-satisfying at build time and
  `package-lock.json` was ignored entirely — builds were not reproducible, and a
  security pin recorded in the lockfile never reached the image. It now copies
  the lockfile and runs `npm ci --omit=dev`, installing exactly the locked tree
  and failing the build if lockfile and `package.json` have drifted apart.
  Verified with a `--no-cache` rebuild: identical dependency versions, and
  signing, read-back, the TSA timestamp path and `bin/verify.sh` all unchanged.
  No functional change to the running service.

## [0.4.3] - 2026-08-05

A compatibility and maintenance release. **No change to `src/`**, so the
installed Composer package is functionally identical to 0.4.0–0.4.2: no API
change, no behaviour change, nothing to migrate. What changes is the range of
Laravel versions this package is *tested* against, and the signing service in
the repository.

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

[Unreleased]: https://github.com/provemark/content-credentials/compare/v0.5.3...main
[0.5.3]: https://github.com/provemark/content-credentials/releases/tag/v0.5.3
[0.5.2]: https://github.com/provemark/content-credentials/releases/tag/v0.5.2
[0.5.1]: https://github.com/provemark/content-credentials/releases/tag/v0.5.1
[0.5.0]: https://github.com/provemark/content-credentials/releases/tag/v0.5.0
[0.4.3]: https://github.com/provemark/content-credentials/releases/tag/v0.4.3
[0.4.2]: https://github.com/provemark/content-credentials/releases/tag/v0.4.2
[0.4.1]: https://github.com/provemark/content-credentials/releases/tag/v0.4.1
[0.4.0]: https://github.com/provemark/content-credentials/releases/tag/v0.4.0
[0.3.0]: https://github.com/provemark/content-credentials/releases/tag/v0.3.0
[0.2.1]: https://github.com/provemark/content-credentials/releases/tag/v0.2.1
[0.2.0]: https://github.com/provemark/content-credentials/releases/tag/v0.2.0
[0.1.0]: https://github.com/provemark/content-credentials/releases/tag/v0.1.0
