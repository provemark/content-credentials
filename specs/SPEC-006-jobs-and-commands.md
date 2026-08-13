# SPEC-006: Queued signing job + artisan commands

| Field      | Value                                   |
|------------|-----------------------------------------|
| Status     | implemented (amended 2026-08-13 — see Amendment) |
| Author     | Maurice van Loon (maintainer)           |
| Approved   | Maurice van Loon — 2026-07-27           |
| Supersedes | —                                       |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

SPEC-004 wired the Laravel layer (provider, config, facade, manager) but
deferred the ergonomics CLAUDE.md lists for that layer: **queued jobs** and
**artisan commands** (SPEC-004 D5). Signing is a network round-trip to the
service — slow and transiently failure-prone — so an app usually wants it
**off the request path** (a queued job), and operators want a **CLI** to sign or
inspect a file without writing code. Neither exists yet.

## Scope

**In scope**

- **Artisan command `content-credentials:sign`** — sign a local file:
  `content-credentials:sign {input} {output} {--agent=} {--agent-version=}
  {--claim-generator=} {--claim-generator-version=}`. Infers `MediaType` from the
  input extension (`.png` / `.jpg` / `.jpeg`), builds the AI-generated manifest,
  signs via the bound `SignerInterface`, writes `{output}`.
- **Artisan command `content-credentials:read`** — inspect a local file:
  `content-credentials:read {file}`. Prints `hasManifest`, `isAiGenerated`,
  `digitalSourceTypes`, signer, `validationState`, `isSignatureValid`,
  `isTrusted` via the bound `ReaderInterface`. *(Amended 2026-08-13: also
  `reader` and `hasTimestamp` — see Amendment.)*
- **Queued job `SignAssetJob`** (`implements ShouldQueue`) — signs a source file
  and writes the signed file to a destination, off the request path, with
  bounded retries + backoff (signing is a network call). Delegates to
  `SignerInterface`; may fire an `AssetSigned` event on success.
- Provider registers the commands when running in the console.

**Out of scope** (each needs its own spec)

- Eloquent/model integration (signing a model attribute), batch/bulk
  orchestration, progress reporting beyond basic output.
- Trust verification (`c2patool` / `bin/verify.sh` stays the authority; the read
  command reports the library's own `validationState`/`isSignatureValid`, not a
  trust-list decision).
- A queue-driven read/verify job (only signing is queued in v1).
- CAWG and formats beyond PNG/JPEG (inherited from SPEC-001/002). *(TSA was
  listed here until 2026-08-13; the inheritance expired when SPEC-007 shipped —
  see Amendment.)*

## Behavior

Given/When/Then; each maps to a Pest test tagged `->group('SPEC-006')`, run in the
bare `illuminate/container` harness (SPEC-004 D4). Commands are exercised by
instantiating them, calling `setLaravel($container)`, and running them with
Symfony Console `ArrayInput` + `BufferedOutput`; the job by constructing it and
calling `handle()` with a fake/mock `SignerInterface`. A fake signer/reader bound
in the container returns known results — **no live service**. AC2 and AC6 are the
required error paths.

- **AC1 — `sign` command signs a file**
  - Given a fake `SignerInterface` bound in the container that records the
    `Manifest` and returns known `SignedAsset` bytes, and an input PNG on disk
  - When `content-credentials:sign in.png out.png --agent="ACME GenAI"` runs
  - Then the exit code is `0`; `out.png` contains exactly the returned bytes; the
    recorded manifest is a `c2pa.actions.v2` / `c2pa.created` marking with
    `digitalSourceType = trainedAlgorithmicMedia` and `softwareAgent.name =
    "ACME GenAI"`; and the `Asset` passed carried `MediaType::Png`.

- **AC2 — `sign` command rejects an unsupported extension** *(error path)*
  - Given an input path ending in `.gif`
  - When the command runs
  - Then the exit code is non-zero, a clear error naming the unsupported type is
    written, and no output file is produced.

- **AC3 — `read` command reports a credential**
  - Given a fake `ReaderInterface` bound in the container returning a known
    `ManifestReport` (AI-generated, signer set, `validationState` Valid)
  - When `content-credentials:read signed.png` runs
  - Then the exit code is `0` and the output contains `isAiGenerated`,
    the signer issuer, and `validationState` values from the report.

- **AC4 — `SignAssetJob` signs and writes**
  - Given a `SignAssetJob` for a source path, destination path and manifest
    parameters, and a mock `SignerInterface` returning known bytes
  - When `handle($signer)` is called
  - Then the source bytes are signed with the expected manifest and the known
    bytes are written to the destination.

- **AC5 — `SignAssetJob` is a bounded, retrying queue job**
  - Given the job
  - When its queue configuration is inspected
  - Then it implements `ShouldQueue`, declares a finite `$tries` (> 1) and a
    `backoff()` schedule (signing is a network call, so transient failures are
    retried rather than lost).

- **AC6 — `SignAssetJob` surfaces a signing failure** *(error path)*
  - Given a mock `SignerInterface` that throws a `ContentCredentialsException`
  - When `handle($signer)` is called
  - Then the exception propagates out of `handle()` (so the queue can retry /
    eventually fail the job); no partial/corrupt destination file is left behind.

- **AC7 — the read command reports whether the manifest carries a timestamp**
  *(added by the 2026-08-13 amendment; see Amendment for why)*
  - Given a `ManifestReport` whose active manifest carries an RFC 3161
    timestamp, and one that does not
  - When `content-credentials:read` runs against each
  - Then the output reports the timestamp state for both, labelled, and the two
    outputs differ in that field
  - And the report does **not** describe the timestamp as trusted or as proof of
    time. Per SPEC-007 D3 and the accessor's own docblock, `hasTimestamp()`
    means the token is **present and structurally parseable**; trust of the
    timestamp authority's own certificate is a separate concern, stated in
    `docs/production.md`. SPEC-013 exists because absence of evidence must not
    read as trust, and a bare `true` beside `isTrusted: false` invites exactly
    that reading

## API sketch

Illustrative only. `declare(strict_types=1)`; PHPStan level max. Lives in
`src/Laravel/{Console,Jobs,Events}` (Laravel layer — may use `illuminate/*`).

```php
namespace Provemark\ContentCredentials\Laravel\Jobs;

use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class SignAssetJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;

    public int $tries = 3;

    public function __construct(
        private string $sourcePath,
        private string $destinationPath,
        private MediaType $mediaType,
        private string $softwareAgent,
        private ?string $softwareAgentVersion = null,
        // ... optional claim generator ...
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(SignerInterface $signer): void
    {
        // read source -> build manifest (ManifestBuilder) -> sign -> write dest
        // -> optionally event(new AssetSigned($this->destinationPath));
    }
}
```

```php
namespace Provemark\ContentCredentials\Laravel\Console;

use Illuminate\Console\Command;

final class SignCommand extends Command
{
    protected $signature = 'content-credentials:sign
        {input} {output} {--agent=} {--agent-version=}
        {--claim-generator=} {--claim-generator-version=}';

    public function handle(\Provemark\ContentCredentials\Laravel\ContentCredentialsManager $cc): int
    {
        // infer MediaType from the input extension; build + sign; write output;
        // return self::SUCCESS / self::FAILURE.
    }
}
```

The provider registers the commands in `boot()`:

```php
if ($this->app->runningInConsole()) {
    $this->commands([Console\SignCommand::class, Console\ReadCommand::class]);
}
```

## Decisions (resolved at approval, 2026-07-27)

The draft's open questions were resolved as proposed; recorded here so the
approved spec is self-contained.

- **D1 — job source/destination contract.** Local filesystem paths for v1
  (simplest, matches the CLI and same-host workers). Storage-disk and raw-bytes
  variants are deferred.
- **D2 — retry policy.** A flat `$tries` + `backoff()` that retries any failure
  for v1; a transient-vs-permanent policy (`fail()` on
  `MediaTypeMismatchException`/`MissingConfiguration`/4xx) is a later refinement.
- **D3 — completion event.** Fire a lightweight `AssetSigned` event on success so
  apps can react.
- **D4 — media-type inference.** Infer `MediaType` from the input extension
  (`.png`→Png, `.jpg`/`.jpeg`→Jpeg); error on anything else. No `--format`
  override in v1.
- **D5 — testing depth.** Unit-test command logic (via `setLaravel` + Symfony
  `ArrayInput`/`BufferedOutput`) and the job's `handle()` (direct call with a
  mock signer) in the bare harness. Queue dispatch/worker behaviour and console
  auto-registration are framework integration the bare harness cannot exercise
  (no full app/kernel; testbench uninstallable — SPEC-004 D4); those are covered
  by manual/e2e verification. Small annotated PHPStan ignores for framework-typed
  console/queue APIs are acceptable if needed, scoped as in SPEC-004.

No open questions remain.

## Amendment (2026-08-13)

Found by a review of #70, which added `hasTimestamp` to the `read` command and
was reverted because this spec put it out of scope. Two of the three defects
below predate that PR.

**The Out-of-scope exclusion of TSA had expired, and nobody revisited it.** It
read "CAWG, TSA, formats beyond PNG/JPEG (inherited from SPEC-001/002)", and the
parenthesis is the whole story: on 2026-07-27 this package had no timestamping
at all, so the commands could not report on it. SPEC-007 implemented TSA and
gave the reading contract `ManifestReport::hasTimestamp(): bool`. The exclusion
was inherited from a state that stopped being true, and an inherited exclusion
does not expire on its own — which is why it silently governed a decision six
weeks later.

**The In-scope description of what `read` prints was already wrong before this.**
It enumerates seven fields; the command has printed `reader` since SPEC-020 AC6,
and that enumeration was never updated. An enumeration that drifts is worse than
a floor, because it reads as exhaustive. It is amended to match, and AC7 below
states the rule rather than restating the list.

**AC7 is new**, and lives in `## Behavior` with the others rather than here.
It was written into this section when the amendment was drafted, which is a
defect SPEC-020's amendment tripped the same afternoon: `bin/spec-check.php`
reads criteria from Behavior only, so a criterion written into an amendment
gets a traceability row pointing at nothing the tool can find. It never errored
here only because AC7 had no row until now. SPEC-028's amendment had already
set the pattern — the criterion goes in Behavior, the amendment narrates.

**One precondition before the criterion is worth much.** No CI profile sets
`CONTENTAUTH_TSA_URL`, so SPEC-019 AC2's cross-reader comparison of
`hasTimestamp` compares two `false` values and has never observed agreement
non-vacuously. The extension carries c2pa-rs 0.89.0 against the service's
0.90.5, and `ManifestStoreParser` reads `signature_info.time` as written against
the latter. Reporting the value to an operator is only as good as that
comparison; closing it belongs to SPEC-019 and is noted here so the dependency
is not discovered a third time.

## Traceability

Filled when status becomes `implemented`. Every acceptance criterion maps to at
least one test; every source file maps back to this spec.

Implemented 2026-07-27. Tests in
`tests/Unit/Laravel/JobsAndCommandsTest.php`, tagged `->group('SPEC-006')`
(6 tests). `composer check` green (Pint + PHPStan level max + Pest + Deptrac).
Added `illuminate/console` to require-dev (the commands extend
`Illuminate\Console\Command`; the host Laravel app provides it at runtime, like
`illuminate/support`). Console auto-registration (provider `boot()`) and queue
dispatch are framework integration verified manually/e2e, not in the bare
harness (D5). The bare `Illuminate\Console\Command` execution needs container
methods (`runningUnitTests()`), provided by a test-only `Container` subclass;
the `Application`-typing of `Command::setLaravel()` is covered by the annotated
PHPStan ignore in `phpstan.neon`.

| Criterion | Test (`it …`) | Source (file / symbol) |
|-----------|---------------|------------------------|
| AC1 | sign command signs a file | `Console\SignCommand`, `Console\InfersMediaType` |
| AC2 | sign command rejects an unsupported extension | `Console\SignCommand`, `Console\InfersMediaType`, `Manifest\Exception\UnsupportedMediaTypeException` |
| AC3 | read command reports a credential | `Console\ReadCommand` |
| AC4 | SignAssetJob signs the source and writes the destination | `Jobs\SignAssetJob::handle()`, `Events\AssetSigned` |
| AC5 | SignAssetJob is a bounded, retrying queue job | `Jobs\SignAssetJob` (`ShouldQueue`, `$tries`, `backoff()`) |
| AC6 | SignAssetJob lets a signing failure propagate and leaves no output | `Jobs\SignAssetJob::handle()` |
| AC7 | `tests/Unit/Laravel/JobsAndCommandsTest.php` :: "read command reports a credential"; "read command distinguishes a timestamped manifest from one without" | `Console\ReadCommand` |
