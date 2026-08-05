<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-010 — reading an asset with no C2PA manifest is absence, not an error.
 *
 * Integration tests against the running service (docker compose up). Excluded
 * from `composer check`; run with `vendor/bin/pest --group=integration`.
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

// --- AC1: manifest-less read is an empty report ----------------------------

it('reads an unsigned asset as an empty report, not an error', function () {
    [, $reader] = ServiceHarness::signerAndReader();

    $report = $reader->read(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png));

    expect($report->hasManifest())->toBeFalse()
        ->and($report->isAiGenerated())->toBeFalse()
        ->and($report->digitalSourceTypes())->toBe([])
        ->and($report->signer())->toBeNull();
})->group('SPEC-010', 'integration')->skip($skipUnlessReachable);

// --- AC2: a signed asset still reads back its marking (regression) ----------

it('still reads back the AI marking from a signed asset', function () {
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Png)
        ->withSoftwareAgent('SPEC-010 regression')
        ->build();

    $signed = $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest);
    $report = $reader->read(new Asset($signed->bytes, MediaType::Png));

    expect($report->hasManifest())->toBeTrue()
        ->and($report->isAiGenerated())->toBeTrue()
        ->and($report->digitalSourceTypes())
        ->toContain(DigitalSourceType::TrainedAlgorithmicMedia->value);
})->group('SPEC-010', 'integration')->skip($skipUnlessReachable);

// --- AC3: malformed input still fails as 400, never 500 ---------------------

it('rejects malformed read input with 400, not 500', function () {
    $http = new Client(['http_errors' => false]);
    $url = ServiceHarness::baseUrl().'/v1/read';
    $auth = ['Authorization' => 'Bearer '.ServiceHarness::apiKey()];

    // Invalid base64 content.
    $badBase64 = $http->post($url, [
        'headers' => $auth,
        'json' => ['content' => '!!! not base64 !!!', 'mime_type' => 'image/png'],
    ]);
    expect($badBase64->getStatusCode())->toBe(400);

    // Unsupported mime type (valid base64 payload).
    $badMime = $http->post($url, [
        'headers' => $auth,
        'json' => ['content' => base64_encode('whatever'), 'mime_type' => 'image/gif'],
    ]);
    expect($badMime->getStatusCode())->toBe(400);
})->group('SPEC-010', 'integration')->skip($skipUnlessReachable);
