<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-023 — the four remaining formats that actually work.
 *
 * Same bar as SPEC-021: widening an enum proves nothing, what has to hold is
 * that each format signs, reads back, and still carries the Article 50 marking.
 *
 * Run with `vendor/bin/pest --group=integration` against a service started with
 * `RATE_LIMIT_REQUESTS=1000` (NOTES Step 17).
 *
 * @see specs/SPEC-023-measured-remaining-media-types.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/** Sign the committed fixture for a type and read it back through the service. */
function cc23RoundTrip(MediaType $type): void
{
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiGenerated($type)
        ->withSoftwareAgent('ACME GenAI', '1.0.0')
        ->build();

    $signed = $signer->sign(new Asset(ServiceHarness::mediaFixture($type), $type), $manifest);
    $report = $reader->read(new Asset($signed->bytes, $type));

    expect($signed->mediaType)->toBe($type)
        ->and($report->hasManifest())->toBeTrue()
        ->and($report->isSignatureValid())->toBeTrue()
        ->and($report->isAiGenerated())->toBeTrue();
}

// --- AC1: every added format signs and reads back --------------------------

it('signs and reads back image/svg+xml', function () {
    cc23RoundTrip(MediaType::Svg);
})->skip($skipUnlessReachable)->group('SPEC-023', 'integration');

it('signs and reads back video/quicktime', function () {
    cc23RoundTrip(MediaType::Mov);
})->skip($skipUnlessReachable)->group('SPEC-023', 'integration');

it('signs and reads back video/x-msvideo', function () {
    cc23RoundTrip(MediaType::Avi);
})->skip($skipUnlessReachable)->group('SPEC-023', 'integration');

it('signs and reads back audio/flac', function () {
    cc23RoundTrip(MediaType::Flac);
})->skip($skipUnlessReachable)->group('SPEC-023', 'integration');

// --- AC2: the deployment accepts what the enum declares ---------------------

it('accepts the four added types on the running service', function () {
    $reported = ServiceHarness::health()['media_types'] ?? null;

    expect($reported)->toBeArray();

    $accepted = is_array($reported) ? $reported : [];

    foreach (['image/svg+xml', 'video/quicktime', 'video/x-msvideo', 'audio/flac'] as $mime) {
        expect($accepted)->toContain($mime);
    }
})->skip($skipUnlessReachable)->group('SPEC-023', 'integration');

// --- AC3: the oversized-body refusal covers every video type ---------------

it('names every video type when refusing an oversized body', function () {
    $limits = ServiceHarness::health()['limits'] ?? [];
    $max = is_array($limits) ? ($limits['max_body_bytes'] ?? null) : null;

    if (! is_int($max)) {
        $this->markTestSkipped('service does not report max_body_bytes');
    }

    $response = (new Client)->post(ServiceHarness::baseUrl().'/v1/sign', [
        'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
        'json' => [
            'content' => base64_encode(str_repeat("\x00", (int) ($max * 3 / 4) + 1024)),
            'mime_type' => 'video/quicktime',
            'extra_assertions' => [],
        ],
        'timeout' => 30,
        'http_errors' => false,
    ]);

    $decoded = json_decode((string) $response->getBody(), true);
    $error = strtolower(is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : '');

    expect($response->getStatusCode())->toBe(413)
        ->and($error)->toContain('too large');

    // SPEC-021 AC7 made this refusal honest for the one video type that then
    // existed. With three, a sentence about video/mp4 alone would let someone
    // sending a MOV conclude it does not apply to them.
    foreach (['video/mp4', 'video/quicktime', 'video/x-msvideo'] as $mime) {
        expect($error)->toContain($mime);
    }
})->skip($skipUnlessReachable)->group('SPEC-023', 'integration');

// --- AC6 (service half): what stays out, stays out -------------------------

it('refuses the formats c2pa-rs cannot sign, naming what it accepts', function (string $mime) {
    $response = (new Client)->post(ServiceHarness::baseUrl().'/v1/sign', [
        'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
        'json' => [
            'content' => base64_encode(ServiceHarness::mediaFixture(MediaType::Png)),
            'mime_type' => $mime,
            'extra_assertions' => [],
        ],
        'timeout' => 30,
        'http_errors' => false,
    ]);

    $decoded = json_decode((string) $response->getBody(), true);
    $error = is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : '';

    // If c2pa-rs ever gains PDF writing, this is the test that fails first and
    // sends the next person to NOTES Step 27 — where the reason it is not a
    // one-line change is written down.
    expect($response->getStatusCode())->toBe(400)
        ->and($error)->toContain($mime)
        ->and($error)->toContain('audio/flac')
        ->and($decoded)->not->toHaveKey('signed_content');
})->with(['application/pdf', 'video/webm'])
    ->skip($skipUnlessReachable)->group('SPEC-023', 'integration');
