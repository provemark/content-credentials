<?php

declare(strict_types=1);

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;
use Provemark\ContentCredentials\Laravel\Exception\MissingConfigurationException;
use Provemark\ContentCredentials\Laravel\HttpClientOptions;
use Psr\Http\Client\ClientInterface;

/**
 * SPEC-008 — HTTP timeout for the signing-service client.
 * Tests-first: reference HttpClientOptions (does not exist yet); RED until
 * implemented. Reuses the SPEC-004 harness (ccApp / ccRegister). Bare container,
 * no live network. The unit ACs assert the resolved options (the wiring), not
 * the socket (D5).
 *
 * @see specs/SPEC-008-http-timeout.md
 */

// --- AC1: a safe default timeout when none is configured -------------------

it('applies a safe default timeout when none is configured', function () {
    $app = ccApp(); // service config has no timeout keys
    ccRegister($app);

    $options = $app->make(HttpClientOptions::class);

    expect($options->timeout)->toBe(10.0)
        ->and($options->connectTimeout)->toBe(5.0);
})->group('SPEC-008');

// --- AC2: a configured timeout overrides the default -----------------------

it('applies a configured timeout over the default', function () {
    $app = ccApp(['base_url' => 'https://sign.test', 'api_key' => 'secret', 'timeout' => 3, 'connect_timeout' => 1]);
    ccRegister($app);

    $options = $app->make(HttpClientOptions::class);

    expect($options->timeout)->toBe(3.0)
        ->and($options->connectTimeout)->toBe(1.0);
})->group('SPEC-008');

// --- AC3: an injected client is used unchanged -----------------------------

it('uses an injected PSR-18 client unchanged', function () {
    $app = ccApp();

    $mock = new MockClient;
    $mock->addResponse(new Response(200, [], json_encode([
        'signed_content' => base64_encode('OK'),
        'manifest_url' => null,
    ], JSON_THROW_ON_ERROR)));
    $app->instance(ClientInterface::class, $mock);

    ccRegister($app);

    $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)->withSoftwareAgent('X')->build();
    $app->make(SignerInterface::class)->sign(new Asset('B', MediaType::Png), $manifest);

    // The bound client handled the request — the package did not build its own.
    expect($mock->getRequests())->toHaveCount(1);
})->group('SPEC-008');

// --- AC4: invalid timeout configuration fails clearly (error path) ---------

it('throws MissingConfigurationException for an invalid timeout', function (int|string $bad) {
    $app = ccApp(['base_url' => 'https://sign.test', 'api_key' => 'secret', 'timeout' => $bad]);
    ccRegister($app);

    expect(fn () => $app->make(HttpClientOptions::class))
        ->toThrow(MissingConfigurationException::class, 'timeout');
})->with([
    'negative' => [-1],
    'non-numeric' => ['soon'],
])->group('SPEC-008');
