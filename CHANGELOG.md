# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **On the early release cadence.** Versions 0.5.x through 0.9.x were tagged
> between 5 and 7 August 2026 — an intensive build-out phase in which the
> security review, the media-type work and the Article 50 marking all landed in
> quick succession. The pace reflects that phase and not instability in what each
> release contained.
>
> Two things are worth knowing when reading those entries. **0.5.1 through 0.5.3
> changed no application code at all** — they were service and documentation
> releases; `src/` was unchanged from 0.5.0 to 0.6.0. And the signing service is
> **not part of the Composer package** (`service/` is `export-ignore`d), so every
> service-side change reaches you through `git pull` and a rebuild rather than
> through a Composer update, whether or not it carries a tag.

## [Unreleased]

### Fixed

Twelve findings from a review of the 0.10.0 release range. Most are corrections
to that release's own work.

- **`AUTH_FAIL_LIMIT=0` silently disabled the audit trail for failed
  authentication**, not just the rate limit. Measured: three failed
  authentications produced zero records while `/health` counted three. The
  window used for de-duplicating records was read off a rate-limit bucket that
  the limiter never creates when it is switched off, so every comparison was
  `null !== null`. The window is now computed from the clock, independent of
  whether a budget is in force. `0` disables the limit, as documented, and
  nothing else.
- **An actions entry with no action verb reached the engine.**
  `{actions:[{}]}` and `{actions:[{action: 7}]}` both answered **500** after a
  real signing attempt; they are now refused with a named **400**, the same
  treatment SPEC-029 gave the other malformed shapes.
- **A client bound after the first resolution was ignored.** Memoising the HTTP
  client froze the "did the application bind its own?" decision at whichever of
  the signer and reader resolved first. An application-bound client now wins on
  every resolution; only the client this package builds itself is memoised.
- **`SignAssetJob` matched non-retryable failures by exact class**, so a
  subclass — or a deterministic exception added later to one of those
  hierarchies — was retried three times with the 10/60/300s backoff. Now
  `instanceof`.
- **Startup names a permission problem instead of throwing a stack.** Since the
  container dropped to an unprivileged user, the likeliest failure reading key
  material is that a bind-mounted key kept its host ownership and is unreadable
  by uid 1000. The error now says so.
- **The `/tmp` tmpfs is 128m rather than 256m.** tmpfs pages are charged to the
  same memory cgroup as `mem_limit`, and the ~650 MiB figure that limit is sized
  from was measured before `/tmp` was in RAM.
- **One rule decides the audited `event` field.** Three handlers used two
  different tests, so `/v1/readback` was recorded as `read` by one and `sign` by
  the others.
- Smaller: `rejectActionsShape()` no longer assumes its caller validated the
  label; `illuminate/queue` is listed in `suggest` alongside `illuminate/support`;
  `mediaTypeFromPath()` derives its refusal message from the helper added for it
  rather than restating the list; `ExtC2paReader` builds its settings once at
  construction, which also moves the fail-closed trust check to wiring time.

### Changed

- **Documentation and test corrections.** A sentence in the service page read
  "the bearer check itself runs before authentication", which is nonsense — the
  bearer check *is* the authentication — and it had been written that way to
  satisfy a substring assertion, which then froze it. Both are fixed. A guard
  test that asserted only inside a `catch` block, and so passed if the guard ever
  stopped throwing, now fails explicitly. `bin/check.sh` names its log with the
  pid: `date +%N` is a GNU extension that emits a literal `N` on a stock macOS
  rather than failing, so the fallback never fired and two failures in the same
  second overwrote each other.

### Service (requires `git pull` + `docker compose up -d --build`)

- **`@contentauth/c2pa-node` 0.8.1 → 0.8.3**, which carries **c2pa-rs 0.90.4 →
  0.90.5** (confirmed from the running container, not the changelog:
  `org.contentauth.c2pa_rs` reads `0.90.5`). 0.90.5 fixes an integer-underflow in
  JUMBF description-box parsing (`read_desc_box`, c2pa-rs #2334) that a crafted
  manifest could reach on any read path. Measured on both engines before and
  after the bump: on the shipped **release** builds — which do not enable
  `overflow-checks` — the underflow wraps and is caught downstream, so the
  crafted asset produces a handled `HTTP 500` with an audit record (service) or a
  catchable `C2paException` (extension), never a process crash. The upstream
  "panic" is a debug-build behaviour; this is not an exploitable denial of
  service in our configuration, and the bump is defence in depth. The single
  `c2pa.actions.v2` assertion, the async TSA timestamp, and every error path
  (400/401/413/429) were verified unchanged; the timestamped PNG is byte-for-byte
  the same size (55,478). c2pa-node 0.8.2 brought the engine bump; 0.8.3 adds
  `updateActions` builder methods that our path does not use.
- The vendored **c2patool** used by `bin/verify.sh` moved 0.27.3 → 0.27.7 (not in
  the repository; it is gitignored). It verifies existing signed assets with
  trust on unchanged.

## [0.10.0] - 2026-08-08

Everything a review found, closed. Reading the package as an outsider produced
twelve findings: three were defects in what the signing service would attest to
or how much it would do before asking who was asking, four were places the client
layer described itself inaccurately, and the rest were runtime and deployment.
All twelve are here, each with a spec and a measurement behind it.

**The two largest fixes are service-side, and `service/` is not in this package.**
They reach you through `git pull` and a rebuild, not through this update. What
does arrive by Composer is the client work: bounded read errors, one shared HTTP
client, a trust-anchor post-condition, a queue job that stops retrying what cannot
succeed, and a stability policy that says what all of it promises.

### Fixed

- **The signing service now validates the actions structure it reads**
  (SPEC-029). SPEC-011 bounded the assertion *envelope* — count, size, nesting
  depth, label — and never the one structure the service then walks, so four
  malformed shapes got past it with a valid token:

  | `data.actions` | Was | Now |
  |---|---|---|
  | a non-iterable value | 500, no named constraint | **400** |
  | a non-array value | 500, after a real signing attempt | **400**, nothing signed |
  | absent | 500, after a real signing attempt | **400**, nothing signed |
  | an empty array | **200 — signed** | **400**, nothing signed |

  The last row is why this is filed as a fix rather than a limit. An empty
  actions array was the one malformed shape that produced a *signature*, and the
  signed asset cannot be read by the signing service (c2pa-rs 0.90.4),
  `c2patool` 0.27.3 or `ext-c2pa` (c2pa-rs 0.89.0) — all three answer
  `No Action array in Actions`. The certificate was being spent on an artefact
  no verifier can parse.

  **Sending no actions assertion at all is unchanged and still permitted**
  (SPEC-011 settled "at most one, not required"): that manifest signs and reads
  back `Invalid` with `assertion.action.malformed`, which is a verifier
  correctly reporting a claim-v2 rule.

  Service only — no change to `src/` or `config/`, and no change for a caller
  using `ManifestBuilder`, which has always emitted the correct shape.

- **Four places the client layer said something it did not do** (SPEC-032).

  - **The artisan commands advertised three formats and accepted fifteen.**
    `content-credentials:sign` described its input as "the source image
    (.png/.jpg/.jpeg)" and had done since SPEC-021 added audio and video. Both
    commands now derive that list from the extension map they actually use, and
    describe an *asset* rather than an image.
  - **The signer and the reader each built their own HTTP client**, so a
    sign-then-verify round-trip opened connections from two pools. They now share
    one. It is memoised on the service provider rather than bound as
    `ClientInterface` in the container: binding a global interface from a library
    would hand our client, and our timeouts, to anything else that resolves it.
    An application that binds its own still wins, unchanged.
  - **`ExtC2paReader` configured trust anchors and never confirmed they took.**
    It now asserts the extension reports them as applied and throws
    `TrustAnchorsNotAppliedException` if not. Measured against ext-c2pa v0.1.0:
    garbage PEM is accepted by the setter and then fails loudly at read time, so
    what this guards is the setter ceasing to take effect — after which every
    asset would read as untrusted while trust appeared configured.
  - **`SignAssetJob` retried failures that cannot succeed.** With `tries = 3` and
    a `[10, 60, 300]` backoff, an oversized asset or a media-type mismatch slept
    up to six minutes to fail identically three times. Those now fail
    immediately; transport failures, 429 and 5xx are still retried.

  `illuminate/queue` joins `require-dev` (and the CI matrix) for
  `InteractsWithQueue`. It is not a runtime dependency and consumers are
  unaffected.

- **A failing read could put 32 MiB into one log line** (SPEC-031). The client
  caps the service's own error text before copying it into an exception —
  whatever answers on that URL controls that string — but the cap existed in
  `SigningServiceSigner` only. `SigningServiceReader` carried the identical
  method without it, so `ReadFailedException` was bounded only by
  `max_response_bytes` (32 MiB since 0.9.0).

  SPEC-025's scope covered "capping the service error text copied into an
  exception"; its acceptance criterion named `SigningFailedException`, and the
  implementation followed the criterion. Both clients now route through one
  `ServiceError` helper, so there is no second copy to fix next time.

  The same change makes truncation **character-wise**. It used `substr()`, which
  cuts by bytes, so a UTF-8 message capped at byte 256 could end mid-codepoint
  and hand an invalid byte sequence to a log pipeline. No new dependency:
  `preg_match` with `/u` gives character semantics, and the input is valid UTF-8
  by construction because `json_decode()` rejects anything else.

- **The signing service now authenticates before it parses a body** (SPEC-030).
  Every budget the service has — SPEC-015's signing limits, SPEC-024's read
  limits — is spent per token, and the body parser ran *before* the token was
  checked. So an oversized body with an invalid token was answered **413**,
  which only the parser can produce, and sixty invalid-token requests produced
  sixty 401s and zero 429s: the unauthenticated path had no budget at all.

  Measured, eight concurrent 21 MB unauthenticated requests against a 17.3 MiB
  idle baseline: the burst cost **+37.1 MiB** before and **+9.5 MiB** after. The
  residual is the bytes still arriving on the socket — refusing before the parser
  removes the allocation and the parse, not the transfer.

  Repeated failures are now bounded by `AUTH_FAIL_LIMIT` (default 30 per
  window), answered 429 with `Retry-After`. It is one **global** counter rather
  than one per client, because measurement showed per-client keying would
  discriminate nothing in the shipped deployment — every host-side request
  reaches the container as the bridge gateway address — while a caller-controlled
  key would be an unbounded map. `GET /health` reports `auth_failures` as a
  running count, and the audit stream carries at most two records per window, so
  a flood cannot grow an operator's log.

  A body-parser refusal now carries a `token_id`, which it could not before:
  there was no verified caller at that point. "Which client keeps sending 25 MB
  assets" is answerable from the log for the first time.

### Security

- **The service image moves to Node 24 and the container is contained.** Node 20
  left maintenance in April 2026, so the image holding the signing key was on a
  runtime receiving no security fixes — and `npm audit` cannot see that, because
  it audits packages and not the interpreter beneath them.

  The container also no longer runs as root. It runs as the unprivileged `node`
  user, with **all Linux capabilities dropped**, `no-new-privileges`, and a
  **read-only root filesystem**; only a `tmpfs` at `/tmp` is writable, which the
  signing path needs because `builder.sign()` writes the signed asset to a file.
  `mem_limit` is set from the measured saturation figure (~650 MiB) so a runaway
  meets a ceiling rather than the host's OOM killer, and `pids_limit` caps
  process creation. A `HEALTHCHECK` is included, using node's own `fetch` — the
  image has no curl or wget.

  None of this changes what the service does. It bounds what a compromise of it
  could reach, and it is the part of a Generator Product Security Architecture
  document that describes the deployment rather than the code.

### Upgrading

- **`/v1/*` without a valid token now fails on the token, not on the body.** A
  request that previously got 400 (malformed JSON) or 413 (oversized) with a bad
  or missing token now gets **401**. If your monitoring probes `/v1/sign` without
  credentials and asserts a specific status, it needs updating. Anything sending
  a valid token is unaffected.
- **Repeated authentication failures are now rate limited** (`AUTH_FAIL_LIMIT`,
  default 30 per minute, `0` disables). A deployment that legitimately produces
  many failed authentications — a misconfigured client during a key rotation, for
  instance — will meet 429 where it met 401. `GET /health` reports the budget and
  the running failure count.
- **`docker cp` into the service container no longer works**, because its root
  filesystem is read-only; Docker refuses with "container rootfs is marked
  read-only" whatever the destination. Pipe instead:
  `docker exec -i <container> sh -c 'cat > /tmp/file' < local-file`. This
  affects tooling and debugging scripts, not the service itself.

### Added

- **A stability and support policy** ([`docs/stability.md`](docs/stability.md)).
  The package had no statement of what it promises not to break: 44 of its 48
  classes were implicitly public API, the supported Laravel range existed only in
  the CI matrix, and there was no deprecation policy beyond one docblock.

  The page states what counts as public API and what is `@internal`, that the
  supported range is PHP 8.3–8.5 and Laravel 11–13, that a deprecated method
  raises no runtime notice and is removed no earlier than a major, and what is
  and is not a breaking change — including the reminder that adding an enum case
  is additive for Composer and still breaks an exhaustive `match` with no
  `default` arm.

  Two caveats are stated rather than left implicit. **`ExtC2paReader`'s contract
  is covered; its continued operation is not** — no type from the extension
  appears in any of its signatures, so an upstream API change breaks our
  implementation rather than your code, but we cannot promise the timing of a
  catch-up. And the signing service is not in the Composer package at all, so its
  changes arrive through `git pull` rather than through a release.

  `ReaderInterface` and `SignerInterface` are stated to be contracts you program
  **against**, not extension points: adding a method to either is not a breaking
  change under this policy. Only the readers and signer in this package implement
  them, and the realistic third-party implementation is a test double, which
  breaks loudly and is fixed in a line. Reserving that room now means a future
  capability method — needed only if a media type ever becomes readable but not
  signable — would not have to wait for a major.

  It also records what 1.0 would require, which is deliberately not feature
  completeness: **real use by someone other than the maintainer**, and nothing
  else. `ExtC2paReader` is explicitly *not* a condition: hanging our own versioning on another project's roadmap buys
  nothing, given that its contract is insulated, the feature is opt-in and off by
  default, and CI now pins the version it tests against.

- **The README records the listing on the Content Authenticity Initiative's
  [community resources](https://opensource.contentauthenticity.org/docs/community-resources/)
  page**, where this package appears as the PHP library under *Related
  projects*. The line says explicitly that a listing is not a conformance
  claim, and points at [Going to production](docs/production.md) for why no
  library can appear on the Conforming Products List. Documentation only — no
  change to `src/`, `config/` or behaviour, though the dist is not
  byte-identical because `README.md` ships in it.

### Changed

- **CI pins the `ext-c2pa` version it tests against, and verifies the pin.** The
  `ext-c2pa` profile ran `pie install ericmann/ext-c2pa` unpinned. That profile
  exists to run SPEC-019's equivalence check, which is the alarm for the two
  reader engines drifting apart — and an alarm whose input version can change
  without anyone deciding is not an alarm: an upstream release would either turn
  it red for a reason nobody caused, or green against an engine nobody meant to
  test. Now pinned to 0.1.0, with the loaded version asserted afterwards, because
  a pin is not evidence until it is checked.

- **A failing `composer check` now keeps its own output.** The script calls
  `bin/check.sh`, which runs the sequence, tees it to `out/check-<stamp>.log`
  and keeps that file **only when the run fails**; a green run leaves nothing
  behind. The sequence itself moved unchanged to `composer check:run`, so what
  green *means* is identical — only what survives a red run changed.

  Built for a specific reason: an intermittent test failure has been seen five
  times and reproduced zero times, and four of those five the output was lost to
  a pipe or to a confirming re-run. A habit that has failed four times will fail
  a fifth, so the fix is mechanical rather than behavioural.

  **Nothing changes for consumers of the package.** Composer runs scripts only
  for the root package, never for a dependency, so these entries are inert once
  installed. Note that `/bin` is `export-ignore`d: the dist carries the
  `composer.json` entry but not `bin/check.sh` itself. That is deliberate —
  shipping developer tooling to make an inert path resolve would be the worse
  trade.

## [0.9.1] - 2026-08-07

Two defects found by reviewing 0.9.0 the same day, both in the same place: what
the signing service refuses to attest to. **Service-side only — no change to
`src/`, `config/` or the public API.**

### Fixed

- **The service signed a manifest that validates as `Invalid` (SPEC-028 AC13).**
  A `/v1/sign` request whose `extra_assertions` supplied a `c2pa.opened` action
  answered **200** and returned a signed asset reading
  `validation_state: Invalid` with `assertion.action.ingredientMismatch` — our
  certificate spent on an asset no verifier accepts. Since 0.9.0 that action
  belongs to the service: c2pa-rs inserts it under the edit intent with a hash
  over the ingredient assertion it builds, which a caller cannot compute, so a
  second one can never be linked. Now refused with 400 and audited.

  The PHP client could not produce this — its builder never emits `c2pa.opened`
  — but the service is a separate HTTP surface and carries its own guards, which
  is the premise of SPEC-011.

- **Refused signing requests recorded no parent asset (SPEC-028 AC8).** The
  criterion reads "accepted **or refused**"; `parent_bytes` and `parent_sha256`
  were written on the success path only, so a refusal — exactly when an auditor
  most wants to know what was submitted — carried neither. Both fields are now
  on the refusal record too, decoded defensively because the parent may be
  absent or malformed at that point.

- **The 0.9.0 changelog entry had the documentation split filed under
  *Upgrading*.** It is a `Changed` note; three sections were inserted above it
  and orphaned it. Documentation only.

## [0.9.0] - 2026-08-07

The second half of Article 50(2). Until now this package could mark content that
was *generated* by AI and not content that was *manipulated* by it — one sentence
in the law, two entirely different manifests in C2PA, and only one of them built.

### Added

- **Marking content that was *manipulated* with AI (SPEC-028).** Article 50(2)
  covers content that is "generated **or manipulated**", and only the first half
  was supported. `ManifestBuilder::forAiManipulated()` marks the editing case,
  and the two remaining editing terms — `algorithmicallyEnhanced` and
  `humanEdits` — build through `forSourceType()`. SPEC-026 refused all three,
  because C2PA records an edit as `c2pa.opened` + a `parentOf` **ingredient** +
  `c2pa.edited`, which this package could not build; it can now.

  The consequence for callers is one extra argument, **the original asset**:
  the ingredient is a hash binding over its bytes, so a filename or a digest
  cannot stand in for it.

  ```php
  $signed = ContentCredentials::sign($edited, $manifest, parent: $original);
  ```

  Omitting it raises `MissingParentAssetException`, and supplying one for a
  manifest that marks creation raises `UnexpectedParentAssetException` — both
  before any request is sent. Neither is pedantry: c2pa-rs signs both of those
  shapes without complaint and reports the result `Valid`.

  A signed original is carried into the result, so provenance is preserved
  automatically. Measured: a chain of edits grows by about **90 KB per
  generation**, linearly. Peak memory is **4.6×** the two assets together, so
  four concurrent manipulations of the largest admissible pair peak near
  245 MiB — below the ≈420 MiB that four maximum-size single-asset signings
  cost, because the parent is hashed rather than signed. `MAX_BODY_SIZE` needed
  no change.

### Changed

- **`bin/verify.sh` recognises both Article 50 markings.** It tested for
  `trainedAlgorithmicMedia` alone, so a correctly marked *manipulated* asset was
  reported as `AI Art.50 mark : FAIL`. It now names which of the two it found.
  Repository tooling only — nothing in the installed package.

- **The documentation is split across pages, and now ships with the package
  (SPEC-027).** `README.md` had grown to 866 lines in one column; it is 244 now
  — requirements, quickstart, verifying, development, security — ending in a map
  to five pages under `docs/`: usage, what you can mark, choosing a reader,
  running the signing service, and going to production.

  `docs/` is no longer `export-ignore`d, so **the installed package gains a
  `docs/` directory** and the README's links resolve from
  `vendor/provemark/content-credentials/` rather than only on GitHub. No code,
  configuration or behaviour changed, and no documented claim changed: the split
  moved whole sections rather than rewriting them.

### Upgrading

- **`SignerInterface::sign()` takes a third, optional `?Asset $parent`
  parameter.** Calling code is unaffected. **Implementing** code is not: a class
  implementing `SignerInterface` must add the parameter, or PHP raises a fatal
  error for an incompatible declaration. The same applies to
  `ContentCredentialsManager::sign()`.
- **`UnsupportedSourceTypeException` is no longer thrown.** The class is kept
  indefinitely and stays exactly where it was, so `catch` blocks keep compiling;
  it simply has no remaining throw site now that every declared source type can
  be built. Nothing to change unless you relied on the refusal itself.

## [0.8.0] - 2026-08-07

The largest release since 0.5.0, and the first in a while to change `src/`
substantially. Three things happened, in this order: what the engine could
already do was measured and shipped, the API was corrected where that widening
made it wrong, and a full review of the codebase closed the gaps it found.

**Media types went from two to thirteen.** PNG and JPEG were never a c2pa-rs
limitation — they were hand-written allow-lists nobody had re-examined since the
spike. Every added type was measured signing, reading back `Valid`, keeping the
Article 50 marking, and confirmed with `c2patool`.

**What you can claim went from one thing to three.** Alongside
`trainedAlgorithmicMedia` you can now mark a mix containing generative AI, and
purely algorithmic output — the latter being a *negative* claim about AI, which
is the useful part.

**Two paths that were unbounded are bounded**, and two defects in the signing
service are fixed. Both were found by reviewing code nobody had read end to end
in a while, rather than by anything failing.

Additive for Composer, but not free: **four upgrade notes below**, one of which
(an exhaustive `match` over `MediaType`) can throw at runtime in code that
compiles today.

### Upgrading
- **`max_response_bytes` drops from 96 MiB to 32 MiB.** It was documented as
  "headroom over the service's 50 MB request cap", and SPEC-017 lowered that cap
  to 20 MB — so the guard permitted about five times what a correct service can
  send, and sat far above the `memory_limit = 128M` many deployments run. If you
  raised `MAX_BODY_SIZE` on the service, raise `CONTENTAUTH_MAX_RESPONSE_BYTES`
  and `CONTENTAUTH_MAX_REQUEST_BYTES` with it.

- **Verification traffic is now rate-limited.** If a deployment reads more than
  240 assets per minute per token, or runs more than 4 verifications at once, it
  will start seeing **429** where it never did. Raise `READ_RATE_LIMIT_REQUESTS`
  and `MAX_CONCURRENT_READS`, and raise the container's memory with them.

- **An exhaustive `match` over `MediaType` is no longer exhaustive.** Eleven
  cases were added across SPEC-021 and SPEC-023, so code like `match ($asset->mediaType) { MediaType::Png => …,
  MediaType::Jpeg => … }` with no `default` arm now throws
  `\UnhandledMatchError` the first time it meets a WEBP. Composer sees this
  release as additive and it is — but adding cases to an enum a consumer matches
  on is not free. Add a `default`, or handle the new cases.

### Added
- **Seven more media types (SPEC-021).** This package signed and read PNG and
  JPEG. That was never a c2pa-rs limitation — it was two hand-written
  allow-lists, and they have not been re-examined since v1 scope was set by the
  spike. Now supported, each measured signing, reading back `Valid` and keeping
  the Article 50 marking, and independently confirmed with `c2patool`:

  | | |
  |---|---|
  | images | `image/png`, `image/jpeg`, `image/webp`, `image/avif`, `image/gif`, `image/tiff` |
  | audio | `audio/wav`, `audio/mpeg` (`audio/mp3` accepted as an input spelling) |
  | video | `video/mp4` — see the qualification below |

  This matters beyond convenience: WEBP and AVIF are what the modern web serves
  and what several generators emit, so an application that optimises its images
  could not sign its own output. And Article 50(2) covers audio and video, not
  images alone.

  The artisan commands accept the matching extensions (`.webp`, `.avif`,
  `.gif`, `.tif`/`.tiff`, `.wav`, `.mp3`, `.mp4`), and a running service now
  publishes what it accepts at `GET /health` (`media_types`).

  ⚠️ **`video/mp4` is supported as a container, and bounded to small files.** It
  signs and verifies exactly like an image, but `MAX_BODY_SIZE` (20 MB) and the
  ~7× memory multiplier apply to every media type — and the transport is base64
  in one HTTP body. A 64×64 one-second clip signs fine; a real video does not.
  An oversized body is refused with a **413 that says this**, rather than a
  generic byte count. Streaming or path-based signing is what would change it,
  and that is a separate piece of work.

- **Four more media types (SPEC-023).** SPEC-021 left six formats out as
  unmeasured rather than unsupported. They have now been measured: **SVG, MOV,
  AVI and FLAC** sign, read back `Valid`, keep the Article 50 marking, pass
  `c2patool` and agree across both readers. That makes **thirteen** media types.
  `audio/x-flac` and `video/avi` are accepted as input spellings.

  ⚠️ **Signing SVG needs a warning the other formats do not.** Measured: SVGO
  with its default preset removes the manifest **silently** — the image renders
  identically and a verifier cannot distinguish it from a file that was never
  signed — and any tool that re-serialises the XML leaves a file c2pa-rs refuses
  to parse. Every common bundler runs SVGO with defaults, so sign SVG as a final
  deliverable, not as a build asset. The README carries the detail.

  ⚠️ **Lossless audio is not "short audio".** A few minutes of FLAC approaches or
  exceeds the 20 MB body limit, which the other audio formats rarely touch.

  Four formats stay out, each for its own reason, now documented rather than
  merely absent: **PDF** (c2pa-rs can read but not write it), **WEBM** (no
  handler at all), **DNG** and **JPEG XL** (unmeasured).

- **The read path is bounded (SPEC-024).** `/v1/read` had no rate limit, no
  concurrency cap and no audit record: measured, ten reads all answered 200
  while the sixth sign was refused. SPEC-015 scoped itself to `/v1/sign` and
  never mentioned reading, so this was a gap rather than a decision. It now has
  its own cap and its own budget, reported on `/health` as
  `max_concurrent_reads`, `read_rate_limit_requests` and `reads_in_flight`.

  Budgets are **separate** on purpose: one shared budget would let a
  verification loop consume what an application needs to sign its own output,
  and that failure presents as "signing is broken".

  Measured: reading costs ~3–5× the asset in memory against signing's ~7×, so
  the read cap defaults to the same 4 rather than to something generous. Four
  signs plus four reads of maximum-size assets is roughly 650 MiB.

- **Three things can be marked now, not one (SPEC-026).** `DigitalSourceType`
  had a single case and `ManifestBuilder` hard-coded it. Alongside
  `trainedAlgorithmicMedia` you can now mark:

  ```php
  ManifestBuilder::forSynthetic(MediaType::Png)     // compositeSynthetic
  ManifestBuilder::forAlgorithmic(MediaType::Png)   // algorithmicMedia
  ManifestBuilder::forSourceType($type, $mediaType) // the general form
  ```

  `compositeSynthetic` is a mix containing generative AI; `algorithmicMedia` is
  purely algorithmic with no model and no training data — useful precisely
  because it is a *negative* claim about AI.

  ⚠️ **`compositeWithTrainedAlgorithmicMedia` is not "a composite with AI in
  it".** IPTC defines it as augmentation or enhancement **using** a generative
  model — an edit of something that already existed. C2PA records that as
  `c2pa.opened` + an ingredient + `c2pa.edited`, which this package does not
  build, so asking for it (or for `algorithmicallyEnhanced` or `humanEdits`)
  raises `UnsupportedSourceTypeException` rather than emitting a `c2pa.created`
  action that would claim something false.

  **Reading:** `isAiGenerated()` still means exactly `trainedAlgorithmicMedia`
  and always will — code gates Article 50 decisions on it. The new
  `involvesGenerativeAi()` is the wider question: true for
  `trainedAlgorithmicMedia` and `compositeSynthetic`, false for
  `algorithmicMedia`.

  Capture terms (`digitalCapture` and friends) are deliberately absent: a web
  application receives bytes and cannot know a physical origin, and a signed
  assertion turns hearsay into attestation.

- **The client keeps its own bounds now (SPEC-025).** The service has been
  hardened six times; the client once, and that one bound was sized against a
  limit SPEC-017 replaced. Four changes, all on the PHP side:

  - `max_request_bytes` (new, 15 MiB): an asset too large for the service is
    refused with `AssetTooLargeException` **before** it is base64-encoded.
    Encoding costs ~3.7× the file, so learning the limit from the service's 413
    meant paying for it first — or dying before the answer arrived.
  - `require_secure_transport` (new, off): plain HTTP to anything other than
    loopback sends the API key across a network in clear. That is now a logged
    warning by default, and an exception when this is set. Loopback stays
    silent, because that is the documented deployment.
  - A service error message copied into an exception is capped at 256
    characters. Whatever answers on that URL controls that string, and it ends
    up in your logs.
  - The signed file is written atomically — temporary file plus rename, in the
    destination's own directory — so a crash mid-write can no longer leave a
    truncated file that looks signed.

  The README and the primer now also state what choosing `ExtC2paReader` means:
  it parses untrusted assets **inside the application process**, where the
  service reader keeps that in a separate one. That is the mirror image of
  ADR-0003's key-isolation argument, and worth deciding on purpose.

### Changed
- **`ManifestBuilder::forAiGenerated()` is the entry point (SPEC-022).** The old
  name, `forAiGeneratedImage()`, predates the media types above — it now reads as
  a contradiction for `MediaType::Mp4`. It **keeps working, indefinitely**: it
  delegates to the new name, raises no runtime deprecation, and there is no
  removal planned. Deleting a three-line alias would break working code for
  cosmetics. Only the docblock marks it, so IDEs and static analysis point at
  the new name.

  ```php
  ManifestBuilder::forAiGenerated(MediaType::Mp4)   // canonical
  ManifestBuilder::forAiGeneratedImage(MediaType::Png)  // still fine, forever
  ```

- The service's `400` for an unsupported `mime_type` now names all nine accepted
  types rather than two, and `UnsupportedMediaTypeException` derives its message
  from the enum. Both lists used to be written out by hand, which is how they
  went stale in the first place.

### Fixed
- **A deeply nested assertion crashed past its own guard.** SPEC-011's depth
  check ran *behind* the size check, and the size check is `JSON.stringify`,
  which overflows the stack at ~10 000 levels. Such a request answered 500 with
  an HTML body and wrote no audit record, so SPEC-011's bound and SPEC-012's
  "every request is recorded" held only for payloads small enough not to need
  them. The two checks are now the other way round.
- **Unanticipated errors reached express's default handler**, answering HTML
  with no correlation id in the body and writing nothing to the audit stream.
  There is now a catch-all that audits and answers `{error, cid}` like every
  other refusal.

## [0.7.0] - 2026-08-06

Completes what 0.6.0 started. That release added `ExtC2paReader` to `Core`, but
the Laravel container still bound one reader unconditionally — so a Laravel
application that installed the extension kept getting HTTP from the facade, the
manager, the jobs and the commands. The capability shipped; the way to reach it
did not.

Additive: new public API and two config keys, no behaviour change for anyone who
sets neither. `config/content-credentials.php` gained keys, so republish it
(`--tag=content-credentials-config`) or add them by hand if you have published a
copy.

### Added

- **The Laravel container can bind either reader (SPEC-020).** v0.6.0 shipped
  `ExtC2paReader` in `Core` only, so a Laravel application that installed
  `ext-c2pa` still got HTTP everywhere the container was involved — facade,
  manager, jobs, commands. A `reader` config key now selects it:

  ```dotenv
  CONTENTAUTH_READER=auto            # service (default) | extension | auto
  CONTENTAUTH_TRUST_ANCHORS=/path/to/anchors.pem   # or the PEM contents
  ```

  ⚠️ **`service` is the default, so installing the extension changes nothing
  until you set this.** Deliberate: the two readers carry different c2pa-rs
  versions (0.89.0 and 0.90.4), and an extension installed for an unrelated
  reason must not silently change which engine decides your trust verdicts.

  `extension` throws when the extension is absent rather than falling back to
  HTTP, and an unrecognised mode is refused rather than defaulted — a typo that
  quietly becomes `auto` is the same invisible switch in a different costume.

- **`php artisan content-credentials:read` reports the reader it used**, so
  "which engine produced this report?" is answerable in a bug report.

- **`trust_anchors` accepts PEM contents or a path** (extension reader only).
  Every trust surface underneath takes contents and silently verifies nothing
  when handed a path; this layer absorbs that rather than letting you find it.

## [0.6.0] - 2026-08-06

**The first release since 0.5.0 to change the installed package.** 0.5.1, 0.5.2
and 0.5.3 were service and documentation — `composer update` changed nothing you
could observe. This one adds public API, which is why it is a minor rather than
a patch. A `^0.5` constraint does not resolve to 0.6.0, so nobody gets it
unasked.

Nothing is removed and nothing behaves differently. If you do not install the
extension, this release changes nothing for you.

### Added

- **`ExtC2paReader` — read credentials without running the signing service
  (SPEC-019).** Verification needs no private key, no certificate and no
  service; it needed one until now only because reading and signing shared a
  transport. With [`ericmann/ext-c2pa`](https://github.com/ericmann/ext-c2pa)
  installed (`pie install ericmann/ext-c2pa`), reading happens in-process.

  ```php
  $reader = ExtC2paReader::isAvailable()
      ? new ExtC2paReader($anchorsPem)
      : new SigningServiceReader($client, $factory, $factory, $config);
  ```

  Both implement `ReaderInterface` and return the same `ManifestReport`, so the
  choice is an installation decision rather than an API one. Construction throws
  `ExtensionMissingException` when the extension is absent and deliberately does
  **not** fall back to HTTP — a caller who asked for in-process reading and
  silently got a network call cannot tell.

  Two things to know before depending on it: the extension is at **v0.1.0**, and
  it carries **c2pa-rs 0.89.0** against the service's **0.90.4**. An integration
  test compares both readers accessor by accessor on the same asset; they agree
  today, and that test is what would report it if they stopped.

  **Signing is unaffected and stays with the service.** The extension can sign,
  and this library does not expose that: it would put the private key in the web
  process, which is what this architecture exists to avoid.

### Changed

- **Manifest-store decoding moved to a shared `ManifestStoreParser`.** Behaviour
  is unchanged — the code is `SigningServiceReader`'s former private `parse()`,
  moved rather than rewritten. Both readers now answer from one decoder, so
  there is one definition of "trusted" rather than two places for it to drift.

  Stated plainly rather than filed under "no changes": **`SigningServiceReader`
  itself was edited.** Its public behaviour and constructor are untouched and it
  is `final`, so there is nothing to subclass and nothing a caller can observe —
  but the file did change, and a release note claiming otherwise would be wrong.

### Repository

- **A fourth CI integration profile installs `ext-c2pa`** and runs the
  equivalence check on every push, rather than only where somebody had installed
  the extension by hand. It asserts that the extension actually loaded and that
  the comparison actually passed: run without it, the suite reports
  `9 skipped, 8 passed` and exits 0 — green while testing nothing. The other
  three profiles deliberately keep running without the extension, because AC5 is
  about its absence.

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

[Unreleased]: https://github.com/provemark/content-credentials/compare/v0.10.0...main
[0.10.0]: https://github.com/provemark/content-credentials/releases/tag/v0.10.0
[0.9.1]: https://github.com/provemark/content-credentials/releases/tag/v0.9.1
[0.9.0]: https://github.com/provemark/content-credentials/releases/tag/v0.9.0
[0.8.0]: https://github.com/provemark/content-credentials/releases/tag/v0.8.0
[0.7.0]: https://github.com/provemark/content-credentials/releases/tag/v0.7.0
[0.6.0]: https://github.com/provemark/content-credentials/releases/tag/v0.6.0
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
