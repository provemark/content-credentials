<?php

declare(strict_types=1);

use Http\Client\Exception as HttpClientException;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Provemark\ContentCredentials\Core\Manifest\Manifest;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\Exception\MediaTypeMismatchException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningFailedException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningResponseException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningTransportException;
use Provemark\ContentCredentials\Core\Signing\SignedAsset;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Signing\SigningServiceSigner;
use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * SPEC-002 — Signing (SignerInterface + SigningServiceSigner).
 * Tests-first: reference src/Core/Signing classes that do not exist yet; RED
 * until implemented. Driven entirely by a mock PSR-18 client — no live network.
 *
 * @see specs/SPEC-002-signing.md
 */
function signerFor(MockClient $client, string $baseUrl = 'https://sign.test', string $apiKey = 'secret'): SigningServiceSigner
{
    $factory = new Psr17Factory; // implements both PSR-17 factory interfaces

    return new SigningServiceSigner($client, $factory, $factory, new SigningServiceConfig($baseUrl, $apiKey));
}

function pngManifest(): Manifest
{
    return ManifestBuilder::forAiGeneratedImage(MediaType::Png)
        ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
        ->withClaimGenerator('Content Credentials', '0.1.0')
        ->build();
}

/**
 * @param  array<string, mixed>  $data
 */
function jsonBody(array $data): string
{
    return json_encode($data, JSON_THROW_ON_ERROR);
}

function signedResponse(string $signedBytes): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], jsonBody([
        'signed_content' => base64_encode($signedBytes),
        'manifest_url' => null,
    ]));
}

function onlyRequest(MockClient $client): RequestInterface
{
    $requests = $client->getRequests();
    expect($requests)->toHaveCount(1);

    return $requests[0] ?? throw new RuntimeException('no request was recorded');
}

// --- AC1: happy path -------------------------------------------------------

it('signs a PNG AI manifest against the service', function () {
    $client = new MockClient;
    $client->addResponse(signedResponse('SIGNED-BYTES'));

    $signed = signerFor($client)->sign(new Asset('PNG-BYTES', MediaType::Png), pngManifest());

    expect($signed)->toBeInstanceOf(SignedAsset::class)
        ->and($signed->bytes)->toBe('SIGNED-BYTES')
        ->and($signed->mediaType)->toBe(MediaType::Png);

    $request = onlyRequest($client);
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://sign.test/v1/sign')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer secret')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json');
})->group('SPEC-002');

// --- AC2: request body maps manifest + asset (incl. D1 creator_name) -------

it('maps the manifest and asset into the request body', function () {
    $client = new MockClient;
    $client->addResponse(signedResponse('X'));
    $manifest = pngManifest();

    signerFor($client)->sign(new Asset('PNG-BYTES', MediaType::Png), $manifest);

    $body = json_decode((string) onlyRequest($client)->getBody(), true, 512, JSON_THROW_ON_ERROR);

    // Exact body: no signature_type key; creator_name from the claim generator.
    expect($body)->toEqual([
        'content' => base64_encode('PNG-BYTES'),
        'mime_type' => 'image/png',
        'creator_name' => 'Content Credentials',
        'extra_assertions' => $manifest->assertions(),
    ]);
})->group('SPEC-002');

// D1: creator_name omitted when the manifest has no claim generator.
it('omits creator_name when the manifest has no claim generator', function () {
    $client = new MockClient;
    $client->addResponse(signedResponse('X'));
    $manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Png)->withSoftwareAgent('A')->build();

    signerFor($client)->sign(new Asset('B', MediaType::Png), $manifest);

    $body = json_decode((string) onlyRequest($client)->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($body)->toEqual([
        'content' => base64_encode('B'),
        'mime_type' => 'image/png',
        'extra_assertions' => $manifest->assertions(),
    ]);
})->group('SPEC-002');

// --- AC3: non-2xx response is a typed failure (error path) -----------------

it('throws SigningFailedException on a non-2xx response', function (int $status) {
    $client = new MockClient;
    $client->addResponse(new Response($status, [], jsonBody(['error' => 'Unauthorized'])));

    expect(fn () => signerFor($client)->sign(new Asset('B', MediaType::Png), pngManifest()))
        ->toThrow(SigningFailedException::class);
})->with([[401], [400], [500]])->group('SPEC-002');

it('the signing failure carries the status and service error message', function () {
    $client = new MockClient;
    $client->addResponse(new Response(401, [], jsonBody(['error' => 'Unauthorized'])));

    try {
        signerFor($client)->sign(new Asset('B', MediaType::Png), pngManifest());
        throw new RuntimeException('expected SigningFailedException was not thrown');
    } catch (SigningFailedException $e) {
        expect($e->getMessage())->toContain('401')
            ->and($e->getMessage())->toContain('Unauthorized')
            ->and($e)->toBeInstanceOf(ContentCredentialsException::class);
    }
})->group('SPEC-002');

// --- AC4: transport failure is wrapped (error path) ------------------------

it('wraps a PSR-18 transport failure', function () {
    $client = new MockClient;
    // Implements PSR-18's ClientExceptionInterface (what the signer catches) and
    // php-http's marker (what mock-client's addException expects).
    $client->addException(new class('boom') extends RuntimeException implements ClientExceptionInterface, HttpClientException {});

    try {
        signerFor($client)->sign(new Asset('B', MediaType::Png), pngManifest());
        throw new RuntimeException('expected SigningTransportException was not thrown');
    } catch (SigningTransportException $e) {
        expect($e->getPrevious())->toBeInstanceOf(ClientExceptionInterface::class)
            ->and($e)->toBeInstanceOf(ContentCredentialsException::class);
    }
})->group('SPEC-002');

// --- AC5: malformed 2xx body is rejected (malformed-input path) ------------

it('rejects a malformed 2xx body', function (string $body) {
    $client = new MockClient;
    $client->addResponse(new Response(200, [], $body));

    expect(fn () => signerFor($client)->sign(new Asset('B', MediaType::Png), pngManifest()))
        ->toThrow(SigningResponseException::class);
})->with([
    'not json at all',
    '{"manifest_url": null}',                  // missing signed_content
    '{"signed_content": "!!! not base64 !!!"}', // invalid base64
])->group('SPEC-002');

// --- AC6: asset/manifest media-type must agree, before any HTTP call -------

it('rejects an asset/manifest media-type mismatch before any HTTP call', function () {
    $client = new MockClient;

    expect(fn () => signerFor($client)->sign(new Asset('B', MediaType::Jpeg), pngManifest()))
        ->toThrow(MediaTypeMismatchException::class);

    expect($client->getRequests())->toHaveCount(0);
})->group('SPEC-002');

it('media-type mismatch error implements the Core exception interface', function () {
    $client = new MockClient;

    try {
        signerFor($client)->sign(new Asset('B', MediaType::Jpeg), pngManifest());
        throw new RuntimeException('expected MediaTypeMismatchException was not thrown');
    } catch (MediaTypeMismatchException $e) {
        expect($e)->toBeInstanceOf(ContentCredentialsException::class);
    }
})->group('SPEC-002');

// --- AC7: the API key never leaks -----------------------------------------

it('never leaks the API key in failure messages', function () {
    $client = new MockClient;
    $client->addResponse(new Response(401, [], jsonBody(['error' => 'Unauthorized'])));

    try {
        signerFor($client, apiKey: 'super-secret-key')->sign(new Asset('B', MediaType::Png), pngManifest());
        throw new RuntimeException('expected SigningFailedException was not thrown');
    } catch (SigningFailedException $e) {
        expect($e->getMessage())->not->toContain('super-secret-key')
            ->and((string) $e)->not->toContain('super-secret-key');
    }
})->group('SPEC-002');

// --- D5: base URL normalisation --------------------------------------------

it('normalises a trailing slash in the base URL', function () {
    $client = new MockClient;
    $client->addResponse(signedResponse('X'));

    signerFor($client, baseUrl: 'https://sign.test/')->sign(new Asset('B', MediaType::Png), pngManifest());

    expect((string) onlyRequest($client)->getUri())->toBe('https://sign.test/v1/sign');
})->group('SPEC-002');
