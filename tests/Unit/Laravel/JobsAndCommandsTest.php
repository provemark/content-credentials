<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Provemark\ContentCredentials\Core\Manifest\Manifest;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Reading\ReaderInterface;
use Provemark\ContentCredentials\Core\Reading\SignerInfo;
use Provemark\ContentCredentials\Core\Reading\ValidationState;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningTransportException;
use Provemark\ContentCredentials\Core\Signing\SignedAsset;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;
use Provemark\ContentCredentials\Laravel\Console\ReadCommand;
use Provemark\ContentCredentials\Laravel\Console\SignCommand;
use Provemark\ContentCredentials\Laravel\Jobs\SignAssetJob;
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

    public function sign(Asset $asset, Manifest $manifest): SignedAsset
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

    $in = h6TempFile('gif');
    $out = h6DestPath('gif');

    [$exit, $output] = h6Run(new SignCommand, $app, ['input' => $in, 'output' => $out, '--agent' => 'X']);

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('gif')
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

    [$exit, $output] = h6Run(new ReadCommand, $app, ['file' => h6TempFile('png')]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('C2PA Test Signing Cert')
        ->and($output)->toContain('Valid');
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
        public function sign(Asset $asset, Manifest $manifest): SignedAsset
        {
            throw new SigningTransportException('boom');
        }
    };

    expect(fn () => (new SignAssetJob($in, $out, MediaType::Png, 'X'))->handle($failing))
        ->toThrow(SigningTransportException::class);

    expect(file_exists($out))->toBeFalse();
})->group('SPEC-006');
