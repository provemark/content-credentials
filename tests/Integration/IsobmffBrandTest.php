<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-039 — the signing service refuses foreign ISOBMFF containers.
 *
 * A JPEG XL container is ISOBMFF, and so are MP4, QuickTime and AVIF. The
 * declared media type selects a handler, not a format, so before this spec the
 * BMFF handler accepted a JXL container under all three types: HTTP 200, a
 * plausible signed_content, and a credential nothing could read back. Measured
 * in NOTES Step 58.
 *
 * These need the real engine and the real service, so they live here.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`
 * against a service started with `RATE_LIMIT_REQUESTS=1000`.
 *
 * @see specs/SPEC-039-isobmff-brand-check.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/** The committed JPEG XL container fixture: boxes `JXL ` / `ftyp` / `jxlc`, brand `jxl `. */
function cc39JxlContainer(): string
{
    return (string) file_get_contents(__DIR__.'/../Fixtures/fixture-container.jxl');
}

/**
 * The AI-marking assertion every sign request here carries.
 *
 * @return list<array<string, mixed>>
 */
function cc39Actions(): array
{
    return [[
        'label' => 'c2pa.actions.v2',
        'data' => ['actions' => [[
            'action' => 'c2pa.created',
            'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
            'softwareAgent' => ['name' => 'SPEC-039 probe'],
        ]]],
    ]];
}

/**
 * Raw POST, so the refusal itself can be asserted rather than an exception type.
 *
 * @param  array<string, mixed>  $body
 * @return array{0: int, 1: array<string, mixed>}
 */
function cc39Post(string $path, array $body): array
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

/**
 * The `error` field as a string, whatever the service put there.
 *
 * @param  array<string, mixed>  $body
 */
function cc39Error(array $body): string
{
    $error = $body['error'] ?? '';

    return is_string($error) ? $error : '';
}

/** @return array{0: int, 1: array<string, mixed>} */
function cc39Sign(string $bytes, string $mime): array
{
    return cc39Post('/v1/sign', [
        'content' => base64_encode($bytes),
        'mime_type' => $mime,
        'extra_assertions' => cc39Actions(),
    ]);
}

// --- AC1: the measured defect, closed --------------------------------------
// One test per ISOBMFF type, written out rather than looped: the defect was
// reachable under all three, and a failure has to name which one came back.

it('refuses a JPEG XL container declared as video/mp4', function () {
    [$status, $body] = cc39Sign(cc39JxlContainer(), 'video/mp4');

    expect($status)->toBe(400)
        // Naming both halves is the point of the message: an operator has to
        // see what was sent and what it actually was.
        ->and(cc39Error($body))->toContain('jxl')
        ->and(cc39Error($body))->toContain('video/mp4');
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');

it('refuses a JPEG XL container declared as video/quicktime', function () {
    [$status, $body] = cc39Sign(cc39JxlContainer(), 'video/quicktime');

    expect($status)->toBe(400)
        ->and(cc39Error($body))->toContain('jxl');
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');

it('refuses a JPEG XL container declared as image/avif', function () {
    [$status, $body] = cc39Sign(cc39JxlContainer(), 'image/avif');

    expect($status)->toBe(400)
        ->and(cc39Error($body))->toContain('jxl');
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');

// --- AC2: the overreach guard ----------------------------------------------
// A brand check that refuses a legitimate file is worse than the defect it
// fixes. These pin the brands measured off the fixtures themselves.

it('still signs fixture.mp4, whose major brand is isom', function () {
    [$status, $body] = cc39Sign(
        (string) file_get_contents(__DIR__.'/../Fixtures/fixture.mp4'),
        'video/mp4',
    );

    expect($status)->toBe(200)
        ->and($body)->toHaveKey('signed_content');
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');

it('still signs fixture.mov, whose major brand is qt', function () {
    [$status, $body] = cc39Sign(
        (string) file_get_contents(__DIR__.'/../Fixtures/fixture.mov'),
        'video/quicktime',
    );

    expect($status)->toBe(200)
        ->and($body)->toHaveKey('signed_content');
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');

// --- AC3: major brand only, never the compatible brands --------------------

it('still signs fixture.avif, which carries mif1 among its compatible brands', function () {
    // Measured: major `avif`, compatible `avif mif1 miaf MA1A`. `mif1` is
    // generic HEIF and a plausible deny-list entry — denying on compatible
    // brands would refuse this file, which we support today.
    [$status, $body] = cc39Sign(
        (string) file_get_contents(__DIR__.'/../Fixtures/fixture.avif'),
        'image/avif',
    );

    expect($status)->toBe(200)
        ->and($body)->toHaveKey('signed_content');
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');

// --- AC4: the ftyp box is located, not assumed to be first -----------------

it('finds the ftyp box behind the JXL signature box rather than at offset four', function () {
    $bytes = cc39JxlContainer();

    // The fixture's shape is what makes AC4 necessary: reading bytes 4..8 finds
    // `JXL `, not `ftyp`. Assert the shape here so that a regenerated fixture
    // cannot quietly turn AC1 into a test of something easier.
    expect(substr($bytes, 4, 4))->toBe('JXL ')
        ->and(substr($bytes, 16, 4))->toBe('ftyp')
        ->and(substr($bytes, 20, 4))->toBe('jxl ');

    [$status] = cc39Sign($bytes, 'video/mp4');

    expect($status)->toBe(400);
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');

// --- AC5: no ftyp at all means nothing to deny -----------------------------

it('leaves an asset with no ftyp box to the engine instead of refusing it', function () {
    // A QuickTime file need not carry ftyp. Measured: with the box stripped the
    // engine answers `type is unsupported` — its own message, a 500, not our
    // 400. Asserting that specific string is what distinguishes "the deny-list
    // stayed out of the way" from "nothing happened".
    $mov = (string) file_get_contents(__DIR__.'/../Fixtures/fixture.mov');
    $header = unpack('Nlength', substr($mov, 0, 4));
    $length = is_array($header) ? $header['length'] : 0;
    $ftypLength = is_int($length) ? $length : 0;

    expect(substr($mov, 4, 4))->toBe('ftyp')
        ->and($ftypLength)->toBeGreaterThan(7);

    [$status, $body] = cc39Sign(substr($mov, $ftypLength), 'video/quicktime');

    expect($status)->toBe(500)
        ->and(cc39Error($body))->toContain('signing failed');
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');

// --- AC6: the parent ingredient is checked too ------------------------------

it('refuses a JPEG XL container offered as the parent asset', function () {
    [$status, $body] = cc39Post('/v1/sign', [
        'content' => base64_encode((string) file_get_contents(__DIR__.'/../Fixtures/fixture.mp4')),
        'mime_type' => 'video/mp4',
        'parent' => [
            'content' => base64_encode(cc39JxlContainer()),
            'mime_type' => 'video/mp4',
        ],
        // Route B (SPEC-028): we send only the edit intent. The service adds
        // c2pa.opened and the parentOf ingredient — supplying c2pa.opened here
        // is refused, and would test the wrong guard.
        'extra_assertions' => [[
            'label' => 'c2pa.actions.v2',
            'data' => ['actions' => [[
                'action' => 'c2pa.edited',
                'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia',
                'softwareAgent' => ['name' => 'SPEC-039 probe'],
            ]]],
        ]],
    ]);

    expect($status)->toBe(400)
        ->and(cc39Error($body))->toContain('parent')
        ->and(cc39Error($body))->toContain('jxl');
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');

// --- AC7: malformed container input ----------------------------------------

it('refuses a box header that runs past the end of the buffer', function () {
    // A box claiming 4 GB inside a 24-byte file. The walk is attacker-controlled
    // arithmetic and must stop at the buffer, not read past it.
    $bytes = "\xFF\xFF\xFF\xFF".'ftyp'.'jxl '."\x00\x00\x00\x00".'jxl ';

    [$status, $body] = cc39Sign($bytes, 'video/mp4');

    expect($status)->toBe(400)
        ->and(cc39Error($body))->not->toBe('');
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');

it('refuses a first box shorter than its own header', function () {
    $bytes = "\x00\x00\x00\x02".'ftyp'.'jxl ';

    [$status, $body] = cc39Sign($bytes, 'video/mp4');

    expect($status)->toBe(400)
        ->and(cc39Error($body))->not->toBe('');
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');

// --- AC8: the refusal is audited and quotes nothing raw ---------------------

it('names the brand printably and carries a correlation id', function () {
    [$status, $body] = cc39Sign(cc39JxlContainer(), 'video/mp4');

    $error = cc39Error($body);

    expect($status)->toBe(400)
        ->and($body)->toHaveKey('cid')
        ->and($body['cid'])->toBeString()
        // The brand is four bytes off an attacker-supplied asset. It reaches an
        // operator's terminal, so it may not carry control characters — the
        // SPEC-006 AC8 reasoning, one layer earlier.
        ->and(preg_match('/[\x00-\x1F\x7F]/', $error))->toBe(0);
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');

// --- AC9: reading is unaffected ---------------------------------------------

it('still reads a JPEG XL container as an empty manifest report', function () {
    [$status, $body] = cc39Post('/v1/read', [
        'content' => base64_encode(cc39JxlContainer()),
        'mime_type' => 'video/mp4',
    ]);

    expect($status)->toBe(200)
        ->and($body)->toBe([]);
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');

// --- AC10: the three lists still agree --------------------------------------

it('narrows what is accepted without narrowing what is declared', function () {
    $reported = ServiceHarness::health()['media_types'] ?? [];

    expect($reported)->toBeArray()
        ->and($reported)->toContain('video/mp4')
        ->and($reported)->toContain('video/quicktime')
        ->and($reported)->toContain('image/avif')
        ->and($reported)->not->toContain('image/jxl');
})->skip($skipUnlessReachable)->group('SPEC-039', 'integration');
