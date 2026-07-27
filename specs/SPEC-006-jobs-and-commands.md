# SPEC-006: Queued signing job + artisan commands

| Field      | Value                                   |
|------------|-----------------------------------------|
| Status     | approved                                |
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
  `isTrusted` via the bound `ReaderInterface`.
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
- CAWG, TSA, formats beyond PNG/JPEG (inherited from SPEC-001/002).

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

## API sketch

Illustrative only. `declare(strict_types=1)`; PHPStan level max. Lives in
`src/Laravel/{Console,Jobs,Events}` (Laravel layer — may use `illuminate/*`).

```php
namespace ContentCredentials\Laravel\Jobs;

use ContentCredentials\Core\Manifest\MediaType;
use ContentCredentials\Core\Signing\SignerInterface;
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
namespace ContentCredentials\Laravel\Console;

use Illuminate\Console\Command;

final class SignCommand extends Command
{
    protected $signature = 'content-credentials:sign
        {input} {output} {--agent=} {--agent-version=}
        {--claim-generator=} {--claim-generator-version=}';

    public function handle(\ContentCredentials\Laravel\ContentCredentialsManager $cc): int
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
