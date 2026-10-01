# SPEC-042: A reader in pure PHP, through provemark/c2pa-verifier

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — while draft                                     |
| Supersedes | — (extends SPEC-003 reading and SPEC-019 the second reader; see SPEC-038 below) |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Reading a manifest costs the caller a deployment decision. `SigningServiceReader`
needs a second process; `ExtC2paReader` needs a native extension the host must
build. NOTES Step 24 recorded that neither reaches cheap shared hosting, and
this file's own guidance says that reaching it "would need a third route — a
reader in pure PHP … Large, unbuilt, unrequested. Do not start it without a spec
and a user asking."

Two of those three words are no longer true:

- **It is built.** `provemark/c2pa-verifier` (v0.2.8 on Packagist, PHP `^8.3`,
  `ext-openssl` and `ext-mbstring` only) verifies in pure PHP — claim
  signature, hash binding, certificate chain against a supplied trust list,
  RFC 3161 timestamp — and returns c2patool's shape. This package already
  points at it: `suggest` in `composer.json` and "A third route" in
  `docs/readers.md` (#151). Nothing here *uses* it.
- **It is asked for.** The Drupal module `drupal/content_credentials` asked on
  2026-10-01, for exactly the hosts this route exists for, and measured it
  first (that module's NOTES Step 26).

### What that measurement found

`ManifestStoreParser::fromArray($verifier->verify(...)->toArray())`, compared
with `SigningServiceReader` on the same file and the same trust settings
(`certs/c2pa-trust.settings.json`, read unchanged by `TrustSettings::fromJson()`):

| File | Verifier through the parser | Service |
|---|---|---|
| a PNG signed by this package's service | Trusted · `trainedAlgorithmicMedia` · ACME GenAI Image Model 3.1.0 · C2PA Test Signing Cert | identical |
| the same, one byte flipped in `IDAT` | Invalid · same type, agent, signer | identical |
| an unsigned PNG | `hasManifest()` false · **state `Invalid`** | `hasManifest()` false · state `null` |

So the decoder already serves a third reader unchanged — **except** for the
unsigned case, where the verifier reports `validation_state: Invalid` on a file
with nothing to validate. Passed through as-is, that breaks SPEC-019 AC2's
equivalence on `validationState` and contradicts SPEC-003's "no C2PA data is an
empty report". The adapter has to own that, as `ExtC2paReader` already does
with its explicit empty report.

### Relationship to SPEC-038

SPEC-038 (draft) is also a third route: shelling out to the `c2patool` binary.
Both aim at hosts that cannot run a service or an extension. They do not
compete for the same host — a binary needs `proc_open` and an executable it may
not be allowed to place, which much shared hosting refuses; pure PHP needs
neither — and this spec does not decide SPEC-038's fate. It does mean SPEC-038's
reach argument should be re-read against this one before that spec is approved.

## Scope

**In scope**

- `C2paVerifierReader implements ReaderInterface` in `Core/Reading`.
- Trust configuration at construction, fail-closed like `ExtC2paReader`.
- The empty report for an asset with no manifest.
- A refusal for media types the verifier does not read.
- Joining the SPEC-019 AC2 equivalence check as a third reader.
- `require-dev` for the tests; `suggest` already exists. Recorded as ADR-0007.

**Out of scope** (each needs its own spec before it may be built)

- Laravel wiring (a config value choosing this reader). SPEC-020 owns selection.
- `FragmentedVerifier` and DASH input.
- Any change to `ManifestStoreParser` or `ManifestReport`. If one turns out to
  be needed, that is a finding and an amendment, not part of this.
- Signing. The verifier never signs, and nothing here changes that.
- Deciding SPEC-038.

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will
be covered by a Pest test tagged `->group('SPEC-042')`.

- **AC1 — a signed asset reads with no service, no extension and no binary**
  - Given a signed PNG and trust settings covering its signer
  - When it is read through `C2paVerifierReader`
  - Then `hasManifest()`, `isSignatureValid()` and `isTrusted()` are true,
    `digitalSourceTypes()` holds `…/trainedAlgorithmicMedia` and
    `softwareAgents()` holds *ACME GenAI Image Model 3.1.0* — concrete values,
    because two readers that both find nothing agree
  - And no HTTP request is made and no process is started

- **AC2 — it agrees with the other readers** *(the drift alarm)*
  - Given the same signed asset and the same trust configuration
  - When it is read through `C2paVerifierReader` and `SigningServiceReader`
    (and `ExtC2paReader` where the extension is loaded)
  - Then every accessor in `spec019Accessors()` is equal, and a difference
    names the accessor and both values

- **AC3 — an asset with no C2PA data is an empty report** *(the measured gap)*
  - Given an unsigned asset of a type the verifier reads
  - When it is read
  - Then `hasManifest()` is false and `validationState()` is `null` — the same
    report `ExtC2paReader` returns — not the verifier's `Invalid`

- **AC4 — trust discriminates, and absent trust is never silent**
  - Given settings that do not cover the signer, or no settings
  - When a signed asset is read
  - Then `isTrusted()` is false, `isSignatureValid()` stays true, and the
    status codes say why (`signingCredential.untrusted`, or the verifier's
    statement that the trust check did not run)
  - And settings that are present but empty or unusable are refused at
    construction, not discovered on the first read

- **AC5 — a type the verifier does not read is refused, not misread** *(error path)*
  - Given an asset whose `MediaType` is outside what the verifier reads — of
    this package's thirteen, documented by the verifier's README (not yet
    measured) as GIF, TIFF, SVG, WAV, MP3, FLAC and AVI
  - When it is read
  - Then a `ReadFailedException` is thrown, naming the type and this reader
  - And never an empty report: "no credential" and "could not look" are
    different answers, and a caller deciding on trust must be able to tell them
    apart
  - And a public static method reports which types this reader accepts, so a
    caller can decide before queueing work

- **AC6 — malformed input is refused, not crashed** *(error path)*
  - Given bytes that are not a valid asset of their declared type
  - When they are read
  - Then the outcome is either a report the decoder accepts or a
    `ReadFailedException` — the same type the other readers throw — and no
    exception from the verifier's `@internal` layers reaches the caller

- **AC7 — the choice is documented, with its risks**
  - Given `docs/readers.md`, where the README already sends a reader choosing
    between readers
  - Then it states what this reader needs (PHP and two extensions every host
    has), which types it reads and which it refuses, that the verifier is a
    first version nobody had used before this, and that it never signs
  - Not the README: `DocumentationLayoutTest` caps it at 300 lines and `main`
    is at 299, which is why #151 put its pointer in `docs/readers.md` too

## API sketch

Illustrative only. `final`, `strict_types=1`, `Core` only (Deptrac).

```php
namespace Provemark\ContentCredentials\Core\Reading;

final class C2paVerifierReader implements ReaderInterface
{
    /**
     * @param string|null $trustSettingsJson c2patool-shaped settings, CONTENTS
     *                                       not a path (the shape
     *                                       certs/c2pa-trust.settings.json has)
     * @throws TrustAnchorsNotAppliedException when settings are present but unusable
     */
    public function __construct(?string $trustSettingsJson = null) {}

    public function read(Asset $asset): ManifestReport;

    /** @return list<MediaType> */
    public static function supportedMediaTypes(): array;
}
```

Thin by design: a `php://memory` stream over the asset bytes, `verify()`, then
the existing decoder — one definition of "trusted" across three readers.

## Open questions

To be answered at approval.

- **Settings as JSON or as PEM?** `ExtC2paReader` takes PEM contents; the
  verifier takes c2patool-shaped JSON and still reads the older single-PEM
  `trust.trust_anchors`. JSON is what the verifier means and what the shipped
  test file already is (measured). PEM would let a consumer that stores PEM
  — the Drupal module does — pass one value to either in-process reader, with
  the adapter wrapping it. Not measured: whether the wrapping yields the same
  verdict as the file.
- **Which exception for unusable settings?** The verifier throws its own
  `Trust\TrustException`. The proposal is to wrap it in the existing
  `TrustAnchorsNotAppliedException`, which is what `ExtC2paReader` throws when
  its trust setup fails closed, so a caller catches this package's types only
  and one type means "trust was asked for and is not in effect".
- **The supported-type list: measured or declared?** The verifier's README
  names its formats. AC5 should be pinned by reading one fixture of every
  `MediaType` through it, not by copying a sentence.
- **CI.** The verifier is plain Composer, so unlike the extension it can run in
  every profile. Should AC2's three-way comparison then be blocking? The
  argument against is the one the `ext-c2pa` profile carries: it depends on a
  package outside this repository staying the same.

## Traceability

Filled when status becomes `implemented`.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1 | | |
| AC2 | | |
| AC3 | | |
| AC4 | | |
| AC5 | | |
| AC6 | | |
| AC7 | | |
