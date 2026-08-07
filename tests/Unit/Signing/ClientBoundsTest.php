<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use Provemark\ContentCredentials\Core\Manifest\Manifest;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\Exception\AssetTooLargeException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningFailedException;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Signing\SigningServiceSigner;
use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * SPEC-025 — the bounds the client keeps for itself.
 *
 * The service has been hardened six times; the client once, and that one bound
 * was sized against a body limit SPEC-017 replaced. These cover the client side
 * only: AC5 and AC6 live with the Laravel tests and the documentation tests.
 *
 * @see specs/SPEC-025-client-side-bounds.md
 */

/** An HTTP client that records whether it was ever asked to send anything. */
final class Cc25SpyClient implements ClientInterface
{
    public int $calls = 0;

    public function __construct(private readonly ?ResponseInterface $returns = null) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls++;

        return $this->returns ?? new Response(200, [], '{"signed_content":""}');
    }
}

function cc25Signer(ClientInterface $client, ?SigningServiceConfig $config = null): SigningServiceSigner
{
    $factory = new Psr17Factory;

    return new SigningServiceSigner(
        $client,
        $factory,
        $factory,
        $config ?? new SigningServiceConfig('http://localhost:3000', 'test-key'),
    );
}

function cc25Manifest(): Manifest
{
    return ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('SPEC-025')
        ->build();
}

// --- AC1: the response bound matches what the service can send --------------

it('sizes the response bound to what the service can actually return', function () {
    $config = new SigningServiceConfig('http://localhost:3000', 'k');

    // The service caps a body at 20 MiB, which carries a ~15 MiB asset, so the
    // largest legitimate response is ~20 MiB plus JSON overhead. 96 MiB — the
    // old default, sized against a 50 MB cap SPEC-017 replaced — is five times
    // that, and above the memory_limit many deployments run.
    expect($config->maxResponseBytes)->toBeLessThanOrEqual(48 * 1024 * 1024)
        ->and($config->maxResponseBytes)->toBeGreaterThanOrEqual(24 * 1024 * 1024);
})->group('SPEC-025');

// --- AC2: an oversized asset is refused before it is encoded ----------------

it('refuses an asset larger than the request bound without sending anything', function () {
    $client = new Cc25SpyClient;
    $config = new SigningServiceConfig('http://localhost:3000', 'k', maxRequestBytes: 1024);

    $asset = new Asset(str_repeat('x', 4096), MediaType::Png);

    expect(fn () => cc25Signer($client, $config)->sign($asset, cc25Manifest()))
        ->toThrow(AssetTooLargeException::class);

    // The point of the criterion: nothing was sent, and nothing was encoded.
    expect($client->calls)->toBe(0);
})->group('SPEC-025');

it('names both the size and the limit when refusing', function () {
    $config = new SigningServiceConfig('http://localhost:3000', 'k', maxRequestBytes: 1024);

    try {
        cc25Signer(new Cc25SpyClient, $config)->sign(
            new Asset(str_repeat('x', 4096), MediaType::Png),
            cc25Manifest(),
        );
        throw new RuntimeException('expected AssetTooLargeException was not thrown');
    } catch (AssetTooLargeException $e) {
        // A limit error that does not say what the limit is sends the reader to
        // the source to find out.
        expect($e->getMessage())->toContain('4096')
            ->and($e->getMessage())->toContain('1024')
            ->and($e)->toBeInstanceOf(ContentCredentialsException::class);
    }
})->group('SPEC-025');

it('signs normally when the asset is within the bound', function () {
    // The bound must be invisible to the legitimate path — otherwise it is not a
    // bound, it is a smaller limit.
    $client = new Cc25SpyClient(new Response(200, [], json_encode([
        'signed_content' => base64_encode('SIGNED'),
    ], JSON_THROW_ON_ERROR)));

    $signed = cc25Signer($client)->sign(new Asset('small', MediaType::Png), cc25Manifest());

    expect($signed->bytes)->toBe('SIGNED')
        ->and($client->calls)->toBe(1);
})->group('SPEC-025');

// --- AC4: a service error message cannot flood a log ------------------------

it('caps the service error text it copies into an exception', function () {
    $hostile = str_repeat('A', 50_000);
    $client = new Cc25SpyClient(new Response(400, [], json_encode([
        'error' => $hostile,
    ], JSON_THROW_ON_ERROR)));

    try {
        cc25Signer($client)->sign(new Asset('x', MediaType::Png), cc25Manifest());
        throw new RuntimeException('expected SigningFailedException was not thrown');
    } catch (SigningFailedException $e) {
        // Capped — but the status code, which is the actionable part, survives.
        expect(strlen($e->getMessage()))->toBeLessThan(1024)
            ->and($e->getMessage())->toContain('400');
    }
})->group('SPEC-025');

it('still reports a short service error in full', function () {
    $client = new Cc25SpyClient(new Response(400, [], json_encode([
        'error' => 'unsupported mime_type "image/bmp"',
    ], JSON_THROW_ON_ERROR)));

    try {
        cc25Signer($client)->sign(new Asset('x', MediaType::Png), cc25Manifest());
        throw new RuntimeException('expected SigningFailedException was not thrown');
    } catch (SigningFailedException $e) {
        expect($e->getMessage())->toContain('unsupported mime_type "image/bmp"');
    }
})->group('SPEC-025');

// --- AC3 (Core half): the config can tell whether the transport is exposed --

it('recognises which base URLs send the token in clear', function (string $url, bool $insecure) {
    $config = new SigningServiceConfig($url, 'k');

    expect($config->usesInsecureTransport())->toBe($insecure);
})->with([
    // Loopback over http is the documented deployment, and silent.
    ['http://localhost:3000', false],
    ['http://127.0.0.1:3000', false],
    ['http://[::1]:3000', false],
    ['https://signer.example.com', false],
    // Anything else over http crosses a network with the bearer token on it.
    ['http://signer.example.com:3000', true],
    ['http://10.0.0.5:3000', true],
    // A private hostname between containers: still reported, because the config
    // cannot know it is private — what differs is the SEVERITY the Laravel layer
    // gives it, not the fact.
    ['http://signer:3000', true],
])->group('SPEC-025');
