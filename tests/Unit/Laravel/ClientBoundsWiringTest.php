<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Laravel\ContentCredentialsServiceProvider;
use Provemark\ContentCredentials\Laravel\Exception\MissingConfigurationException;
use Provemark\ContentCredentials\Laravel\Support\AtomicWrite;
use Psr\Log\AbstractLogger;

/**
 * SPEC-025 AC3 + AC5 — the Laravel half.
 *
 * AC3's decision lives here rather than in Core: Core states the fact
 * (`usesInsecureTransport()`), the framework layer decides what it is worth,
 * because Core has no logger by design.
 *
 * @see specs/SPEC-025-client-side-bounds.md
 */

/** @param array<string, mixed> $service */
function cc25App(array $service): Container
{
    $app = new Container;
    Container::setInstance($app);
    $app->instance('config', new Repository(['content-credentials' => ['service' => $service]]));

    return $app;
}

/** A logger that records what it was told. */
final class Cc25RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($level === 'warning') {
            $this->warnings[] = (string) $message;
        }
    }
}

// --- AC3: insecure transport is visible -------------------------------------

it('warns when the service is reached over plain HTTP across a network', function () {
    $app = cc25App(['base_url' => 'http://signer.example.com:3000', 'api_key' => 'k']);
    $logger = new Cc25RecordingLogger;
    $app->instance('log', $logger);

    (new ContentCredentialsServiceProvider($app))->register();
    $app->make(SigningServiceConfig::class);

    expect($logger->warnings)->toHaveCount(1)
        ->and($logger->warnings[0])->toContain('plain HTTP')
        ->and($logger->warnings[0])->toContain('signer.example.com')
        // The warning must say how to act on it, or it is just noise.
        ->and($logger->warnings[0])->toContain('require_secure_transport');
})->group('SPEC-025');

it('stays silent for loopback over plain HTTP', function () {
    // The documented deployment: the service publishes on 127.0.0.1 and nothing
    // leaves the machine. Warning here would train people to ignore the warning.
    $app = cc25App(['base_url' => 'http://localhost:3000', 'api_key' => 'k']);
    $logger = new Cc25RecordingLogger;
    $app->instance('log', $logger);

    (new ContentCredentialsServiceProvider($app))->register();
    $app->make(SigningServiceConfig::class);

    expect($logger->warnings)->toBe([]);
})->group('SPEC-025');

it('refuses to build the config when strict transport is required', function () {
    $app = cc25App([
        'base_url' => 'http://signer.example.com:3000',
        'api_key' => 'k',
        'require_secure_transport' => true,
    ]);

    (new ContentCredentialsServiceProvider($app))->register();

    expect(fn () => $app->make(SigningServiceConfig::class))
        ->toThrow(MissingConfigurationException::class);
})->group('SPEC-025');

it('does not crash when the application has no logger', function () {
    // A bare container has no 'log' binding. A missing logger must not turn a
    // warning into a fatal — that would make the protection worse than absent.
    $app = cc25App(['base_url' => 'http://signer.example.com:3000', 'api_key' => 'k']);

    (new ContentCredentialsServiceProvider($app))->register();

    expect($app->make(SigningServiceConfig::class))->toBeInstanceOf(SigningServiceConfig::class);
})->group('SPEC-025');

it('carries the configured request bound into the config', function () {
    $app = cc25App(['base_url' => 'https://sign.test', 'api_key' => 'k', 'max_request_bytes' => 4096]);

    (new ContentCredentialsServiceProvider($app))->register();

    expect($app->make(SigningServiceConfig::class)->maxRequestBytes)->toBe(4096);
})->group('SPEC-025');

// --- AC5: a signed file appears whole or not at all -------------------------

it('leaves no temporary file behind after a successful write', function () {
    $dir = sys_get_temp_dir().'/cc25-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $path = $dir.'/signed.png';

    expect(AtomicWrite::toPath($path, 'SIGNED-BYTES'))->toBeTrue()
        ->and(file_get_contents($path))->toBe('SIGNED-BYTES');

    // The temporary file lives in the destination's own directory, so a leak
    // would show up right here.
    $left = array_values(array_diff((array) scandir($dir), ['.', '..', 'signed.png']));
    expect($left)->toBe([]);

    unlink($path);
    rmdir($dir);
})->group('SPEC-025');

it('writes nothing at all when the destination directory does not exist', function () {
    $path = sys_get_temp_dir().'/cc25-missing-'.bin2hex(random_bytes(4)).'/signed.png';

    expect(AtomicWrite::toPath($path, 'SIGNED-BYTES'))->toBeFalse()
        ->and(file_exists($path))->toBeFalse();
})->group('SPEC-025');

it('replaces an existing file without an intermediate empty state', function () {
    // What rename() buys: the destination never exists in a half-written state.
    // Observing that directly is a race, so this asserts the property that can
    // be observed — the old content is replaced wholesale, never truncated.
    $dir = sys_get_temp_dir().'/cc25-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $path = $dir.'/signed.png';
    file_put_contents($path, 'OLD-CONTENT-THAT-IS-LONGER');

    expect(AtomicWrite::toPath($path, 'NEW'))->toBeTrue()
        ->and(file_get_contents($path))->toBe('NEW');

    unlink($path);
    rmdir($dir);
})->group('SPEC-025');
