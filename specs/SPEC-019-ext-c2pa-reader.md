# SPEC-019: An in-process reader, so verification needs no signing service

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | Maurice van Loon — 2026-08-06                     |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

`ReaderInterface` has exactly one implementation, `SigningServiceReader`, which
POSTs to `/v1/read` on the Node service. So **reading a credential requires
running the signing service** — a second process, Docker, a certificate mount and
an API token.

For *signing* that is the architecture working as intended: key isolation is the
differentiator, and ADR-0003 settled it. For *reading* it is a cost with no
matching benefit. **Verification needs no private key, no certificate and no
service.** It is a pure function of the asset bytes plus, optionally, a trust
list. Today the only reason it needs a service is that reading and signing share
one transport.

That has two consequences worth stating separately.

### It filters out most of the ecosystem

Researched 2026-08-06 (NOTES Step 16 item 4): the PHP mass is WordPress, not
Laravel — AI Engine alone has 80,000+ active installs. Typical WordPress hosting
cannot run a second process. Those deployments cannot *verify* a credential with
this library, let alone sign one, and a viewer-side integration — the one place
this library could reach that ecosystem without compromising the architecture —
is currently impossible to build on it.

### It is the wrong half to gate

Article 50 has applied since 2 August 2026. Marking is what a generator does;
**checking** is what everyone downstream does, including applications that never
sign anything. Gating the cheap, keyless half behind the expensive, key-holding
half is backwards.

### What makes this buildable now

`ericmann/ext-c2pa` (Automattic, GPL-2.0-or-later, PHP namespace
`Automattic\VIP\C2PA`) is a native extension over c2pa-rs. Verified 2026-08-06:

- **Installable today.** On Packagist as `ericmann/ext-c2pa`, `type: php-ext`,
  `require: php ^8.3`, one release `v0.1.0`. `RELEASE.md` documents a worked PIE
  procedure with a platform × PHP-minor build matrix. (The README's "listing is
  still deferred" line is stale.)
- **The read surface is complete**, and maps almost one-to-one onto ours:
  `Reader::fromBytes()`, `hasManifest()`, `validationState()`, `isValid()`,
  `isTrusted()`, `json()`, plus `Settings->withTrustAnchors(string $pem)` —
  which takes PEM **contents**, matching the finding in NOTES Step 11.
- **Built for a web process**: `c2pa` with `default-features = false` (no
  `file_io`, no remote-manifest fetch — no disk, no network egress) and
  `rust_native_crypto` (no system OpenSSL link).

And the two facts that make this a bet rather than a certainty, which is exactly
why it belongs behind an interface:

- **v0.1.0.** `PLAN.md` still reads `Scaffold | rendered` with an empty release
  table, so the project's own planning lags its code. The API can move.
- **It is a VIP product**, not neutral infrastructure — it exists to serve the
  `wp-c2pa` plugin, and its namespace says so. We are not its audience.
- **Version drift.** The extension carries **c2pa-rs 0.89.0**; our service
  carries **0.90.4**. Two readers on two different c2pa-rs lines is precisely the
  kind of divergence that could make the same asset report differently.

## Scope

**In scope**

- `ExtC2paReader` in `src/Core/Reading/`, implementing the existing
  `ReaderInterface` and returning the existing `ManifestReport`.
- Opt-in construction and a clear failure when the extension is absent.
- Trust-list verification through the extension's `Settings`, reusing the PEM
  contents this repository already ships.
- An **equivalence test**: both readers, one asset, one identical
  `ManifestReport`. This is the only mechanism that would surface the c2pa-rs
  0.89-versus-0.90.4 drift; without it we would learn about it from a user.
- `ext-c2pa` in `suggest`, never in `require`.
- README and CHANGELOG.

**Out of scope** (each needs its own spec before it may be built)

- **`ExtC2paSigner`.** The extension exposes `Builder` and `Signer::fromPem()`,
  so in-process signing is possible — and it moves the private key into the web
  process, which is the trade ADR-0003 rejected. It may still be right as an
  explicit opt-in, but it is a separate decision with its own risk analysis, and
  bundling it here would smuggle it in behind an adoption argument.
- Making `ExtC2paReader` the default, or auto-selecting a reader. This spec adds
  a choice; it does not change anyone's existing one.
- A WordPress plugin.
- Any change to `SigningServiceReader`, the service, or the wire contract.
- Anything requiring a new **runtime** Composer dependency (a PHP extension in
  `suggest` is not one; anything else needs an ADR).

## Behavior

Acceptance criteria as Given/When/Then. Each is individually testable and will be
covered by a Pest test tagged `->group('SPEC-019')`. Criteria needing the
extension are skipped where it is absent, except AC5, which is about absence.

- **AC1 — a signed asset reads without any service**
  - Given the extension is loaded, and a signed asset
  - When it is read through `ExtC2paReader`
  - Then a populated `ManifestReport` comes back — `hasManifest()` true,
    `isSignatureValid()` true, `isAiGenerated()` true for our Article 50 marking
  - And no HTTP request is made, and no service need be running

- **AC2 — the two readers agree** *(the criterion this spec exists to keep true)*
  - Given the same signed asset and the same trust configuration
  - When it is read through `ExtC2paReader` and through `SigningServiceReader`
  - Then the two `ManifestReport`s are equal on every accessor the public API
    exposes: `hasManifest`, `isSignatureValid`, `isTrusted`, `validationState`,
    `isAiGenerated`, `isVerifiedAiGenerated`, `hasTimestamp`, `softwareAgent`
  - And where they differ, the test names which accessor and both values, so a
    c2pa-rs version drift is a legible failure rather than a mystery

- **AC3 — an asset with no C2PA data is an empty report, not an error**
  - Given an unsigned asset
  - When it is read through `ExtC2paReader`
  - Then `hasManifest()` is false and the report is empty, matching the SPEC-003
    contract and the behaviour SPEC-010 fixed on the service side
  - *(This is the exact shape of the SPEC-010 bug: the library returned null and
    an unguarded call crashed. It must not reappear in a second reader.)*

- **AC4 — trust verification works and discriminates**
  - Given trust anchors that cover the signing certificate
  - When a signed asset is read
  - Then `isTrusted()` is true and `validationState()` is `Trusted`
  - And given anchors that do **not** cover it, `isTrusted()` is false while
    `isSignatureValid()` stays true, with a `signingCredential.untrusted` code
    explaining it — never silence (SPEC-014 AC2, and NOTES Step 11's finding that
    absent trust material fails *silently*)

- **AC5 — a missing extension fails loudly at construction** *(error path)*
  - Given the extension is not loaded
  - When an `ExtC2paReader` is constructed
  - Then it throws immediately, naming the extension and how to install it
  - And it does **not** silently fall back to the service reader: a caller who
    asked for in-process reading and got HTTP instead cannot tell, which is the
    failure shape this project has now documented five times

- **AC6 — malformed input is refused, not crashed** *(error path)*
  - Given bytes that are not a valid asset, or bytes whose declared media type
    does not match
  - When they are read
  - Then a `ReadFailedException` from the existing `Reading/Exception` hierarchy
    is thrown — the same type `SigningServiceReader` throws, so a caller can
    swap readers without changing its error handling
  - And no extension panic or fatal reaches the caller

- **AC7 — the choice is documented, with its risks**
  - Given the README
  - When a reader chooses between the two
  - Then it states that verification needs no service, that the extension is
    installed with PIE and is at v0.1.0, that the two carry different c2pa-rs
    versions, and that signing is deliberately unaffected

## API sketch

Illustrative only. `final`, `readonly` where it applies, `strict_types=1`,
framework-agnostic — `Core` must not learn about Laravel (Deptrac).

```php
namespace Provemark\ContentCredentials\Core\Reading;

final readonly class ExtC2paReader implements ReaderInterface
{
    /** @throws ExtensionMissingException when ext-c2pa is not loaded */
    public function __construct(private ?string $trustAnchorsPem = null) {}

    public function read(Asset $asset): ManifestReport;

    public static function isAvailable(): bool;   // extension_loaded('c2pa')
}
```

```php
// Callers choose explicitly; nothing auto-switches.
$reader = ExtC2paReader::isAvailable()
    ? new ExtC2paReader($anchors)
    : new SigningServiceReader($client, $factory, $factory, $config);
```

The adapter is thin: hand the bytes to `Reader::fromBytes()`, take `json()`, and
feed it to the **existing** `ManifestReport` decoder. Two readers producing one
report shape is what makes AC2 meaningful — a second decoder would be a second
place for the definition of "trusted" to drift.

## Open questions

Settled at approval, before any test was written. Recorded as decisions rather
than deleted, because the reasoning is the useful part.

- **How does PHPStan see a class that only exists when an extension is loaded?**
  **Author our own stub** at `stubs/ext-c2pa.stub.php`, listed in
  `phpstan.neon`'s `scanFiles` and `export-ignore`d from the dist. Not the
  extension's own `stubs/c2pa.stubs.php`: vendoring a GPL-2.0-or-later file into
  this repository is a licensing question this spec should not answer, and a stub
  we write is one we can keep minimal — only the members the adapter calls, so an
  upstream addition cannot silently widen what we type-check against. Level max
  with no un-annotated ignores stands.
- **Is the equivalence test (AC2) run in CI?** **Skip-gated, like the rest of the
  integration suite**, and CI gains an extension install only if PIE provides a
  prebuilt binary for a PHP minor in the matrix. A drift check nobody runs is
  worth little, so if it stays local it must be named in `CONTRIBUTING.md` as a
  step before releasing a reader change — not left to memory.
- **Does `Settings->withTrustAnchors()` accept the document we already ship?**
  Verify against the extension before writing the AC4 test, as with NOTES
  Step 11 — the trust surface is where this project has been surprised most.
  `certs/c2pa-trust.settings.json` embeds PEM contents and the extension takes
  PEM contents, so the expectation is a field extraction, not a new file. The
  test asserts the observed behaviour, not the expectation.
- **What happens at v0.2.0?** `suggest` carries a version note rather than a
  constraint (Composer cannot constrain a suggestion anyway), and Dependabot does
  not watch extensions. The containment is the adapter plus AC2: a break shows up
  as the two readers disagreeing, which is a legible failure. Accepted risk,
  documented in the README.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

| Acceptance criterion | Test (file :: name / group) | Source (file/symbol) |
|----------------------|-----------------------------|----------------------|
| AC1                  | —                           | —                    |
| AC2                  | —                           | —                    |
| AC3                  | —                           | —                    |
| AC4                  | —                           | —                    |
| AC5                  | —                           | —                    |
| AC6                  | —                           | —                    |
| AC7                  | —                           | —                    |
