# SPEC-042: A pure-PHP reader through provemark/c2pa-verifier

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | implemented                                       |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon, 2026-10-07                      |
| Supersedes | — (extends SPEC-003 reading, SPEC-019 the second reader, SPEC-020 selection, SPEC-040 bounded error text) |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Both readers this package ships cost the host a deployment decision.
`SigningServiceReader` needs a second process to run, secure and monitor;
`ExtC2paReader` needs a native extension the host must build. NOTES Step 24
recorded that neither reaches cheap shared hosting, and that reaching it would
need "a reader in pure PHP (JUMBF, CBOR, COSE, hash binding, via
openssl/sodium)". SPEC-038 proposed a third route through the `c2patool`
binary, and its own *What this does NOT solve* section says a host forbidding
`proc_open` stays out of reach.

That pure-PHP reader now exists as a separate package,
`provemark/c2pa-verifier` (v0.5.0, MIT, requires `php ^8.3`, `ext-openssl`,
`ext-mbstring`). `docs/readers.md` has pointed at it since v0.15.2 (PR #151),
but as a route the caller wires up alone: it returns its own report, not a
`ManifestReport`, so a host that wants it cannot select it through
`ReaderInterface` and loses every accessor this package defines.

The verifier's README states the intended direction: "the library may later add
an adapter to it. The dependency, if any, points from the library to the
verifier, never the other way." This spec is that adapter.

### What makes this cheap, measured before writing

The verifier's own suite already feeds its `toJson()` through this package's
`ManifestStoreParser::fromJson()` (its SPEC-013, on 22 recorded c2patool
outputs). That was measured before the verifier had trust (its M5), so it was
re-measured here on 2026-10-07 against the running service (c2pa-node 0.9.5 /
c2pa-rs 0.90.22, TSA on) and `ExtC2paReader` (ext-c2pa 0.1.0 / c2pa-rs 0.89.0)
with `certs/c2pa-trust.settings.json`, verifier v0.5.0 from source:

- **Eleven of thirteen media types agree accessor by accessor** with both
  readers on every accessor in `spec019Accessors()`: PNG, JPEG, WebP, AVIF, GIF,
  WAV, MP3, FLAC, MP4, MOV, AVI. That includes `isTrusted() === true`,
  `isVerifiedAiGenerated() === true` and `hasTimestamp() === true` on the async
  TSA path — the three accessors the verifier's own check could not cover yet.
- **The verifier needs no network, no binary and no download.** In CI it
  arrives through Composer like any `require-dev` package, so unlike the
  `ext-c2pa` profile it can run in every `check` leg.

### What the measurement found that an adapter must handle

These are the reasons the adapter is more than `fromJson($report->toJson())`.
Each became a criterion below.

1. **TIFF and SVG are not read.** The verifier answers `Invalid` with
   `general.error` ("unsupported file type") and `has_manifest: false`, so
   through the parser `hasManifest()` is `false` for an asset that carries
   credentials. A caller asking only `hasManifest()` would be told "no Content
   Credentials" — a silent wrong answer, the shape this project rates worst.
2. **An unsigned asset reports `validation_state: "Invalid"` with no status
   codes.** Through the parser, `validationState()` is `Invalid`; both other
   readers return `null`. That is a divergence on an accessor in the SPEC-019
   comparison, and on the most common input there is.
3. **The verifier never throws on input; it always returns a report.** Empty
   input, random bytes and a PNG signature followed by garbage come back
   `has_manifest: false` with `general.error`; a signed PNG cut in half comes
   back `has_manifest: true` with `general.error`, because the verifier sets
   the flag once it reaches the store (Amendment 1 — the first measurement did
   not print the flag for that case). Both other readers throw
   `ReadFailedException` on each of these. `ReaderInterface` promises the same
   exception for the same failure.
4. **The `explanation` text is built partly from the asset.** For an
   unsupported type it quotes the first twelve bytes in hex
   (`the file starts with 49 49 2A 00 …`). Hex is printable, but other messages
   may not be, and SPEC-040 already decided that bounding belongs where the
   string enters this package.
5. **The declared media type plays no part.** The verifier takes a stream and
   identifies the container from its bytes: a signed WAV declared as
   `image/png` comes back `Trusted`. The service and the extension use the
   declared type to pick a handler (primer §8). Harmless, but a difference.
6. **Trust settings are the c2patool JSON shape, with one refusal.** Our
   `certs/c2pa-trust.settings.json` (`trust_anchors` + `trust_config`) gives
   `Trusted`; a `trust.anchors[]` entry gives `Trusted`, also with only an
   `allowed_list`; no settings gives `Valid` with `signingCredential.untrusted`.
   A top-level `trust.allowed_list` — what `docs/production.md` teaches for the
   IPTC list — is refused by `TrustSettings::fromJson()` with a
   `TrustException` naming where it belongs. So do invalid JSON and a file
   path where PEM contents are expected.

Two further differences were seen and need no criterion: a byte flipped inside
the PNG manifest chunk is `Invalid` in all three readers, but the verifier says
`general.error` (the chunk CRC) where c2pa-rs says
`assertion.hashedURI.mismatch` — `validationStatusCodes()` is not in the
SPEC-019 comparison, deliberately. And for ISOBMFF the verifier leaves
`c2pa.hash.bmff.v3` out of `assertions()` where c2patool 0.27.22 lists it; that
is a divergence from c2patool inside the verifier, to be fixed there, and
`assertions()` is not compared either.

## Scope

**In scope**

- `C2paVerifierReader`, a third `ReaderInterface` implementation that runs
  `Provemark\C2paVerifier\Verifier\Verifier` in-process and decodes its report
  through `ManifestStoreParser`.
- Trust configuration through a settings file in the c2patool JSON shape.
- The mapping from the verifier's report to the `ReaderInterface` contract:
  empty report, `ReadFailedException`, bounded message.
- Selection: a new value `verifier` in the SPEC-020 reader configuration.
- Extending the SPEC-019 AC2 equivalence comparison to the verifier.
- `provemark/c2pa-verifier` in `require-dev` and `suggest`, never in `require`,
  recorded in an ADR (this repository's rule: no new runtime dependency without
  spec + ADR).
- `docs/readers.md`: the verifier as a selectable route, and a second trust
  settings shape in `docs/production.md` for the allowed-list case.

**Out of scope**

- **Signing.** The verifier cannot sign, and this spec adds nothing to
  `SignerInterface`.
- **TIFF and SVG through this reader.** They are refused (AC3) until the
  verifier reads them; widening is an amendment, triggered by a verifier release
  that does.
- **Media types the verifier reads and `MediaType` does not** (HEIC, fragmented
  DASH, plain text). `Asset` cannot carry them; widening `MediaType` is
  SPEC-021's territory and needs three lists to move together.
- **`FragmentedVerifier`, fetching remote manifests and the verifier's
  `checks_performed`.** A reader answers the questions `ReaderInterface` asks.
  A remote manifest is refused by name (AC4, Amendment 2), never fetched.
- **A new accessor on `ManifestReport`** for anything the verifier reports and
  the others do not (`signature_info.time`, `format`, revocation checked).
- **Changing `auto`.** It keeps resolving to `extension` or `service`
  (AC8).
- **Fixing the `c2pa.hash.bmff.v3` omission.** It belongs to the verifier.

## Behavior

- **AC1 — three readers, one answer**
  - Given a signed asset of each of the eleven types measured above, and this
    reader configured with the same trust settings file the service runs with
    (none in the `defaults` profile, `certs/c2pa-trust.settings.json` in
    `hardened`)
  - When it is read by `SigningServiceReader` and by `C2paVerifierReader`
  - Then every accessor in `spec019Accessors()` agrees, trust-dependent ones
    included; and in the `ext-c2pa` profile the same holds against
    `ExtC2paReader`
  - And at least one value is pinned concretely: under trust,
    `isVerifiedAiGenerated() === true` and the `trainedAlgorithmicMedia` URI in
    `digitalSourceTypes()`. Two readers that both return empty agree about
    nothing.

- **AC2 — no manifest is an empty report, not an error**
  - Given an asset carrying no C2PA data (`tests/Fixtures/fixture.png`)
  - When it is read
  - Then the result is `new ManifestReport(null, null, [], [], null)`:
    `hasManifest() === false` **and `validationState() === null`**, as both other
    readers answer (SPEC-003 D2, SPEC-010). The verifier's `Invalid` for this
    case must not reach the caller. The rule: `has_manifest` false and no
    failure status means an empty report.

- **AC3 — a type the verifier cannot read is refused before it is read**
  *(error path)*
  - Given an `Asset` whose `MediaType` is `image/tiff` or `image/svg+xml`
  - When it is read
  - Then `ReadFailedException` is raised naming the media type and saying this
    reader does not support it, and the verifier is not called. Never an empty
    report: a signed TIFF must not read as "no credentials".
  - The refused set is a constant in the class, not derived at runtime, and a
    test asserts that every other `MediaType` case is accepted, so a new case
    added to `MediaType` without a measurement here fails a test instead of
    slipping through.

- **AC4 — a fault in the file is an exception, as in the other readers**
  *(error path)*
  - Given empty bytes, random bytes, a PNG signature followed by garbage, or a
    signed PNG truncated to half its length
  - When it is read
  - Then `ReadFailedException` is raised, as `ExtC2paReader` and
    `SigningServiceReader` both do for each of these. The rule (Amendment 1):
    no active manifest in the report, with at least one failure status, is a
    failure — whatever `has_manifest` says. The verifier sets `has_manifest`
    once it reaches the store, so a store cut short or with a bad chunk CRC
    reports true while nothing was decoded (measured 2026-10-07).
  - And a byte flipped inside the PNG manifest chunk is `ReadFailedException`
    too. Recorded divergence: there the other two readers return an `Invalid`
    report with `hasManifest() === true`. Neither answer is ever `Valid`; the
    alternative, a report with `hasManifest() === false`, would tell a caller
    asking only that question "no Content Credentials", the shape AC3 refuses.
  - And a file that HAS a manifest and fails validation (a byte flipped in the
    pixel data: `assertion.dataHash.mismatch`) is a report, not an exception:
    `hasManifest() === true`, `validationState() === Invalid`. A broken
    signature is an answer, not a read failure.
  - And a file whose only manifest is remote is `ReadFailedException`,
    naming the URL and saying this reader never fetches. Only the URL is
    bounded (it comes from the file); the explanation after it is fixed text
    and always survives (Amendment 3). The
    verifier reports such a file as `has_manifest: false` with the URL in
    `remote_manifest`, and an empty report would tell the caller "no Content
    Credentials" for a file that declares them (Amendment 2). Measured
    2026-10-07 on `c2pa-rs/cloud.jpg` and an Adobe Photoshop file:
    ExtC2paReader also throws, naming the URL ("must fetch remote manifests
    from url …"); SigningServiceReader fetches the manifest from
    `cai-manifests.adobe.com` and reads `Valid`. Recorded divergence from the
    service, the same shape as the extension's.
  - A file with an embedded manifest AND a remote URL is read from the
    embedded one; the URL changes nothing.
  - And any `\Throwable` escaping the verifier itself is wrapped in
    `ReadFailedException` with the original as `previous`, so a caller can swap
    readers without touching its error handling.

- **AC5 — the message is bounded where it enters**
  *(error path)*
  - Given a failure whose `explanation` exceeds the `ServiceError::bound()`
    limit, or contains bytes that are not valid UTF-8
  - When AC4 raises
  - Then the exception message carries the explanation through
    `ServiceError::bound()`, as SPEC-040 AC5 does for the extension. Making it
    safe to print stays the command's job (`SafeOutput`); the accessors are not
    touched (SPEC-033 AC4).

- **AC6 — trust settings fail at construction, not at the first read**
  *(error path)*
  - Given settings JSON that `TrustSettings::fromJson()` refuses — invalid JSON,
    a path instead of PEM contents, a top-level `trust.allowed_list`
  - When the reader is constructed
  - Then a named exception from this package's reading hierarchy is raised, its
    message carrying the verifier's own explanation (bounded), and no reader
    exists. A misconfigured trust setup surfaces when the reader is wired, as
    with `ExtC2paReader` (SPEC-032).
  - And with no settings the reader is constructed and verifies without trust:
    a signed test asset reads `Valid`, `isTrusted() === false`.

- **AC7 — the package is optional, and its absence is named**
  - Given `provemark/c2pa-verifier` is not installed
  - When `C2paVerifierReader::isAvailable()` is called, it returns `false`; when
    the reader is constructed, a named exception (proposed:
    `VerifierMissingException`, beside `ExtensionMissingException` in
    `Reading\Exception`) says which package to `composer require`
  - Then nothing else in this package changes behaviour: the class is loaded
    only when selected.

- **AC8 — selection stays explicit**
  - Given `CONTENTAUTH_READER=verifier`
  - When the Laravel container resolves `ReaderInterface`
  - Then it is a `C2paVerifierReader`, configured from
    `content-credentials.verifier_settings` (a path, or empty for no trust).
  - And `auto` still resolves to `extension` or `service` exactly as SPEC-020
    defines: installing the verifier for an unrelated reason changes no verdict.

- **AC9 — the equivalence check runs where it blocks**
  - Given the verifier is in `require-dev`, so every CI leg installs it
  - When the `defaults` and `hardened` integration profiles run
  - Then AC1's comparison against the service runs in both, and both are
    blocking (inside `all checks passed`), unlike the `ext-c2pa` profile. This
    is the first reader-equivalence check that can gate `main`.
  - And a guard in the workflow asserts the comparison RAN in each of the two
    profiles, as the SPEC-019 and SPEC-020 guards do: an equivalence test that
    skips is a pass nobody saw. Seen red before it is relied on.
  - The unit-level criteria (AC2–AC7) need no service and run in every
    `check` leg.

- **AC10 — a copy of the asset does not double its memory** (Amendment 3)
  - Given an asset of 32 MiB
  - When it is read
  - Then the reader's own copy of the bytes adds no more than 8 MiB to peak
    memory: the copy goes to `php://temp` with a 2 MiB in-memory limit, and
    spills to the system temp directory above that. Measured 2026-10-08:
    33.4 MB extra with `php://memory`, 1.4 MB with `php://temp`.
  - The spill file lives only for the read and is closed on every exit
    path. A host without a writable temp directory gets
    `ReadFailedException`, not a fatal error.

## Amendment 1 (2026-10-07, approved by Maurice van Loon the same day)

Found while implementing AC4: its stated rule and its own Given disagreed. A
signed PNG cut in half reports `has_manifest: true` from the verifier, so the
rule "`has_manifest` false with a failure" let it through as a report with
`hasManifest() === false` and `Invalid`, where the Given requires an
exception. The pre-spec measurement had printed only the state for that case.
The rule now keys on whether an active manifest was decoded. Problem item 3
is corrected in place, and AC4 gains the manifest-chunk case and the
divergence it creates.

## Amendment 2 (2026-10-07, approved by Maurice van Loon the same day)

Found after the merge of #159, when the three readers were run over 482 files
signed elsewhere (the verifier's own test corpus). Two files declare their
manifest only by URL. The scope had put remote manifests out, but had not said
what the reader does when it meets one, and the mapping turned the verifier's
`has_manifest: false` into an empty report: "no Content Credentials" for a
file that declares them, the silent wrong answer AC3 refuses for TIFF. AC4
now raises instead, as the extension does. Fetching stays out of scope.

## Amendment 3 (2026-10-08, approved by Maurice van Loon the same day)

From the review of everything since v0.15.2. Two findings changed behaviour:
`ServiceError::bound()` was applied to the whole remote-manifest sentence, so a
URL of more than 256 characters cut off the reason ("this reader never fetches
one") and left mostly file-controlled text; and the reader copied the asset
into an unbounded `php://memory` stream, doubling peak memory on the hosts this
reader is for. AC4 now bounds the URL alone, and AC10 is new. Assets above
2 MiB are briefly written to the system temp directory, as PHP already does
for uploads.

## Amendment 4 (2026-10-09, draft — awaiting approval)

The verifier released 0.6.0 on 2026-10-09: the whole of C2PA 2.4 read
against it, five validator rules newly checked, and nine new `StatusCode`
cases (cloud data, the text wrapper, the time-stamp assertion, the original
preservation image). The report's shape and the classes this package uses
are unchanged. The ADR-0007 addendum keeps a host off any verifier minor
nobody measured here, so 0.6 cannot be installed beside this package until
the range moves. This amendment moves it.

**The tested range becomes `^0.6`.** `require-dev` is
`"provemark/c2pa-verifier": "^0.6"` and `conflict` is `"<0.6 || >=0.7"`,
the single-minor form `VerifierVersionRangeTest` enforces. A host on
verifier 0.5 has to move with it: the release that carries this is a minor.
`docs/readers.md` names v0.6.0 as the version measured.

**Measured before writing**, in a scratch copy of `4c57f5b` with only the
two constraints changed and v0.6.0 installed:

- `composer check`: green, 452 passed, 9 skipped (ext-c2pa loaded on this
  machine), deptrac 0 violations. `VerifierVersionRangeTest` passes on the
  new pair.
- AC1 against the local service, `defaults` (no trust,
  `RATE_LIMIT_REQUESTS=1000`): `VerifierReaderEquivalenceTest` 34 passed;
  11 of 11 media types agree with `SigningServiceReader`, and 11 of 11 with
  `ExtC2paReader`.
- AC1 in `hardened` (`CONTENTAUTH_TRUST_SETTINGS`, `REQUIRE_AI_MARKING=true`):
  34 passed, 11 of 11 against the service.
- `VerifierOutcome` keys on whether an active manifest was decoded and on
  the failures' explanations, never on a code's name, so the new codes need
  no mapping.

The CI run of the pull request is the blocking repetition of these: AC9's
guards count the eleven comparisons in both profiles.

No criterion changes. AC1, AC3 and AC9 now name 0.6 where they named the
measured version. AC3's refused set (TIFF, SVG) is unchanged: 0.6.0 reads
neither.

## API sketch

```php
// namespace Provemark\ContentCredentials\Core\Reading;

final class C2paVerifierReader implements ReaderInterface
{
    /** AC3: the media types the verifier v0.5 cannot read, measured 2026-10-07. */
    private const UNSUPPORTED = [MediaType::Tiff, MediaType::Svg];

    public function __construct(?string $trustSettingsJson = null) {}   // AC6, AC7

    public static function isAvailable(): bool;                           // AC7

    public function read(Asset $asset): ManifestReport;                   // AC1–AC5
}
```

The core of `read()`: refuse AC3 types; write the bytes to `php://memory`;
`(new Verifier)->verify($stream, $settings)`; if `hasManifest` is false, return
the empty report (no failure) or raise (failure); otherwise
`ManifestStoreParser::fromJson($report->toJson())`.

No change to `ReaderInterface`, `ManifestReport` or `ManifestStoreParser`.
Deptrac: `Core` may depend on `Provemark\C2paVerifier` (a framework-agnostic
library); the ADR records it.

## Open questions

- **Version constraint.** `^0.5` follows the verifier's own promise ("a change
  that breaks the API below will be `0.6.0`"). Each minor so far added formats,
  which here only matters through AC3. Non-blocking.
- **Does a report say which engine answered?** SPEC-038 AC7 required it for
  c2patool. Here the version is Composer's (`InstalledVersions`), chosen by the
  host's lockfile rather than by an operator installing a binary. Proposed: no
  criterion; `docs/readers.md` names the version measured. Non-blocking.
- **Memory.** `Asset` already holds the whole file as a string; copying it into
  `php://memory` doubles that briefly. The service path has a 20 MB body cap;
  this path has none of its own. Proposed: no new bound in this spec, a
  sentence in `docs/readers.md`. Non-blocking.
- **README.** It is at 299 lines with a test failing at 300. The route belongs
  in `docs/readers.md`; the README gets at most a changed line, not a new one.
  Non-blocking.

## Traceability

Implemented 2026-10-07; Amendment 2 the same day, Amendment 3 on 2026-10-08.
`composer check`: 427 passed, 7 skipped, 18 deprecated, deptrac 0 (419 before Amendment 2, 423 before
Amendment 3). Integration: 195 passed / 19 skipped with the service in its default
configuration, 194 / 20 with the `hardened` configuration (trust on, AI marking
required); 11 verifier comparisons ran in each.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | `tests/Integration/VerifierReaderEquivalenceTest.php` :: "agrees with the service reader through the verifier", "finds the marking it was given, through the verifier", "agrees with the service reader on an unsigned asset", "agrees with the in-process reader through the verifier"; `tests/Unit/Reading/C2paVerifierReaderTest.php` :: "reads the signed fixture as trusted, AI-generated and timestamped" | `src/Core/Reading/C2paVerifierReader.php` `read()`; `tests/Integration/ServiceHarness.php` `accessors()` |
| AC2 | `tests/Unit/Reading/C2paVerifierReaderTest.php` :: "returns an empty report for an asset with no C2PA data", "reports no validation state for an unsigned asset, as the other readers do", "returns exactly the report ExtC2paReader returns for no manifest"; `tests/Unit/Reading/VerifierOutcomeTest.php` :: "maps no manifest and no failure to an empty report", "treats a report with no has_manifest key as a failure, not as empty" | `src/Core/Reading/VerifierOutcome.php` `toManifestReport()` |
| AC3 | `tests/Unit/Reading/C2paVerifierReaderTest.php` :: "refuses a media type the verifier cannot read", "accepts every other media type", "knows of thirteen media types, eleven of them readable here" | `src/Core/Reading/C2paVerifierReader.php` `UNSUPPORTED`, `read()` |
| AC4 | `tests/Unit/Reading/C2paVerifierReaderTest.php` :: "throws ReadFailedException on input the verifier cannot parse", "throws ReadFailedException for a store the verifier reached but could not decode", "refuses a file whose only manifest is remote, naming the URL", "carries the verifier explanation in the message", "reports a broken hard binding as an Invalid report, not an exception"; `tests/Unit/Reading/VerifierOutcomeTest.php` :: "treats a reached but undecoded store as a failure", "does not call a reached store empty when it decoded nothing and said nothing", "refuses a report whose only manifest is remote", "bounds a remote manifest URL, which comes from the file, and keeps the reason", "reads the embedded manifest when a remote URL is declared beside it" | `src/Core/Reading/VerifierOutcome.php` `toManifestReport()` (Amendments 1 and 2); `tests/Fixtures/remote-manifest-spec042.jpg`; `src/Core/Reading/C2paVerifierReader.php` `read()` |
| AC5 | `tests/Unit/Reading/VerifierOutcomeTest.php` :: "bounds a long explanation where it enters this package", "bounds an explanation that is not valid UTF-8 without discarding it", "keeps a short explanation verbatim" | `src/Core/Reading/VerifierOutcome.php` via `ServiceError::bound()` |
| AC6 | `tests/Unit/Reading/C2paVerifierReaderTest.php` :: "refuses trust settings the verifier refuses, at construction", "verifies without trust when no settings are given", "treats empty settings as no settings" | `src/Core/Reading/C2paVerifierReader.php` `buildSettings()`; `src/Core/Reading/Exception/TrustSettingsRejectedException.php` |
| AC7 | `tests/Unit/Reading/C2paVerifierReaderTest.php` :: "reports itself available when the verifier is installed", "names the package to install when the verifier is absent" | `src/Core/Reading/C2paVerifierReader.php` `isAvailable()`, `__construct()`; `src/Core/Reading/Exception/VerifierMissingException.php` |
| AC8 | `tests/Unit/Laravel/ReaderSelectionTest.php` :: "binds the pure-PHP reader when the mode is verifier", "reports verifier as the mode, configured and resolved", "passes the configured settings file to the pure-PHP reader", "treats an empty settings value as no trust, not as an error", "refuses a settings path that does not exist, when the reader is resolved", "refuses a settings file the verifier refuses, when the reader is resolved", "names verifier among the accepted modes when refusing", "does not resolve auto to the verifier, though it is installed" | `src/Laravel/ReaderFactory.php` `MODES`, `make()`, `verifierSettings()`; `config/content-credentials.php` `verifier_settings` |
| AC9 | `tests/Unit/VerifierVersionRangeTest.php` :: "refuses every verifier version outside the tested range", "keeps the verifier out of require"; `tests/Integration/VerifierReaderEquivalenceTest.php` :: "agrees with the service reader through the verifier", "agrees with the in-process reader through the verifier" | `.github/workflows/ci.yml` steps "Assert the verifier comparison ran (SPEC-042)" and "Assert the verifier-extension comparison ran (SPEC-042)" (counted 0 on a skipped run, 11 on a real one); `composer.json` `require-dev`, `conflict` (ADR-0007 addendum); `docs/adr/ADR-0007-the-verifier-as-an-optional-reader.md` |
| AC10 | `tests/Unit/Reading/C2paVerifierReaderTest.php` :: "adds little to peak memory when reading a large asset", "fails with ReadFailedException when the copy cannot spill to disk" | `src/Core/Reading/C2paVerifierReader.php` `IN_MEMORY_BYTES`, `read()` (Amendment 3) |
