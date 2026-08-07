<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-021 — the media types the engine already supports.
 *
 * Widening an enum proves nothing on its own; what has to hold is that each
 * declared format signs, reads back, and still carries the Article 50 marking.
 * That needs the real engine, so it lives here.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`
 * against a service started with `RATE_LIMIT_REQUESTS=1000` (the suite trips a
 * default 60/minute budget — NOTES Step 17).
 *
 * @see specs/SPEC-021-additional-media-types.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/** The committed fixture for a media type. */
function cc21Fixture(MediaType $type): string
{
    $extension = match ($type) {
        MediaType::Png => 'png',
        MediaType::Jpeg => 'jpg',
        MediaType::Webp => 'webp',
        MediaType::Avif => 'avif',
        MediaType::Gif => 'gif',
        MediaType::Tiff => 'tiff',
        MediaType::Wav => 'wav',
        MediaType::Mp3 => 'mp3',
        MediaType::Mp4 => 'mp4',
    };

    $path = dirname(__DIR__)."/Fixtures/fixture.{$extension}";
    $bytes = file_get_contents($path);

    if (! is_string($bytes) || $bytes === '') {
        throw new RuntimeException("missing fixture {$path}");
    }

    return $bytes;
}

/**
 * Raw POST to the service, so the error paths can be asserted directly.
 *
 * Guzzle rather than a stream context: `$http_response_header` is deprecated on
 * PHP 8.4+, and its replacement does not exist on the 8.3 this package targets.
 *
 * @param  array<string, mixed>  $body
 * @return array{0: int, 1: array<string, mixed>}
 */
function cc21Post(string $path, array $body): array
{
    $response = (new Client)->post(ServiceHarness::baseUrl().$path, [
        'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
        'json' => $body,
        'timeout' => 30,
        'http_errors' => false,
    ]);

    $decoded = json_decode((string) $response->getBody(), true);

    /** @var array<string, mixed> $decodedBody */
    $decodedBody = is_array($decoded) ? $decoded : [];

    return [$response->getStatusCode(), $decodedBody];
}

// --- AC1: every declared format signs and reads back -----------------------
// One test per format, written out rather than looped: when a format breaks the
// failure has to name it, and a title assembled at runtime is invisible both to
// a reader of this file and to bin/spec-check.php.

/** Sign the committed fixture for a type and read it back through the service. */
function cc21RoundTrip(MediaType $type): void
{
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiGeneratedImage($type)
        ->withSoftwareAgent('ACME GenAI', '1.0.0')
        ->build();

    $signed = $signer->sign(new Asset(cc21Fixture($type), $type), $manifest);
    $report = $reader->read(new Asset($signed->bytes, $type));

    expect($signed->mediaType)->toBe($type)
        ->and($report->hasManifest())->toBeTrue()
        ->and($report->isSignatureValid())->toBeTrue()
        // The marking is the point: the container changed, the assertion
        // must not have.
        ->and($report->isAiGenerated())->toBeTrue();
}

it('signs and reads back image/png', function () {
    cc21RoundTrip(MediaType::Png);
})->skip($skipUnlessReachable)->group('SPEC-021', 'integration');

it('signs and reads back image/jpeg', function () {
    cc21RoundTrip(MediaType::Jpeg);
})->skip($skipUnlessReachable)->group('SPEC-021', 'integration');

it('signs and reads back image/webp', function () {
    cc21RoundTrip(MediaType::Webp);
})->skip($skipUnlessReachable)->group('SPEC-021', 'integration');

it('signs and reads back image/avif', function () {
    cc21RoundTrip(MediaType::Avif);
})->skip($skipUnlessReachable)->group('SPEC-021', 'integration');

it('signs and reads back image/gif', function () {
    cc21RoundTrip(MediaType::Gif);
})->skip($skipUnlessReachable)->group('SPEC-021', 'integration');

it('signs and reads back image/tiff', function () {
    cc21RoundTrip(MediaType::Tiff);
})->skip($skipUnlessReachable)->group('SPEC-021', 'integration');

it('signs and reads back audio/wav', function () {
    cc21RoundTrip(MediaType::Wav);
})->skip($skipUnlessReachable)->group('SPEC-021', 'integration');

it('signs and reads back audio/mpeg', function () {
    cc21RoundTrip(MediaType::Mp3);
})->skip($skipUnlessReachable)->group('SPEC-021', 'integration');

it('signs and reads back video/mp4', function () {
    cc21RoundTrip(MediaType::Mp4);
})->skip($skipUnlessReachable)->group('SPEC-021', 'integration');

// --- AC2: the two allow-lists agree ----------------------------------------

it('accepts on the service exactly what the client enum declares', function () {
    // Through /health against a running service, not by parsing server.js for a
    // literal: what can be wrong is the deployment, not the source text.
    $reported = ServiceHarness::health()['media_types'] ?? null;

    expect($reported)->toBeArray();

    $accepted = array_map(
        static fn (mixed $value): string => is_string($value) ? $value : '',
        is_array($reported) ? array_values($reported) : [],
    );
    $declared = array_map(fn (MediaType $t): string => $t->value, MediaType::cases());

    sort($accepted);
    sort($declared);

    expect($accepted)->toBe($declared);
})->skip($skipUnlessReachable)->group('SPEC-021', 'integration');

// --- AC6: the service reports what it accepts ------------------------------

it('reports the accepted media types on /health', function () {
    $health = ServiceHarness::health();

    expect($health)->toHaveKey('media_types')
        ->and($health['media_types'])->toBeArray()
        ->and($health['media_types'])->toContain('image/png')
        ->and($health['media_types'])->toContain('video/mp4');
})->skip($skipUnlessReachable)->group('SPEC-021', 'integration');

// --- AC3 (service half): an unsupported type is refused --------------------

it('refuses an unsupported media type and names what it supports', function (string $mime) {
    [$status, $body] = cc21Post('/v1/sign', [
        'content' => base64_encode(cc21Fixture(MediaType::Png)),
        'mime_type' => $mime,
        'extra_assertions' => [],
    ]);

    $error = is_string($body['error'] ?? null) ? $body['error'] : '';

    expect($status)->toBe(400)
        ->and($body)->toHaveKey('error')
        ->and($error)->toContain($mime)
        ->and($error)->toContain('image/png')
        ->and($error)->toContain('video/mp4')
        // Nothing signed.
        ->and($body)->not->toHaveKey('signed_content');
})->with(['image/bmp', 'application/pdf', 'text/plain'])
    ->skip($skipUnlessReachable)->group('SPEC-021', 'integration');

// --- AC4: mismatched bytes and declared type do not silently succeed --------

it('signs what the engine detects when the declared type disagrees', function () {
    // Measured 2026-08-06: c2pa-rs recognises the format from the bytes and
    // treats the declared type as advisory (NOTES Step 22). This pins that
    // behaviour now that more formats are in play, rather than leaving each
    // caller to discover it. It is deterministic and documented, which is what
    // AC4 asks for — not a refusal.
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Webp)
        ->withSoftwareAgent('ACME GenAI')
        ->build();

    // WAV bytes offered as image/webp.
    $signed = $signer->sign(new Asset(cc21Fixture(MediaType::Wav), MediaType::Webp), $manifest);

    // Still a WAV, and still readable as one: the bytes decided.
    expect(substr($signed->bytes, 0, 4))->toBe('RIFF');

    $report = $reader->read(new Asset($signed->bytes, MediaType::Wav));

    expect($report->hasManifest())->toBeTrue()
        ->and($report->isSignatureValid())->toBeTrue()
        ->and($report->isAiGenerated())->toBeTrue();
})->skip($skipUnlessReachable)->group('SPEC-021', 'integration');

// --- AC7: an oversized video is refused for the right reason ---------------

it('refuses an oversized video by naming the limit and that video is bounded by it', function () {
    $limits = ServiceHarness::health()['limits'] ?? [];
    $max = is_array($limits) ? ($limits['max_body_bytes'] ?? null) : null;

    if (! is_int($max)) {
        $this->markTestSkipped('service does not report max_body_bytes');
    }

    // Just over the limit after base64 inflation (4/3), sent as video/mp4. The
    // body parser refuses before any route, so the message cannot depend on the
    // declared type — which is exactly why it has to say this unconditionally.
    $payload = str_repeat("\x00", (int) ($max * 3 / 4) + 1024);

    [$status, $body] = cc21Post('/v1/sign', [
        'content' => base64_encode($payload),
        'mime_type' => 'video/mp4',
        'extra_assertions' => [],
    ]);

    $error = strtolower(is_string($body['error'] ?? null) ? $body['error'] : '');

    expect($status)->toBe(413)
        // The limit itself...
        ->and($error)->toContain('too large')
        // ...and what it means for video, rather than a bare byte count.
        ->and($error)->toContain('video/mp4')
        ->and($body)->toHaveKey('cid');
})->skip($skipUnlessReachable)->group('SPEC-021', 'integration');
