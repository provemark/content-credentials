<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-017 — a body-size default matched to what the service actually signs.
 *
 * `MAX_BODY_SIZE` was 50mb, which carries a ~37 MB asset. Measured 2026-08-06,
 * a signing request costs about 7× the asset in memory — not the "roughly four
 * copies" the docs claimed — so four of those in flight peaks near 1 GB, in a
 * container many people give 512 MB. SPEC-015's concurrency cap cannot help,
 * because express buffers the body before any limit is consulted.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 *
 * @see specs/SPEC-017-body-size-default.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/** The configured body limit in bytes, or null before SPEC-017. */
function bodyLimitBytes(): ?int
{
    $limits = ServiceHarness::health()['limits'] ?? null;
    $value = is_array($limits) ? ($limits['max_body_bytes'] ?? null) : null;

    return is_int($value) ? $value : null;
}

/**
 * A valid PNG of a given side length, built without GD.
 *
 * Pixels are random so the image barely compresses: this is the worst case for
 * size, which is the case a limit has to be chosen against.
 */
function sizedPng(int $side): string
{
    $raw = '';
    for ($y = 0; $y < $side; $y++) {
        $raw .= "\x00".random_bytes($side * 3);
    }

    $chunk = fn (string $type, string $data): string => pack('N', strlen($data))
        .$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NN', $side, $side)."\x08\x02\x00\x00\x00")
        .$chunk('IDAT', (string) gzcompress($raw, 1))
        .$chunk('IEND', '');
}

/**
 * Bytes that will exceed the configured limit once base64 inflates them by a
 * third. Returns a positive length so the caller does not have to reason about
 * a zero or negative limit.
 */
function oversizedPayload(): string
{
    $limit = bodyLimitBytes() ?? 0;

    return random_bytes(max(1, (int) ($limit * 0.9)));
}

/**
 * POST a body of a chosen size to an endpoint, bypassing the PHP client.
 *
 * @return array{status: int, cid: string, error: string}
 */
function postSized(string $path, string $bytes): array
{
    $response = (new Client(['http_errors' => false, 'timeout' => 60]))->post(
        ServiceHarness::baseUrl().$path,
        [
            'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
            'json' => [
                'content' => base64_encode($bytes),
                'mime_type' => 'image/png',
                'extra_assertions' => [[
                    'label' => 'c2pa.actions.v2',
                    'data' => ['actions' => [[
                        'action' => 'c2pa.created',
                        'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
                    ]]],
                ]],
            ],
        ],
    );

    $decoded = json_decode((string) $response->getBody(), true);
    $body = is_array($decoded) ? $decoded : [];

    return [
        'status' => $response->getStatusCode(),
        'cid' => $response->getHeaderLine('X-Correlation-Id'),
        'error' => is_string($body['error'] ?? null) ? $body['error'] : '',
    ];
}

// --- AC3: the limit is configurable and observable --------------------------

it('reports the effective body limit on /health', function () {
    $limits = ServiceHarness::health()['limits'] ?? null;

    expect($limits)->toBeArray()
        ->and($limits)->toHaveKey('max_body_bytes')
        ->and(bodyLimitBytes())->toBeGreaterThan(0);
})->group('SPEC-017', 'integration')->skip($skipUnlessReachable);

it('defaults to a limit sized for the assets it signs, not 50mb', function () {
    // 50mb carries a ~37 MB asset and peaks near 1 GB at the concurrency cap.
    // The default should be well below that while clearing the ~11.4 MB a
    // 2000x2000 incompressible PNG measured.
    $limit = bodyLimitBytes() ?? 0;

    expect($limit)->toBeLessThan(50 * 1024 * 1024, 'the 50mb default is still in place')
        ->and($limit)->toBeGreaterThan(16 * 1024 * 1024, 'too small for a realistic large PNG plus base64 overhead');
})->group('SPEC-017', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(fn () => bodyLimitBytes() === null ? 'service does not report max_body_bytes (pre-SPEC-017)' : false);

// --- AC1: realistic assets still sign ---------------------------------------

it('still signs a large but realistic PNG', function () {
    // 2000x2000 of incompressible pixels — beyond typical generated output.
    $bytes = sizedPng(2000);
    expect(strlen($bytes))->toBeGreaterThan(10 * 1024 * 1024);

    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('SPEC-017 large asset')
        ->build();

    $signed = $signer->sign(new Asset($bytes, MediaType::Png), $manifest);
    $report = $reader->read(new Asset($signed->bytes, MediaType::Png));

    expect($report->isSignatureValid())->toBeTrue()
        ->and($report->isAiGenerated())->toBeTrue();
})->group('SPEC-017', 'integration')->skip($skipUnlessReachable);

// --- AC2: an oversized body is refused clearly ------------------------------

it('refuses an oversized body with 413 rather than an unhandled error', function (string $path) {
    // Comfortably past the limit once base64 inflates it by a third.
    $result = postSized($path, oversizedPayload());

    expect($result['status'])->toBe(413)
        ->and($result['status'])->not->toBe(500, 'an oversized body reached the handler')
        ->and($result['error'])->not->toBe('');
})->with(['/v1/sign', '/v1/read'])
    ->group('SPEC-017', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(fn () => bodyLimitBytes() === null ? 'service does not report max_body_bytes (pre-SPEC-017)' : false);

it('names the limit and carries a correlation id when refusing for size', function () {
    $result = postSized('/v1/sign', oversizedPayload());

    expect($result['status'])->toBe(413)
        ->and($result['cid'])->not->toBe('', 'no correlation id on a size refusal (SPEC-012 AC3)')
        // The message should point at the constraint, not leak internals.
        ->and(strtolower($result['error']))->toContain('large')
        ->and($result['error'])->not->toContain('/app')
        ->and($result['error'])->not->toContain('.js');
})->group('SPEC-017', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(fn () => bodyLimitBytes() === null ? 'service does not report max_body_bytes (pre-SPEC-017)' : false);

// --- AC4: the refusal is recorded -------------------------------------------
// The size check happens inside express's body parser, before any handler runs,
// so this only passes if that path is wired into the SPEC-012 audit trail
// rather than left to express's default error handling.

/**
 * The audit record carrying $cid, or null when the log has none.
 *
 * @return array<array-key, mixed>|null
 */
function spec017AuditRecord(string $cid): ?array
{
    if ($cid === '') {
        return null;
    }

    $container = '';
    foreach (['docker compose', 'docker-compose'] as $binary) {
        $raw = shell_exec($binary.' ps -q service 2>/dev/null');
        $container = is_string($raw) ? trim($raw) : '';
        if ($container !== '') {
            break;
        }
    }

    if ($container === '') {
        return null;
    }

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $raw = shell_exec(sprintf('docker logs --tail 400 %s 2>&1', escapeshellarg($container)));

        foreach (explode("\n", is_string($raw) ? $raw : '') as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '{') || ! str_contains($line, $cid)) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['cid'] ?? null) === $cid) {
                return $decoded;
            }
        }

        usleep(200_000);
    }

    return null;
}

it('records a size refusal without recording the body', function () {
    $result = postSized('/v1/sign', oversizedPayload());

    expect($result['status'])->toBe(413);

    $record = spec017AuditRecord($result['cid']);

    expect($record)->not->toBeNull('an oversized request produced no audit record');
    expect($record['outcome'] ?? null)->toBe('rejected')
        ->and($record['reason'] ?? null)->toBeString()
        ->and($record['reason'] ?? '')->not->toBe('');

    // The whole point of refusing early is not to carry the payload around.
    $json = json_encode($record, JSON_THROW_ON_ERROR);
    expect(strlen($json))->toBeLessThan(4096, 'the audit record grew with the request body')
        ->and($json)->not->toContain(ServiceHarness::apiKey());
})->group('SPEC-017', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(fn () => bodyLimitBytes() === null ? 'service does not report max_body_bytes (pre-SPEC-017)' : false);
