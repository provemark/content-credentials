<?php

declare(strict_types=1);

use Http\Mock\Client as MockClient;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Nyholm\Psr7\Factory\Psr17Factory;
use Provemark\ContentCredentials\Core\Manifest\Manifest;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Reading\ReaderInterface;
use Provemark\ContentCredentials\Core\Reading\SignerInfo;
use Provemark\ContentCredentials\Core\Reading\SigningServiceReader;
use Provemark\ContentCredentials\Core\Reading\ValidationState;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningTransportException;
use Provemark\ContentCredentials\Core\Signing\SignedAsset;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Laravel\Console\ReadCommand;
use Provemark\ContentCredentials\Laravel\Console\SignCommand;
use Provemark\ContentCredentials\Laravel\Jobs\SignAssetJob;
use Provemark\ContentCredentials\Laravel\ReaderFactory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * SPEC-006 — queued signing job + artisan commands.
 * Tests-first: reference src/Laravel/{Console,Jobs} classes that do not exist
 * yet; RED until implemented. Bare illuminate/container harness (SPEC-004 D4);
 * commands run via setLaravel + Symfony IO, the job via a direct handle() call.
 * No live service.
 *
 * @see specs/SPEC-006-jobs-and-commands.md
 */
const AI_URI_6 = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

/**
 * Bare container that also answers the framework methods Illuminate\Console\Command
 * calls during run() (the plain Container does not implement them).
 */
final class Cc6TestContainer extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }

    public function runningInConsole(): bool
    {
        return true;
    }
}

function h6ConsoleApp(): Container
{
    $app = new Cc6TestContainer;
    Container::setInstance($app);

    return $app;
}

/**
 * @param  array{name: string, version?: string}  $softwareAgent
 * @return array{label: string, data: array{actions: list<array{action: string, digitalSourceType: string, softwareAgent: array{name: string, version?: string}}>}}
 */
function h6AiAssertion(array $softwareAgent): array
{
    return ['label' => 'c2pa.actions.v2', 'data' => ['actions' => [[
        'action' => 'c2pa.created',
        'digitalSourceType' => AI_URI_6,
        'softwareAgent' => $softwareAgent,
    ]]]];
}

function h6TempFile(string $ext, string $contents = 'DUMMY-BYTES'): string
{
    $path = sys_get_temp_dir().'/cc6_'.uniqid('', true).'.'.$ext;
    file_put_contents($path, $contents);

    return $path;
}

function h6DestPath(string $ext = 'png'): string
{
    return sys_get_temp_dir().'/cc6_out_'.uniqid('', true).'.'.$ext;
}

/**
 * @param  array<string, mixed>  $params
 * @return array{0: int, 1: string}
 */
function h6Run(Command $command, Container $app, array $params): array
{
    $command->setLaravel($app);
    $output = new BufferedOutput;
    $exit = $command->run(new ArrayInput($params), $output);

    return [$exit, $output->fetch()];
}

/** A signer that records what it was asked to sign and returns fixed bytes. */
final class Cc6RecordingSigner implements SignerInterface
{
    public ?Manifest $manifest = null;

    public ?MediaType $mediaType = null;

    public function __construct(private string $returns = 'SIGNED-BYTES') {}

    public function sign(Asset $asset, Manifest $manifest, ?Asset $parent = null): SignedAsset
    {
        $this->manifest = $manifest;
        $this->mediaType = $asset->mediaType;

        return new SignedAsset($this->returns, $asset->mediaType);
    }
}

function h6RecordingSigner(string $returns = 'SIGNED-BYTES'): Cc6RecordingSigner
{
    return new Cc6RecordingSigner($returns);
}

// --- AC1: `sign` command signs a file --------------------------------------

it('sign command signs a file', function () {
    $app = h6ConsoleApp();
    $signer = h6RecordingSigner('SIGNED-BYTES');
    $app->instance(SignerInterface::class, $signer);

    $in = h6TempFile('png');
    $out = h6DestPath();

    [$exit] = h6Run(new SignCommand, $app, ['input' => $in, 'output' => $out, '--agent' => 'ACME GenAI']);

    expect($exit)->toBe(0)
        ->and(file_get_contents($out))->toBe('SIGNED-BYTES')
        ->and($signer->mediaType)->toBe(MediaType::Png)
        ->and($signer->manifest?->assertions())->toEqual([h6AiAssertion(['name' => 'ACME GenAI'])]);
})->group('SPEC-006');

// --- AC2: `sign` command rejects an unsupported extension (error path) -----

it('sign command rejects an unsupported extension', function () {
    $app = h6ConsoleApp();
    $app->instance(SignerInterface::class, h6RecordingSigner());

    // .gif became supported in SPEC-021; .bmp is still outside the set.
    $in = h6TempFile('bmp');
    $out = h6DestPath('bmp');

    [$exit, $output] = h6Run(new SignCommand, $app, ['input' => $in, 'output' => $out, '--agent' => 'X']);

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('bmp')
        ->and(file_exists($out))->toBeFalse();
})->group('SPEC-006');

// --- AC3: `read` command reports a credential ------------------------------

it('read command reports a credential', function () {
    $app = h6ConsoleApp();

    $report = new ManifestReport(
        'urn:c2pa:test',
        new SignerInfo('C2PA Test Signing Cert', 'C2PA Signer', 'Es256'),
        [h6AiAssertion(['name' => 'ACME GenAI'])],
        ['signingCredential.untrusted'],
        ValidationState::Valid,
    );
    $app->instance(ReaderInterface::class, new class($report) implements ReaderInterface
    {
        public function __construct(private ManifestReport $report) {}

        public function read(Asset $asset): ManifestReport
        {
            return $this->report;
        }
    });

    // SPEC-020: the command reports which reader produced the report, and takes
    // the factory as a dependency. This harness builds a bare container rather
    // than registering the provider, so the binding is supplied here. A harness
    // gap, not a contract change — the command's output assertions are unchanged.
    $app->instance(ReaderFactory::class, new ReaderFactory(
        new Repository(['content-credentials' => ['reader' => 'service']]),
        new SigningServiceReader(new MockClient, new Psr17Factory, new Psr17Factory, new SigningServiceConfig('https://sign.test', 'k')),
    ));

    [$exit, $output] = h6Run(new ReadCommand, $app, ['file' => h6TempFile('png')]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('C2PA Test Signing Cert')
        // Label AND value. `toContain('Valid')` passed for any output at all:
        // `Valid` is a substring of the label `isSignatureValid`, so the
        // assertion held even with validationState removed from the command
        // entirely — measured 2026-08-13. Same shape as the `peak`/`speaks`
        // case this repository already documents.
        ->and($output)->toContain('validationState    : Valid');
})->group('SPEC-006');

/** Runs the read command over a report and returns its output. */
function h6ReadOutput(ManifestReport $report): string
{
    $app = h6ConsoleApp();

    $app->instance(ReaderInterface::class, new class($report) implements ReaderInterface
    {
        public function __construct(private ManifestReport $report) {}

        public function read(Asset $asset): ManifestReport
        {
            return $this->report;
        }
    });
    $app->instance(ReaderFactory::class, new ReaderFactory(
        new Repository(['content-credentials' => ['reader' => 'service']]),
        new SigningServiceReader(new MockClient, new Psr17Factory, new Psr17Factory, new SigningServiceConfig('https://sign.test', 'k')),
    ));

    [, $output] = h6Run(new ReadCommand, $app, ['file' => h6TempFile('png')]);

    return $output;
}

it('read command reports an asset with no credentials as empty, not as absent evidence', function () {
    // The path an unsigned upload takes. SPEC-010 makes a manifest-less asset an
    // empty report rather than an error, and every accessor then answers false
    // or empty — but nothing pinned what the COMMAND prints for it, and AC7 put
    // a new line into that output.
    //
    // The risk is specific: `timestamp` must read `absent`, never `present`.
    // An asset carrying no C2PA data at all has no timestamp to speak of, and a
    // report that said otherwise would be the absence-of-evidence-as-trust
    // conflation SPEC-013 exists to prevent — announced by the very field added
    // to avoid it.
    $output = h6ReadOutput(new ManifestReport(null, null, [], [], null));

    expect($output)->toContain('hasManifest        : false')
        ->and($output)->toContain('timestamp          : absent')
        ->and($output)->toContain('isTrusted          : false')
        ->and($output)->toContain('signer             : (none)')
        ->and($output)->toContain('digitalSourceTypes : (none)')
        ->and($output)->toContain('validationState    : (none)');
})->group('SPEC-006', 'SPEC-010');

it('read command distinguishes a timestamped manifest from one without', function () {
    // SPEC-006 AC7. Asserted as a DIFFERENCE between two runs: an assertion
    // that one output "mentions a timestamp" would pass for a hardcoded line,
    // and today has shown three times that asserting the absence of something
    // passes by default.
    $signer = new SignerInfo('C2PA Test Signing Cert', 'C2PA Signer', 'Es256');
    $assertions = [h6AiAssertion(['name' => 'ACME GenAI'])];

    $with = h6ReadOutput(new ManifestReport(
        'urn:c2pa:test', $signer, $assertions, [], ValidationState::Valid, hasTimestamp: true,
    ));
    $without = h6ReadOutput(new ManifestReport(
        'urn:c2pa:test', $signer, $assertions, [], ValidationState::Valid,
    ));

    expect($with)->toContain('timestamp          : present (unverified)')
        ->and($without)->toContain('timestamp          : absent')
        ->and($with)->not->toBe($without)
        // AC7's second half: the report may not read as proof of time. `true`
        // beside `isTrusted: false` is the conflation SPEC-013 exists to stop,
        // so the value states presence and says outright that nothing verified
        // the timestamp authority's own certificate.
        ->and($with)->not->toContain('timestamp          : true')
        ->and($with)->toContain('unverified');
})->group('SPEC-006', 'SPEC-007');

it('cannot be restyled by a signer name out of an untrusted manifest', function () {
    // Measured 2026-08-13, before the fix: Symfony's OutputFormatter reads
    // `<...>` as markup, so an issuer of `Acme <fg=black;bg=black>` emitted
    // ESC[30;40m and rendered every FOLLOWING line black-on-black — including
    // `isTrusted`. Whoever produced the asset chooses that string, and the
    // operator reading it is exactly who this command is for.
    $app = h6ConsoleApp();

    $report = new ManifestReport(
        'urn:c2pa:test',
        new SignerInfo('Acme <fg=black;bg=black>', '</>Hidden', 'Es256'),
        [h6AiAssertion(['name' => 'ACME GenAI'])],
        [],
        ValidationState::Valid,
    );
    $app->instance(ReaderInterface::class, new class($report) implements ReaderInterface
    {
        public function __construct(private ManifestReport $report) {}

        public function read(Asset $asset): ManifestReport
        {
            return $this->report;
        }
    });
    $app->instance(ReaderFactory::class, new ReaderFactory(
        new Repository(['content-credentials' => ['reader' => 'service']]),
        new SigningServiceReader(new MockClient, new Psr17Factory, new Psr17Factory, new SigningServiceConfig('https://sign.test', 'k')),
    ));

    [$exit, $output] = h6Run(new ReadCommand, $app, ['file' => h6TempFile('png')]);

    expect($exit)->toBe(0)
        // The issuer survives VERBATIM. This is the assertion that fails
        // without escape(), and finding one that does took a second attempt:
        // asserting the absence of an ANSI sequence passes either way here,
        // because this harness writes to an UNDECORATED BufferedOutput and an
        // undecorated formatter strips the tag instead of colouring with it.
        //
        // Which is the second harm, and the quieter one. Unescaped, the report
        // prints `signer             : Acme ` — the rest of the name is gone,
        // in every environment rather than only in a terminal. An issuer can
        // therefore delete itself from an operator's report.
        ->and($output)->toContain('Acme <fg=black;bg=black>')
        ->and($output)->toContain('</>Hidden')
        // And the verdict the tag would colour out in a real terminal is still
        // there to be read.
        ->and($output)->toContain('isTrusted          : false');
})->group('SPEC-006');

// --- AC8: control characters out of an untrusted manifest -------------------

it('strips control characters out of an untrusted manifest before printing', function () {
    // AC8. The sibling test above covers the MARKUP half of this attack, which
    // was fixed on 2026-08-13. This is the other half, and it needs no
    // formatter cooperation at all: OutputFormatter::escape() is one
    // preg_replace over `<`, `>` and a trailing backslash, so a raw ESC (0x1B)
    // walks straight through it into the terminal.
    //
    // Measured end-to-end through the running service before this test existed:
    // an asset signed with a digitalSourceType ending in ESC[30;40m reads back
    // with the bytes intact (hex tail 656469611b5b33303b34306d, validationState
    // Valid). digitalSourceTypes and signer print BEFORE the verdict lines and
    // nothing writes an SGR reset, so the attribute persists over `isTrusted`.
    $app = h6ConsoleApp();

    $report = new ManifestReport(
        'urn:c2pa:test',
        // ESC[2J ESC[H clears the screen and homes the cursor; the CR in the
        // common name returns to column 0 to overwrite what was just printed.
        new SignerInfo("Acme\x1b[2J\x1b[H", "CN\rOverwrite", 'Es256'),
        [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [[
            'action' => 'c2pa.created',
            'digitalSourceType' => AI_URI_6."\x1b[30;40m",
            'softwareAgent' => ['name' => 'ACME GenAI'],
        ]]]]],
        [],
        ValidationState::Valid,
    );
    $app->instance(ReaderInterface::class, new class($report) implements ReaderInterface
    {
        public function __construct(private ManifestReport $report) {}

        public function read(Asset $asset): ManifestReport
        {
            return $this->report;
        }
    });
    $app->instance(ReaderFactory::class, new ReaderFactory(
        new Repository(['content-credentials' => ['reader' => 'service']]),
        new SigningServiceReader(new MockClient, new Psr17Factory, new Psr17Factory, new SigningServiceConfig('https://sign.test', 'k')),
    ));

    [$exit, $output] = h6Run(new ReadCommand, $app, ['file' => h6TempFile('png')]);

    expect($exit)->toBe(0)
        // Present-shaped, per the rule this repository keeps relearning: pin
        // what the line MUST say, not what it must lack. Stripping removes the
        // ESC and leaves its printable tail, so `[30;40m` stays as literal
        // text — inert, and visible evidence that something was in there.
        ->and($output)->toContain('digitalSourceTypes : '.AI_URI_6.'[30;40m')
        ->and($output)->toContain('signer             : Acme[2J[H / CN=CNOverwrite')
        // AC3 still sees the issuer: the printable part is not truncated.
        ->and($output)->toContain('Acme')
        // And every verdict line below the injection is printed in full.
        ->and($output)->toContain('validationState    : Valid')
        ->and($output)->toContain('isSignatureValid   : true')
        ->and($output)->toContain('isTrusted          : false')
        // The whole stream is free of C0/C1 controls except tab and newline.
        // Expressed as an equality on a computed count rather than as
        // not->toContain(), so it states a fact instead of an absence.
        ->and(preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $output))->toBe(0);
})->group('SPEC-006');

// --- AC4: `SignAssetJob` signs and writes ----------------------------------

it('SignAssetJob signs the source and writes the destination', function () {
    $in = h6TempFile('png', 'SOURCE-BYTES');
    $out = h6DestPath();
    $signer = h6RecordingSigner('SIGNED-BYTES');

    (new SignAssetJob($in, $out, MediaType::Png, 'ACME GenAI'))->handle($signer);

    expect(file_get_contents($out))->toBe('SIGNED-BYTES')
        ->and($signer->manifest?->assertions())->toEqual([h6AiAssertion(['name' => 'ACME GenAI'])]);
})->group('SPEC-006');

// --- AC5: `SignAssetJob` is a bounded, retrying queue job ------------------

it('SignAssetJob is a bounded, retrying queue job', function () {
    $job = new SignAssetJob('a.png', 'b.png', MediaType::Png, 'X');

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBeGreaterThan(1)
        ->and($job->backoff())->not->toBeEmpty();
})->group('SPEC-006');

// --- AC6: `SignAssetJob` surfaces a signing failure (error path) -----------

it('SignAssetJob lets a signing failure propagate and leaves no output', function () {
    $in = h6TempFile('png');
    $out = h6DestPath();
    $failing = new class implements SignerInterface
    {
        public function sign(Asset $asset, Manifest $manifest, ?Asset $parent = null): SignedAsset
        {
            throw new SigningTransportException('boom');
        }
    };

    expect(fn () => (new SignAssetJob($in, $out, MediaType::Png, 'X'))->handle($failing))
        ->toThrow(SigningTransportException::class);

    expect(file_exists($out))->toBeFalse();
})->group('SPEC-006');

// --- Defect fix: a failed write is surfaced, not reported as success --------

it('sign command reports a failed write instead of succeeding', function () {
    $app = h6ConsoleApp();
    $app->instance(SignerInterface::class, h6RecordingSigner('SIGNED-BYTES'));

    $in = h6TempFile('png');
    // Parent directory does not exist -> file_put_contents fails.
    $out = sys_get_temp_dir().'/cc6_missing_'.uniqid('', true).'/out.png';

    [$exit, $output] = h6Run(new SignCommand, $app, ['input' => $in, 'output' => $out, '--agent' => 'X']);

    expect($exit)->not->toBe(0)
        ->and($output)->toContain($out)
        ->and(file_exists($out))->toBeFalse();
})->group('SPEC-006');

it('SignAssetJob throws when the destination cannot be written', function () {
    $in = h6TempFile('png', 'SOURCE-BYTES');
    $out = sys_get_temp_dir().'/cc6_missing_'.uniqid('', true).'/out.png';

    expect(fn () => (new SignAssetJob($in, $out, MediaType::Png, 'X'))->handle(h6RecordingSigner()))
        ->toThrow(RuntimeException::class);

    expect(file_exists($out))->toBeFalse();
})->group('SPEC-006');
