<?php

declare(strict_types=1);

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Provemark\ContentCredentials\Core\Manifest\Manifest;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadResponseException;
use Provemark\ContentCredentials\Core\Reading\SigningServiceReader;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningResponseException;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Signing\SigningServiceSigner;

/**
 * SPEC-009 #5 — bounded response reads in the signer + reader.
 * Tests-first: reference SigningServiceConfig::maxResponseBytes (does not exist
 * yet) and the enforced bound; RED until implemented. Mock PSR-18 client, no
 * network.
 *
 * @see specs/SPEC-009-service-client-hardening.md
 */
function s9Signer(MockClient $client, int $maxResponseBytes): SigningServiceSigner
{
    $f = new Psr17Factory;

    return new SigningServiceSigner($client, $f, $f, new SigningServiceConfig('https://sign.test', 'secret', $maxResponseBytes));
}

function s9Reader(MockClient $client, int $maxResponseBytes): SigningServiceReader
{
    $f = new Psr17Factory;

    return new SigningServiceReader($client, $f, $f, new SigningServiceConfig('https://sign.test', 'secret', $maxResponseBytes));
}

function s9Manifest(): Manifest
{
    return ManifestBuilder::forAiGeneratedImage(MediaType::Png)->withSoftwareAgent('X')->build();
}

// --- AC1: an over-limit response is rejected before decoding ----------------

it('rejects an over-limit signing response before decoding', function () {
    $client = new MockClient;
    $client->addResponse(new Response(200, [], json_encode([
        'signed_content' => base64_encode(str_repeat('A', 500)),
        'manifest_url' => null,
    ], JSON_THROW_ON_ERROR)));

    expect(fn () => s9Signer($client, 10)->sign(new Asset('PNG', MediaType::Png), s9Manifest()))
        ->toThrow(SigningResponseException::class);
})->group('SPEC-009');

it('rejects an over-limit read response before parsing', function () {
    $client = new MockClient;
    $client->addResponse(new Response(200, [], json_encode([
        'active_manifest' => 'urn:c2pa:test',
        'manifests' => ['urn:c2pa:test' => ['assertions' => []]],
        'validation_status' => [],
    ], JSON_THROW_ON_ERROR)));

    expect(fn () => s9Reader($client, 10)->read(new Asset('PNG', MediaType::Png)))
        ->toThrow(ReadResponseException::class);
})->group('SPEC-009');

// --- AC2: a within-limit response still works ------------------------------

it('still signs when the response is within the limit', function () {
    $client = new MockClient;
    $client->addResponse(new Response(200, [], json_encode([
        'signed_content' => base64_encode('SIGNED'),
        'manifest_url' => null,
    ], JSON_THROW_ON_ERROR)));

    $signed = s9Signer($client, 10_000)->sign(new Asset('PNG', MediaType::Png), s9Manifest());

    expect($signed->bytes)->toBe('SIGNED');
})->group('SPEC-009');
