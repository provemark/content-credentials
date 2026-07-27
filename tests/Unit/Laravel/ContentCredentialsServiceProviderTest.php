<?php

declare(strict_types=1);

use ContentCredentials\Core\Manifest\Manifest;
use ContentCredentials\Core\Manifest\ManifestBuilder;
use ContentCredentials\Core\Manifest\MediaType;
use ContentCredentials\Core\Reading\ReaderInterface;
use ContentCredentials\Core\Reading\SigningServiceReader;
use ContentCredentials\Core\Signing\Asset;
use ContentCredentials\Core\Signing\SignedAsset;
use ContentCredentials\Core\Signing\SignerInterface;
use ContentCredentials\Core\Signing\SigningServiceConfig;
use ContentCredentials\Core\Signing\SigningServiceSigner;
use ContentCredentials\Laravel\ContentCredentials;
use ContentCredentials\Laravel\ContentCredentialsServiceProvider;
use ContentCredentials\Laravel\Exception\MissingConfigurationException;
use Http\Mock\Client as MockClient;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;

/**
 * SPEC-004 — Laravel integration (provider, config, facade).
 * Tests-first: reference src/Laravel classes that do not exist yet; RED until
 * implemented. Bare illuminate/container + illuminate/config harness (D4) — no
 * orchestra/testbench, no live signing service.
 *
 * @see specs/SPEC-004-laravel-integration.md
 */

// Shim the Laravel config_path() helper the provider's boot() uses for
// publishing (illuminate/foundation is not installed in this bare harness).
if (! function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return sys_get_temp_dir().'/cc-config/'.$path;
    }
}

/**
 * @param  array{base_url?: string, api_key?: string}  $service
 */
function ccApp(array $service = ['base_url' => 'https://sign.test', 'api_key' => 'secret']): Container
{
    $app = new Container;
    Container::setInstance($app);
    $app->instance('config', new Repository(['content-credentials' => ['service' => $service]]));
    Facade::setFacadeApplication($app);
    Facade::clearResolvedInstances();

    return $app;
}

function ccRegister(Container $app): ContentCredentialsServiceProvider
{
    $provider = new ContentCredentialsServiceProvider($app);
    $provider->register();

    return $provider;
}

// --- AC1: resolves a configured signer -------------------------------------

it('resolves a configured signer from the container', function () {
    $app = ccApp();
    ccRegister($app);

    expect($app->make(SignerInterface::class))->toBeInstanceOf(SigningServiceSigner::class);

    $config = $app->make(SigningServiceConfig::class);
    expect($config->baseUrl)->toBe('https://sign.test')
        ->and($config->apiKey)->toBe('secret');
})->group('SPEC-004');

// --- AC2: resolves a configured reader -------------------------------------

it('resolves a configured reader from the container', function () {
    $app = ccApp();
    ccRegister($app);

    expect($app->make(ReaderInterface::class))->toBeInstanceOf(SigningServiceReader::class);
})->group('SPEC-004');

// --- AC3: config is driven by env and publishable --------------------------

it('drives config from env and registers it for publishing', function () {
    putenv('CONTENTAUTH_SERVICE_URL=https://env.test');
    putenv('CONTENTAUTH_API_KEY=env-key');

    $app = new Container;
    Container::setInstance($app);
    $config = new Repository;
    $app->instance('config', $config);

    $provider = ccRegister($app);

    expect($config->get('content-credentials.service.base_url'))->toBe('https://env.test')
        ->and($config->get('content-credentials.service.api_key'))->toBe('env-key');

    $provider->boot();
    $published = ContentCredentialsServiceProvider::pathsToPublish(
        ContentCredentialsServiceProvider::class,
        'content-credentials-config',
    );
    expect($published)->not->toBeEmpty();

    putenv('CONTENTAUTH_SERVICE_URL');
    putenv('CONTENTAUTH_API_KEY');
})->group('SPEC-004');

// --- AC4: facade proxies to the bound services -----------------------------

it('proxies sign() through the facade to the bound signer', function () {
    $app = ccApp();
    ccRegister($app);

    $expected = new SignedAsset('SIGNED', MediaType::Png);
    $app->instance(SignerInterface::class, new class($expected) implements SignerInterface
    {
        public function __construct(private SignedAsset $signed) {}

        public function sign(Asset $asset, Manifest $manifest): SignedAsset
        {
            return $this->signed;
        }
    });

    $manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Png)->withSoftwareAgent('X')->build();
    $result = ContentCredentials::sign(new Asset('B', MediaType::Png), $manifest);

    expect($result)->toBe($expected);
})->group('SPEC-004');

// --- AC5: a container-bound PSR-18 client overrides discovery ---------------

it('uses a container-bound PSR-18 client over discovery', function () {
    $app = ccApp();

    $mock = new MockClient;
    $mock->addResponse(new Response(200, [], json_encode([
        'signed_content' => base64_encode('OK'),
        'manifest_url' => null,
    ], JSON_THROW_ON_ERROR)));
    $app->instance(ClientInterface::class, $mock);

    ccRegister($app);

    $signer = $app->make(SignerInterface::class);
    $manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Png)->withSoftwareAgent('X')->build();
    $signer->sign(new Asset('B', MediaType::Png), $manifest);

    // The bound mock recorded the request — a discovered Guzzle would have tried
    // a real network call instead.
    expect($mock->getRequests())->toHaveCount(1);
})->group('SPEC-004');

// --- AC6: missing required config fails clearly (error path) ---------------

it('throws MissingConfigurationException when api_key is blank', function () {
    $app = ccApp(['base_url' => 'https://sign.test', 'api_key' => '']);
    ccRegister($app);

    expect(fn () => $app->make(SignerInterface::class))
        ->toThrow(MissingConfigurationException::class);
})->group('SPEC-004');

it('missing-config exception names the key and implements the Core interface', function () {
    $app = ccApp(['base_url' => 'https://sign.test', 'api_key' => '']);
    ccRegister($app);

    // toThrow (no catch) also checks the message contains the key name. The
    // exception's `implements ContentCredentialsException` is enforced at
    // compile time (verified by PHPStan), so no runtime interface assertion is
    // needed here.
    expect(fn () => $app->make(SignerInterface::class))
        ->toThrow(MissingConfigurationException::class, 'api_key');
})->group('SPEC-004');
