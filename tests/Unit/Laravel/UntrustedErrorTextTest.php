<?php

declare(strict_types=1);

use Http\Mock\Client as MockClient;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Nyholm\Psr7\Factory\Psr17Factory;
use Provemark\ContentCredentials\Core\Manifest\Manifest;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadFailedException;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Reading\ReaderInterface;
use Provemark\ContentCredentials\Core\Reading\SigningServiceReader;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningFailedException;
use Provemark\ContentCredentials\Core\Signing\SignedAsset;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Support\ServiceError;
use Provemark\ContentCredentials\Laravel\Console\ReadCommand;
use Provemark\ContentCredentials\Laravel\Console\SignCommand;
use Provemark\ContentCredentials\Laravel\ReaderFactory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * SPEC-040 — untrusted error text may not reach a terminal intact.
 *
 * SPEC-006 AC8 neutralised the manifest values `content-credentials:read`
 * prints. The same class of value reaches the same terminal through the
 * EXCEPTION path, and there it comes from an asset rather than from the service.
 *
 * Measured before these tests existed (2026-09-05, ext-c2pa 0.1.0): c2pa-rs
 * quotes four raw asset bytes on a signature mismatch — `expected "RIFF", got
 * "ZZZZ"` — and a complete CSI sequence fits in four bytes. `ESC[2J` (clear the
 * screen), `ESC[7m` (reverse video) and `ESC[1m` all landed in the exception
 * message intact and all survived `OutputFormatter::escape()`.
 *
 * @see specs/SPEC-040-untrusted-error-text.md
 */

/** Complete CSI sequences that fit the four bytes c2pa-rs quotes back. */
const CC40_CLEAR = "\x1b[2J";

const CC40_REVERSE = "\x1b[7m";

final class Cc40Container extends Container
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

function cc40App(): Container
{
    $app = new Cc40Container;
    Container::setInstance($app);

    return $app;
}

function cc40TempFile(string $ext): string
{
    $path = sys_get_temp_dir().'/cc40_'.uniqid('', true).'.'.$ext;
    file_put_contents($path, 'DUMMY-BYTES');

    return $path;
}

/**
 * @param  array<string, mixed>  $params
 * @return array{0: int, 1: string}
 */
function cc40Run(Command $command, Container $app, array $params): array
{
    $command->setLaravel($app);
    $output = new BufferedOutput;
    $exit = $command->run(new ArrayInput($params), $output);

    return [$exit, $output->fetch()];
}

/** Count C0/C1 control characters, excluding tab and newline. */
function cc40Controls(string $text): int
{
    $count = preg_match_all('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', $text);

    // preg_match_all() answers false only on a malformed pattern; this one is a
    // literal. Normalised so the caller can compare against 0 either way.
    return $count === false ? 0 : $count;
}

/** A reader that fails the way ExtC2paReader does on a crafted asset. */
function cc40FailingReader(string $message): ReaderInterface
{
    return new class($message) implements ReaderInterface
    {
        public function __construct(private string $message) {}

        public function read(Asset $asset): ManifestReport
        {
            throw new ReadFailedException($this->message);
        }
    };
}

function cc40Factory(Container $app): ReaderFactory
{
    return new ReaderFactory(
        new Repository(['content-credentials' => ['reader' => 'service']]),
        new SigningServiceReader(new MockClient, new Psr17Factory, new Psr17Factory, new SigningServiceConfig('https://sign.test', 'k')),
    );
}

// --- AC1: a crafted asset cannot move the operator's cursor -----------------

it('strips control characters out of a failed read before printing', function () {
    // The message is the one measured through ext-c2pa, verbatim: c2pa-rs
    // echoes the four bytes it found where it expected "RIFF".
    $app = cc40App();
    $app->instance(ReaderInterface::class, cc40FailingReader(
        'Could not read the asset: c2pa read/validate failed: error parsing RIFF: '
        .'invalid file signature: invalid header: expected "RIFF", got "'.CC40_CLEAR.'"'
    ));
    $app->instance(ReaderFactory::class, cc40Factory($app));

    [, $output] = cc40Run(new ReadCommand, $app, ['file' => cc40TempFile('webp')]);

    // Present-shaped, per the rule this repository keeps relearning: pin what
    // the line MUST say. Stripping leaves the printable tail, so `[2J` stays as
    // literal text — inert, and visible evidence that something was in there.
    expect($output)->toContain('expected "RIFF", got "[2J"')
        ->and(cc40Controls($output))->toBe(0);
})->group('SPEC-040');

it('keeps the printable remainder so the operator can see what failed', function () {
    $app = cc40App();
    $app->instance(ReaderInterface::class, cc40FailingReader(
        'error parsing RIFF: '.CC40_REVERSE.'invalid file signature'
    ));
    $app->instance(ReaderFactory::class, cc40Factory($app));

    [, $output] = cc40Run(new ReadCommand, $app, ['file' => cc40TempFile('webp')]);

    expect($output)->toContain('error parsing RIFF')
        ->and($output)->toContain('invalid file signature')
        ->and(cc40Controls($output))->toBe(0);
})->group('SPEC-040');

// --- AC2: a failed read is a command failure, not an exception --------------

it('reports a failed read as a command failure instead of throwing', function () {
    // Before this spec, ReadCommand called read() outside every try: the only
    // catch covered UnsupportedMediaTypeException from the type inference above
    // it. So the exception left handle() and was rendered by the console, which
    // is the route AC8's filter does not sit on.
    $app = cc40App();
    $app->instance(ReaderInterface::class, cc40FailingReader('engine said no'));
    $app->instance(ReaderFactory::class, cc40Factory($app));

    $file = cc40TempFile('webp');

    [$exit, $output] = cc40Run(new ReadCommand, $app, ['file' => $file]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('engine said no')
        // Naming the file is what makes the failure actionable when the command
        // is pointed at a directory of uploads.
        ->and($output)->toContain(basename($file));
})->group('SPEC-040');

// --- AC3: the same for a hostile signing service ----------------------------

it('strips control characters out of a signing failure before printing', function () {
    $app = cc40App();
    $app->instance(SignerInterface::class, new class implements SignerInterface
    {
        public function sign(Asset $asset, Manifest $manifest, ?Asset $parent = null): SignedAsset
        {
            throw new SigningFailedException(
                'Signing service returned HTTP 400: bad '.CC40_REVERSE.'request'
            );
        }
    });

    [$exit, $output] = cc40Run(new SignCommand, $app, [
        'input' => cc40TempFile('png'),
        'output' => sys_get_temp_dir().'/cc40_out_'.uniqid('', true).'.png',
        '--agent' => 'ACME GenAI',
    ]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('bad [7mrequest')
        ->and(cc40Controls($output))->toBe(0);
})->group('SPEC-040');

// --- AC6: malformed message input does not throw ----------------------------

it('neutralises an empty message and one that is not valid UTF-8', function () {
    // ServiceError::cap() may assume valid UTF-8 because json_decode()
    // guarantees it. The extension's message carries no such guarantee, so the
    // shared neutraliser may not inherit that assumption — its regex is
    // byte-based for exactly this reason.
    $app = cc40App();
    $app->instance(ReaderInterface::class, cc40FailingReader("\xC3\x28".CC40_CLEAR.'tail'));
    $app->instance(ReaderFactory::class, cc40Factory($app));

    [$exit, $output] = cc40Run(new ReadCommand, $app, ['file' => cc40TempFile('webp')]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('tail')
        ->and(cc40Controls($output))->toBe(0);
})->group('SPEC-040');

it('survives an empty failure message', function () {
    $app = cc40App();
    $app->instance(ReaderInterface::class, cc40FailingReader(''));
    $app->instance(ReaderFactory::class, cc40Factory($app));

    [$exit, $output] = cc40Run(new ReadCommand, $app, ['file' => cc40TempFile('webp')]);

    expect($exit)->toBe(1)
        ->and($output)->not->toBe('');
})->group('SPEC-040');

// --- AC8: accessors still return verbatim -----------------------------------

it('leaves manifest accessors returning their values byte for byte', function () {
    // SPEC-033 AC4 continues to hold: this spec neutralises what a COMMAND
    // prints, never what an accessor returns. A caller rendering to HTML needs
    // the bytes; the terminal is the sink that does not.
    $report = new ManifestReport(
        'urn:c2pa:test',
        null,
        [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [[
            'action' => 'c2pa.created',
            'digitalSourceType' => 'http://example.test/type'.CC40_CLEAR,
            'softwareAgent' => ['name' => 'ACME'.CC40_REVERSE],
        ]]]]],
        [],
        null,
    );

    expect($report->digitalSourceTypes())->toBe(['http://example.test/type'.CC40_CLEAR])
        ->and($report->softwareAgents()[0]->name)->toBe('ACME'.CC40_REVERSE);
})->group('SPEC-040');

// --- AC5: the wrapped extension message is bounded --------------------------

it('leaves a message that is already within the bound untouched', function () {
    expect(ServiceError::bound('error parsing RIFF'))->toBe('error parsing RIFF');
})->group('SPEC-040');

it('truncates a message past the bound and says that it did', function () {
    $long = str_repeat('x', 400);

    $bounded = ServiceError::bound($long);

    expect($bounded)->toEndWith('… (truncated)')
        ->and($bounded)->toStartWith(str_repeat('x', 256))
        // 256 characters plus the marker, and nothing of the tail.
        ->and(mb_strlen($bounded))->toBe(256 + mb_strlen('… (truncated)'));
})->group('SPEC-040');

it('still bounds a long message that is not valid UTF-8', function () {
    // The branch that exists for ext-c2pa. `cap()` may assume valid UTF-8
    // because json_decode() guarantees it; this input has no such guarantee, and
    // the `/u` pattern fails on it — which must bound the message, not discard
    // it. Asserted as a LENGTH and a retained prefix, because "it did not throw"
    // would pass on an empty return.
    $long = "\xC3\x28".str_repeat('y', 400);

    $bounded = ServiceError::bound($long);

    expect(strlen($bounded))->toBeLessThan(strlen($long))
        ->and($bounded)->toContain('yyy')
        ->and($bounded)->toEndWith('… (truncated)');
})->group('SPEC-040');

// --- AC4: one neutraliser, not two ------------------------------------------

it('neutralises identically on the read path and on the sign path', function () {
    // "One implementation" is only observable as behaviour: give both commands
    // the same hostile string and require the same rendering. Two copies of the
    // rule would drift, which is the argument that keeps ManifestStoreParser a
    // single decoder — and the escaping of 2026-08-13 is this package's record
    // of one control reaching half its sinks.
    $hostile = 'boom '.CC40_CLEAR.CC40_REVERSE."\x7F <tag> tail";

    $app = cc40App();
    $app->instance(ReaderInterface::class, cc40FailingReader($hostile));
    $app->instance(ReaderFactory::class, cc40Factory($app));
    [, $readOutput] = cc40Run(new ReadCommand, $app, ['file' => cc40TempFile('webp')]);

    $app2 = cc40App();
    $app2->instance(SignerInterface::class, new class($hostile) implements SignerInterface
    {
        public function __construct(private string $message) {}

        public function sign(Asset $asset, Manifest $manifest, ?Asset $parent = null): SignedAsset
        {
            throw new SigningFailedException($this->message);
        }
    });
    [, $signOutput] = cc40Run(new SignCommand, $app2, [
        'input' => cc40TempFile('png'),
        'output' => sys_get_temp_dir().'/cc40_out_'.uniqid('', true).'.png',
        '--agent' => 'ACME GenAI',
    ]);

    $expected = 'boom [2J[7m <tag> tail';

    expect($readOutput)->toContain($expected)
        ->and($signOutput)->toContain($expected)
        ->and(cc40Controls($readOutput))->toBe(0)
        ->and(cc40Controls($signOutput))->toBe(0);
})->group('SPEC-040');

// --- AC7: reading through the service is unchanged --------------------------

it('reports a service-side read failure in the service own wording', function () {
    // The asset vector does not exist on this path: a failed read answers with
    // what the service said, which is our own text and carries no asset bytes.
    // Pinned so that narrowing the extension path cannot quietly change it.
    $client = new MockClient;
    $client->addResponse((new Psr17Factory)->createResponse(500)->withBody(
        (new Psr17Factory)->createStream('{"error":"read failed","cid":"abc"}')
    ));

    $reader = new SigningServiceReader(
        $client, new Psr17Factory, new Psr17Factory,
        new SigningServiceConfig('https://sign.test', 'k'),
    );

    $app = cc40App();
    $app->instance(ReaderInterface::class, $reader);
    $app->instance(ReaderFactory::class, cc40Factory($app));

    [$exit, $output] = cc40Run(new ReadCommand, $app, ['file' => cc40TempFile('png')]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('read failed')
        ->and(cc40Controls($output))->toBe(0);
})->group('SPEC-040');
