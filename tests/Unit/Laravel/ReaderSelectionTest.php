<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\Exception\ExtensionMissingException;
use Provemark\ContentCredentials\Core\Reading\ExtC2paReader;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Reading\ReaderInterface;
use Provemark\ContentCredentials\Core\Reading\SigningServiceReader;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Laravel\Console\ReadCommand;
use Provemark\ContentCredentials\Laravel\ContentCredentialsServiceProvider;
use Provemark\ContentCredentials\Laravel\Exception\MissingConfigurationException;
use Provemark\ContentCredentials\Laravel\ReaderFactory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * SPEC-020 — which reader the Laravel container binds.
 *
 * SPEC-019 added a second `ReaderInterface` implementation but stopped at `Core`,
 * so the provider still bound one reader unconditionally and a Laravel
 * application that installed ext-c2pa kept getting HTTP everywhere the container
 * was involved.
 *
 * The decision that shapes this file: autodetection is a MODE, not the default.
 * Installing an unrelated extension must not silently change which c2pa-rs
 * version — 0.89.0 in the extension, 0.90.5 in the service — decides an
 * application's trust verdicts.
 *
 * Bare illuminate/container + illuminate/config harness, matching SPEC-004's
 * tests. No testbench, no running service.
 *
 * @see specs/SPEC-020-laravel-reader-selection.md
 */
$skipUnlessExtension = fn () => ! extension_loaded('c2pa')
    ? 'ext-c2pa not installed — install it with `pie install ericmann/ext-c2pa`'
    : false;

$skipIfExtension = fn () => extension_loaded('c2pa')
    ? 'ext-c2pa is loaded — this criterion is about its absence'
    : false;

// Pest 4 binds `$this` in a test closure such that static analysis resolves it
// to Pest\PendingCalls\TestCall, where markTestSkipped() does not exist. It
// still works at runtime, but a skip expressed as a ->skip() closure is the
// idiom this file already uses everywhere else, and it is evaluated before the
// body rather than part-way through it.
$skipUnlessSignedFixture = fn () => ! is_file(dirname(__DIR__, 3).'/out/signed.png')
    ? 'out/signed.png not present — run php bin/e2e.php first'
    : false;

/**
 * A container configured with an optional `reader` mode and trust anchors.
 *
 * `null` for $reader means the key is ABSENT, which is a distinct case from any
 * of the three modes — it is what every existing install has.
 *
 * @param  array<string, mixed>  $extra
 */
function ccReaderApp(?string $reader = null, array $extra = []): Container
{
    // Illuminate's Command::run() calls runningUnitTests() on the application,
    // which a bare Container does not have. Same shim as SPEC-006's harness.
    $app = new class extends Container
    {
        public function runningUnitTests(): bool
        {
            return true;
        }

        public function runningInConsole(): bool
        {
            return true;
        }
    };
    Container::setInstance($app);

    $config = ['service' => ['base_url' => 'https://sign.test', 'api_key' => 'secret']] + $extra;

    if ($reader !== null) {
        $config['reader'] = $reader;
    }

    $app->instance('config', new Repository(['content-credentials' => $config]));
    Facade::setFacadeApplication($app);
    Facade::clearResolvedInstances();

    (new ContentCredentialsServiceProvider($app))->register();

    return $app;
}

// --- AC1: `service` binds the HTTP reader ------------------------------------

it('binds the service reader when the mode is service', function () {
    expect(ccReaderApp('service')->make(ReaderInterface::class))
        ->toBeInstanceOf(SigningServiceReader::class);
})->group('SPEC-020');

it('still binds the service reader when the extension is available', function () {
    // The half that makes the setting a control rather than a hint. Without this
    // an implementation that ignores `service` and always autodetects passes.
    expect(ccReaderApp('service')->make(ReaderInterface::class))
        ->toBeInstanceOf(SigningServiceReader::class);
})->group('SPEC-020')->skip($skipUnlessExtension);

// --- AC3: `auto` follows availability, and is not the default ----------------

it('binds the in-process reader under auto when the extension is available', function () {
    expect(ccReaderApp('auto')->make(ReaderInterface::class))
        ->toBeInstanceOf(ExtC2paReader::class);
})->group('SPEC-020')->skip($skipUnlessExtension);

it('falls back to the service reader under auto when the extension is absent', function () {
    expect(ccReaderApp('auto')->make(ReaderInterface::class))
        ->toBeInstanceOf(SigningServiceReader::class);
})->group('SPEC-020')->skip($skipIfExtension);

it('defaults to the service reader when no mode is configured', function () {
    // The criterion the whole default decision rests on: an application that
    // installs the extension for an unrelated reason must not change engines on
    // its own. This runs everywhere, and on a machine WITH the extension it is
    // the one that would catch a default of `auto`.
    expect(ccReaderApp()->make(ReaderInterface::class))
        ->toBeInstanceOf(SigningServiceReader::class);
})->group('SPEC-020');

it('binds the reader once', function () {
    $app = ccReaderApp('service');

    expect($app->make(ReaderInterface::class))->toBe($app->make(ReaderInterface::class));
})->group('SPEC-020');

// --- AC4: `extension` without the extension fails loudly ---------------------

it('throws when the extension mode is set and the extension is missing', function () {
    ccReaderApp('extension')->make(ReaderInterface::class);
})->throws(ExtensionMissingException::class)
    ->group('SPEC-020')
    ->skip($skipIfExtension);

it('does not quietly fall back to the service reader', function () {
    // An application that asked for in-process reading and silently got HTTP
    // cannot tell — the same reason SPEC-019 AC5 refuses a fallback. Asserting
    // the *type* of the failure, because "it threw something" would also pass
    // for a misconfiguration error unrelated to the extension.
    try {
        $reader = ccReaderApp('extension')->make(ReaderInterface::class);
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(
            ExtensionMissingException::class,
        );

        return;
    }

    throw new RuntimeException(
        'binding succeeded without the extension and returned '.$reader::class,
    );
})->group('SPEC-020')->skip($skipIfExtension);

// --- AC2 + AC7: `extension` binds the in-process reader, with anchors --------

it('binds the in-process reader when the mode is extension', function () {
    expect(ccReaderApp('extension')->make(ReaderInterface::class))
        ->toBeInstanceOf(ExtC2paReader::class);
})->group('SPEC-020')->skip($skipUnlessExtension);

it('passes configured trust anchors to the in-process reader', function () {
    // AC7. Asserted through behaviour rather than by inspecting a private
    // property: with the repository's own anchors, a signed asset reads back as
    // trusted; the same asset under no anchors does not. Anything weaker would
    // pass for a reader that accepted the config and dropped it.
    $anchors = (string) file_get_contents(dirname(__DIR__, 3).'/certs/trust_anchors.pem');
    $signed = dirname(__DIR__, 3).'/out/signed.png';

    $asset = new Asset(
        (string) file_get_contents($signed),
        MediaType::Png,
    );

    $withAnchors = ccReaderApp('extension', ['trust_anchors' => $anchors])
        ->make(ReaderInterface::class)
        ->read($asset);

    $without = ccReaderApp('extension')->make(ReaderInterface::class)->read($asset);

    expect($withAnchors->isTrusted())->toBeTrue('configured anchors did not reach the reader')
        ->and($without->isTrusted())->toBeFalse('trusted without any anchors configured');
})->group('SPEC-020')->skip($skipUnlessExtension)->skip($skipUnlessSignedFixture);

it('accepts trust anchors given as a path as well as as contents', function () {
    // A path is what people will reach for, and NOTES Step 11 records that every
    // trust surface underneath us takes contents — silently verifying nothing, or
    // throwing, when given a path. This layer is where that gets absorbed.
    $path = dirname(__DIR__, 3).'/certs/trust_anchors.pem';
    $signed = dirname(__DIR__, 3).'/out/signed.png';

    $report = ccReaderApp('extension', ['trust_anchors' => $path])
        ->make(ReaderInterface::class)
        ->read(new Asset(
            (string) file_get_contents($signed),
            MediaType::Png,
        ));

    expect($report->isTrusted())->toBeTrue('a path was not resolved to PEM contents');
})->group('SPEC-020')->skip($skipUnlessExtension)->skip($skipUnlessSignedFixture);

// --- AC5: an unrecognised mode is refused ------------------------------------

it('refuses a mode it does not recognise', function (string $mode) {
    // Defaulting a typo to `auto` — or to anything — is the silent-degradation
    // shape this project keeps meeting. An empty string is included because it
    // is what an unset env var produces.
    expect(fn () => ccReaderApp($mode)->make(ReaderInterface::class))
        ->toThrow(MissingConfigurationException::class);
})->with([
    'typo' => 'ext',
    'empty string' => '',
    'wrong case' => 'Service',
    'nonsense' => 'nope',
])->group('SPEC-020');

it('names the modes it accepts when refusing', function () {
    try {
        ccReaderApp('ext')->make(ReaderInterface::class);
        $message = '';
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('auto')
        ->and($message)->toContain('service')
        ->and($message)->toContain('extension');
})->group('SPEC-020');

// --- AC6: the application can see which reader it got ------------------------

it('reports the resolved mode without inspecting class names', function () {
    // Two c2pa-rs versions are in play, so "which engine answered?" has to be
    // answerable in a bug report. `auto` must resolve to a concrete answer here,
    // not report itself back.
    expect(ccReaderApp('service')->make(ReaderFactory::class)->mode())->toBe('service');

    $auto = ccReaderApp('auto')->make(ReaderFactory::class)->mode();
    expect($auto)->toBe(extension_loaded('c2pa') ? 'extension' : 'service')
        ->and($auto)->not->toBe('auto', 'auto reported itself instead of what it resolved to');
})->group('SPEC-020');

it('reports the mode with no reader configured', function () {
    expect(ccReaderApp()->make(ReaderFactory::class)->mode())->toBe('service');
})->group('SPEC-020');

it('prints the resolved reader mode in the read command', function () {
    // The other half of AC6. `mode()` being callable is not the same as anyone
    // seeing it: the command output is where someone already is when they wonder
    // which engine produced a report.
    $app = ccReaderApp('service');

    $app->instance(ReaderInterface::class, new class implements ReaderInterface
    {
        public function read(Asset $asset): ManifestReport
        {
            return new ManifestReport(null, null, [], [], null);
        }
    });

    $file = tempnam(sys_get_temp_dir(), 'spec020').'.png';
    file_put_contents($file, "\x89PNG\r\n\x1a\n");

    $command = new ReadCommand;
    $command->setLaravel($app);
    $output = new BufferedOutput;
    $command->run(new ArrayInput(['file' => $file]), $output);

    @unlink($file);

    expect($output->fetch())->toContain('reader             : service (configured: service)');
})->group('SPEC-020');

/** Runs the read command against a stub reader and returns its output. */
function ccReadOutput(string $mode): string
{
    $app = ccReaderApp($mode);

    $app->instance(ReaderInterface::class, new class implements ReaderInterface
    {
        public function read(Asset $asset): ManifestReport
        {
            return new ManifestReport(null, null, [], [], null);
        }
    });

    $file = tempnam(sys_get_temp_dir(), 'spec020').'.png';
    file_put_contents($file, "\x89PNG\r\n\x1a\n");

    $command = new ReadCommand;
    $command->setLaravel($app);
    $output = new BufferedOutput;
    $command->run(new ArrayInput(['file' => $file]), $output);

    @unlink($file);

    return $output->fetch();
}

it('distinguishes an auto-resolved engine from a configured one', function () {
    // AC8 (amended 2026-08-13). Both configurations resolve to the SAME engine
    // here, which is the whole point: if the command printed only the resolved
    // mode the two would be byte-identical, and a bug report could not say
    // whether the engine was chosen or detected. `auto` is not the default
    // precisely because an engine must not change itself; this is the evidence
    // for that having happened.
    //
    // Asserted as a difference between two real runs rather than as the absence
    // of something, because an assertion that something bad is missing passes
    // by default.
    $auto = ccReadOutput('auto');
    $explicit = ccReadOutput('extension');

    expect($auto)->toContain('(configured: auto)')
        ->and($explicit)->toContain('(configured: extension)')
        ->and($auto)->not->toBe($explicit);
})->group('SPEC-020')->skip($skipUnlessExtension);

it('names the engine and the configuration when auto falls back', function () {
    // The other side of auto: without the extension it resolves to service, and
    // the report still has to say the choice was not made by a human.
    expect(ccReadOutput('auto'))->toContain('reader             : service (configured: auto)');
})->group('SPEC-020')->skip($skipIfExtension);
