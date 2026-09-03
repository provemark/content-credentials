# SPEC-038: A reader that shells out to the c2patool binary

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | draft                                             |
| Author     | Maurice van Loon (maintainer)                     |
| Approved   | — while draft                                     |
| Supersedes | — (extends SPEC-003 reading, SPEC-019 the second reader, SPEC-020 selection, SPEC-025 client-side bounds) |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

Reading a manifest costs the caller a deployment decision today. `SigningServiceReader`
needs a second process to run, secure and monitor; `ExtC2paReader` needs a native
PHP extension the host must build. NOTES Step 24 recorded that neither reaches
the part of the PHP world that cannot do either, and corrected the reach claim
SPEC-019 was written on.

NOTES Step 57 measured what that costs. The one PHP package on Packagist with
more downloads than this one, `jrglasgow/c2patool`, is a wrapper around the
`c2patool` binary and builds nothing. The two CMS-side integrations that exist —
a Drupal module that blocks AI-generated uploads and a Contao bundle that detects
existing markers — both **read**, neither signs. The demand that is visible in
this ecosystem is on the half our delivery shape serves worst.

### What makes this buildable now, measured 2026-09-03

Against `c2patool` 0.27.16 (c2pa-rs 0.90.16) on this machine:

- **The output is already our shape.** `c2patool <asset>` writes the manifest
  store to stdout with top-level keys `active_manifest`, `manifests`,
  `validation_results`, `validation_state`, `validation_status` — **identical to
  what `POST /v1/read` returns**.
- **`ManifestStoreParser::fromJson()` parses it unchanged.** Feeding c2patool's
  stdout straight into the shared decoder produced `hasManifest=true`,
  `state=Valid`, `isSignatureValid=true`, `hasTimestamp=true`,
  `isAiGenerated=true`, `signer=C2PA Test Signing Cert`,
  `softwareAgents=[ACME GenAI Image Model]`, `declaredSpecVersion=2.4.0`,
  `assertions=[c2pa.actions.v2]`. Every accessor, including the two newest.
- **The trust verdict works.** The same asset with
  `--settings certs/c2pa-trust.settings.json` reads `state=Trusted`,
  `isTrusted=true`; without it, `Valid` and untrusted. That is the SPEC-013/014
  distinction, unchanged.
- **It is fast**: 5.2 ms per invocation on a 55 KB PNG, ten runs, process spawn
  included.

So the adapter is thin. That is the argument for it, and it is measured rather
than assumed: the decoder is shared, the JSON is the same, and what would be new
is process handling, not C2PA.

### What this does NOT solve

**It is not the shared-hosting answer.** A host that forbids `proc_open` or
`exec`, or gives no way to place a binary, remains out of reach — and cheap
shared hosting often does both. This lowers the bar from *"run and monitor a
second process"* to *"put a file on disk and be allowed to execute it"*, which is
a real reduction and a partial one. The only thing that reaches the WordPress
mass is a reader in pure PHP, which NOTES Step 24 named and nobody has asked for.

Claiming reach beyond that would repeat the exact error SPEC-019's Problem
section made, which Step 24 had to correct.

## Scope

**In scope**

- `C2paToolReader`, a third `ReaderInterface` implementation that runs the
  `c2patool` binary and decodes its stdout through `ManifestStoreParser`.
- Configuration: the binary's path, an explicit settings file, a timeout, an
  output ceiling.
- Reporting which engine answered, since a third c2pa-rs version enters the
  picture and its version is chosen by the operator, not by us.
- Selection: a new value for the reader-selection config of SPEC-020.
- Extending the SPEC-019 AC2 equivalence comparison to three readers.

**Out of scope**

- **Signing. Explicitly and permanently.** `c2patool` can sign, and even offers
  `--signer-path` for an external signer. Using it would put the private key on
  the web host, which ADR-0003 refuses, and the isolated service stays the
  default and the key-isolation differentiator. This spec adds no signing API and
  no `SignerInterface` implementation.
- Shipping, vendoring or downloading the binary. The operator installs it; we
  never fetch it at runtime or at install time.
- `--sidecar`, `--ingredient`, `--tree`, `--certs`, `fragment` and remote
  manifest references. A reader answers the questions `ReaderInterface` asks.

## Behavior

Acceptance criteria. Each is individually testable; the measurements they
rest on are in the Problem section above.

- **AC1 — three readers, one answer**

  For an asset all three can read, the
  accessors of `C2paToolReader` agree with `SigningServiceReader` and
  `ExtC2paReader`, compared accessor by accessor as SPEC-019 AC2 already does, with
  at least one pinned concrete value rather than only agreement — two readers that
  both return empty agree about nothing.

- **AC2 — no manifest is an empty report, not an error**

  `c2patool` exits **1**
  with empty stdout and `Error: No claim found` on stderr for an asset carrying no
  manifest. That must decode to an empty `ManifestReport` with
  `hasManifest() === false`, matching the SPEC-003 contract and SPEC-010's
  behaviour for the service reader. An asset without credentials is an ordinary
  answer, not a failure.

- **AC3 — every other failure is named and bounded *(error path)***

  Unsupported input exits 1
  with `Error: Unsupported file type`; a missing binary, a timeout, an oversized or
  non-JSON stdout are each their own case. Each maps to an existing named exception
  in the reading hierarchy. **Nothing from stderr is echoed raw**: the message is
  attacker-influenced text and reaches an operator's terminal, so it is truncated
  and stripped of control characters exactly as SPEC-006 AC8 requires for
  manifest-derived values.

  ⚠️ AC2 and AC3 both key on exit code 1. **The discrimination is a stderr string,
  which is not a stable interface.** A criterion must state what happens when the
  message is neither known string: it is treated as a failure (AC3), never as an
  empty report — failing closed, as SPEC-013 does for trust.

- **AC4 — the host's ambient configuration cannot change a verdict**

  `c2patool`
  reads settings from `$XDG_CONFIG_HOME/c2pa/c2pa.toml` by default and honours the
  `C2PATOOL_SETTINGS` environment variable. A trust list left in either place would
  silently change what this package reports as trusted. The reader therefore always
  passes `--settings` explicitly — a caller-provided file, or a neutral one when
  trust verification is off — and does not inherit `C2PATOOL_SETTINGS` from the
  environment it was started in.

- **AC5 — the binary is named, not discovered**

  An absolute path is configured. A
  `$PATH` lookup, if offered at all, is opt-in and never the default: what a web
  process finds on its `$PATH` is not a decision this package should make for it.

- **AC6 — bounded like every other client path (SPEC-025)**

  A wall-clock timeout,
  a ceiling on stdout, and an asset written to a temporary file — `c2patool` takes
  a path and does not read the asset from stdin. That file lives in a private
  directory, is created with restrictive permissions, and is removed on every exit
  path including a timeout or an exception.

- **AC7 — the reader says which engine answered**

  `c2patool --version` is
  surfaced. Three c2pa-rs versions now coexist — 0.89.0 in the extension, 0.90.16
  in the service, and whatever the operator installed here — and SPEC-020's whole
  argument for not defaulting to `auto` is that an engine change must never be
  invisible. An unreadable or unparseable version string is itself reported, not
  guessed.

- **AC8 — it cannot be made to sign**

  No method on the class runs `c2patool` with
  `--manifest`, `--config`, `--output`, `--signer-path` or any signing flag. A test
  asserts the constructed argument vector against an allow-list, because "we did
  not implement signing" is an absence, and absences are the assertions this
  repository has repeatedly found to pass while testing nothing.

- **AC9 — selection stays explicit**

  The SPEC-020 configuration gains a value for
  this reader. The meaning of `auto` does not silently widen to include it: what
  `auto` resolves to today must keep resolving the same way, or the change is
  announced in its own criterion.

- **AC10 — no shell**

  The process is started with an argument vector, never a
  command string. No asset-derived or caller-derived value reaches the argument
  list except the temporary path this package generated.

## API sketch

```php
final class C2paToolReader implements ReaderInterface
{
    public function __construct(
        private readonly string $binaryPath,
        private readonly ?string $settingsPath = null,
        private readonly int $timeoutSeconds = 10,
        private readonly int $maxOutputBytes = 8 * 1024 * 1024,
    ) {}

    public static function isAvailable(string $binaryPath): bool;

    public function engineVersion(): ?string;   // AC7, from `c2patool --version`

    public function read(Asset $asset): ManifestReport;   // AC1, AC2, AC3
}
```

No new interface, no change to `ReaderInterface`, no change to
`ManifestStoreParser` — the measurement above is what says the last one is true.

## Open questions

- **`proc_open` or `symfony/process`?** `proc_open` with an argument vector needs
  no new runtime dependency and satisfies AC10 on its own; `symfony/process`
  brings timeout and output handling for free but is a runtime dependency, which
  needs an ADR under this repository's own rules. *Non-blocker*, leaning
  `proc_open`, because a library with four PSR-shaped requirements should not
  gain a fifth for a feature that is opt-in.
- **Does CI install the binary?** It would be a **fourth** external download in a
  workflow that already carries three, and the `ext-c2pa` profile is
  `continue-on-error` for exactly that reason. *Non-blocker*, leaning a separate
  non-blocking profile — with the price stated in the workflow, as the existing
  comment does: an alarm that does not gate is not an alarm that may be ignored.
- **Which version floor?** The output shape measured here is 0.27.16. Older
  c2patool versions predate `validation_state` and would decode to something
  else. *Blocker for the build*: the spec must name a minimum and AC7 must make a
  version below it a refusal rather than a surprise.
- **Is a temporary file acceptable for a 20 MB asset?** It doubles the asset in
  storage briefly and puts caller content on disk, which the service path never
  does locally. *Non-blocker*, but the docs page must say it, because it is a
  property an operator may not expect from a "reader".
- **Does this belong behind `auto` at all?** Three readers with three engines make
  `auto` a harder promise, not an easier one. *Non-blocker*, leaning no.

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
| AC8                  | —                           | —                    |
| AC9                  | —                           | —                    |
| AC10                 | —                           | —                    |
