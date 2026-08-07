<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-024 — bounding the read path.
 *
 * SPEC-015 bounded `/v1/sign` and never mentioned `/v1/read`, so reading was
 * unbounded: measured 2026-08-07 against a service with `RATE_LIMIT_REQUESTS=5`,
 * ten reads all answered 200 while the sixth sign was refused.
 *
 * Reading is cheaper than signing but the same order of magnitude — ~3–5× the
 * asset in memory against ~7× for signing — so the cap is about peak memory, not
 * about fairness.
 *
 * Run with `vendor/bin/pest --group=integration` against a service started with
 * `RATE_LIMIT_REQUESTS=1000` (NOTES Step 17); the criteria that need a small
 * budget skip themselves unless the service reports one.
 *
 * @see specs/SPEC-024-bounding-the-read-path.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/** A reported limit, or null when the service does not publish it. */
function readLimit(string $key): ?int
{
    $limits = ServiceHarness::health()['limits'] ?? null;
    $value = is_array($limits) ? ($limits[$key] ?? null) : null;

    return is_int($value) ? $value : null;
}

/**
 * A signed asset big enough that reading it takes real work.
 *
 * Signed once and cached: the point is to make each READ cost something, and
 * paying a signing request per read would spend the sign budget the SPEC-015
 * criteria need. Same reasoning as SPEC-015's `largePngBytes()` — a burst of
 * cheap requests never overlaps, so a concurrency criterion tested with small
 * assets passes for the wrong reason.
 */
function spec024SignedAsset(): string
{
    /** @var string|null $signed */
    static $signed = null;

    if (is_string($signed)) {
        return $signed;
    }

    $side = 700;
    $raw = '';
    for ($y = 0; $y < $side; $y++) {
        $raw .= "\x00".random_bytes($side * 3);
    }

    $chunk = fn (string $type, string $data): string => pack('N', strlen($data))
        .$type.$data.pack('N', crc32($type.$data));

    $png = "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NN', $side, $side)."\x08\x02\x00\x00\x00")
        .$chunk('IDAT', (string) gzcompress($raw, 1))
        .$chunk('IEND', '');

    [$signer] = ServiceHarness::signerAndReader();
    $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('SPEC-024 read load')
        ->build();

    return $signed = $signer->sign(new Asset($png, MediaType::Png), $manifest)->bytes;
}

/** @return array<string, mixed> */
function readBody(): array
{
    return ['content' => base64_encode(spec024SignedAsset()), 'mime_type' => 'image/png'];
}

/**
 * Fire $count reads concurrently.
 *
 * @return list<array{status: int, retry_after: string, cid: string}>
 */
function concurrentReads(int $count): array
{
    $client = new Client(['http_errors' => false, 'timeout' => 30]);
    $options = [
        'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
        'json' => readBody(),
    ];

    $promises = [];
    for ($i = 0; $i < $count; $i++) {
        $promises[] = $client->postAsync(ServiceHarness::baseUrl().'/v1/read', $options);
    }

    $results = [];
    foreach (Utils::settle($promises)->wait() as $settled) {
        $response = $settled['value'] ?? null;
        $results[] = $response === null
            ? ['status' => 0, 'retry_after' => '', 'cid' => '']
            : [
                'status' => $response->getStatusCode(),
                'retry_after' => $response->getHeaderLine('Retry-After'),
                'cid' => $response->getHeaderLine('X-Correlation-Id'),
            ];
    }

    return $results;
}

/**
 * Fire $count reads as genuinely parallel OS processes.
 *
 * Guzzle's pool was not enough for the sign path (SPEC-015) and is not enough
 * here either: the requests have to actually coexist for a concurrency cap to be
 * reachable, and `xargs -P` gives that regardless of how fast the service is.
 *
 * @return array<int, int> status code => how many responses carried it
 */
function parallelReads(int $count): array
{
    $payload = (string) tempnam(sys_get_temp_dir(), 'spec024');
    file_put_contents($payload, json_encode(readBody(), JSON_THROW_ON_ERROR));

    $command = sprintf(
        'seq %d | xargs -P %d -I{} curl -s -o /dev/null -w %s -X POST %s -H %s -H %s --data-binary @%s',
        $count,
        $count,
        escapeshellarg('%{http_code}\n'),
        escapeshellarg(ServiceHarness::baseUrl().'/v1/read'),
        escapeshellarg('Authorization: Bearer '.ServiceHarness::apiKey()),
        escapeshellarg('Content-Type: application/json'),
        escapeshellarg($payload),
    );

    $raw = shell_exec($command);
    @unlink($payload);

    $counts = [];
    foreach (explode("\n", is_string($raw) ? $raw : '') as $line) {
        $status = (int) trim($line);
        if ($status > 0) {
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
    }

    return $counts;
}

// --- AC5: /health reports the read limits and read saturation ---------------

it('reports its read limits and how many reads are in flight', function () {
    $health = ServiceHarness::health();
    $limits = $health['limits'] ?? [];

    expect($limits)->toBeArray()
        ->and($limits)->toHaveKey('max_concurrent_reads')
        ->and($limits)->toHaveKey('read_rate_limit_requests')
        // Reported separately from signing: an operator cannot size an instance
        // from a single number when the two paths cost different amounts.
        ->and($health)->toHaveKey('reads_in_flight')
        ->and($health['reads_in_flight'])->toBeInt();
})->group('SPEC-024', 'integration')->skip($skipUnlessReachable);

it('has its read limits switched on by default', function () {
    // A protection that ships off is one nobody turns on (SPEC-015's reasoning).
    // 0 means explicitly disabled, and /health says so — that is a decision, not
    // this default.
    expect(readLimit('max_concurrent_reads'))->toBeGreaterThan(0)
        ->and(readLimit('read_rate_limit_requests'))->toBeGreaterThan(0);
})->group('SPEC-024', 'integration')->skip($skipUnlessReachable);

// --- AC1: reads are rate-limited per token ----------------------------------

it('refuses a token that exceeds its read rate, and serves it again after the window', function () {
    $limit = readLimit('read_rate_limit_requests') ?? 0;

    $first = concurrentReads($limit + 4);
    $refused = array_filter($first, fn (array $r): bool => $r['status'] === 429);

    expect($refused)->not->toBeEmpty('the read rate limit refused nothing');

    foreach ($refused as $refusal) {
        // Retry-After is what makes a refusal actionable rather than a mystery.
        expect($refusal['retry_after'])->not->toBe('');
    }

    $window = readLimit('rate_limit_window_ms') ?? 0;
    usleep(($window + 500) * 1000);

    $after = concurrentReads(1);
    expect($after[0]['status'])->toBe(200, 'the token was still refused after its window elapsed');
})->group('SPEC-024', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(function () {
        $limit = readLimit('read_rate_limit_requests');

        return $limit === null || $limit > 20
            ? 'needs a service with a small read_rate_limit_requests (run the rate-limited profile)'
            : false;
    });

// --- AC2: reads are bounded in flight ---------------------------------------

it('refuses the excess when more reads arrive than the cap allows', function () {
    $cap = readLimit('max_concurrent_reads') ?? 0;

    // Retried to a deadline, exactly as SPEC-015 AC3 had to be: a single burst
    // is a race, and a criterion about concurrency cannot assume it achieved
    // concurrency. Exits on the first burst showing both outcomes.
    $accepted = 0;
    $refused = 0;
    $deadline = microtime(true) + 60;

    while (microtime(true) < $deadline) {
        $counts = parallelReads(max(30, $cap * 8));
        $accepted += $counts[200] ?? 0;
        $refused += $counts[429] ?? 0;

        if ($accepted > 0 && $refused > 0) {
            break;
        }
    }

    expect($refused)->toBeGreaterThan(0, 'nothing was refused above the read concurrency cap')
        ->and($accepted)->toBeGreaterThan(0, 'the cap refused everything, including reads within it');
})->group('SPEC-024', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(function () {
        $cap = readLimit('max_concurrent_reads');
        $rate = readLimit('read_rate_limit_requests');

        if ($cap === null || $cap < 1 || $cap > 8) {
            return 'needs a service reporting a small max_concurrent_reads (1-8)';
        }

        // The rate limiter answers 429 too, so a low budget makes the two
        // indistinguishable and this criterion untestable.
        return $rate !== null && $rate < max(20, $cap * 5)
            ? "read_rate_limit_requests is {$rate} — too low to isolate the concurrency cap"
            : false;
    });

// --- AC3: the two paths do not starve each other ----------------------------

it('still signs for a token that has exhausted its read budget', function () {
    $limit = readLimit('read_rate_limit_requests') ?? 0;

    // Spend the read budget.
    $reads = concurrentReads($limit + 4);
    expect(array_filter($reads, fn (array $r): bool => $r['status'] === 429))
        ->not->toBeEmpty('the read budget was never exhausted, so this proves nothing');

    // Signing must be unaffected: a verification loop must not be able to stop
    // an application from marking its own output.
    [$signer] = ServiceHarness::signerAndReader();
    $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('SPEC-024 AC3')
        ->build();

    $signed = $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest);

    expect($signed->bytes)->not->toBe('');
})->group('SPEC-024', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(function () {
        $read = readLimit('read_rate_limit_requests');
        $sign = readLimit('rate_limit_requests');

        if ($read === null || $read > 20) {
            return 'needs a service with a small read_rate_limit_requests';
        }

        // The sign budget must be large enough that spending the read budget
        // cannot have exhausted it by coincidence — otherwise a shared budget
        // would pass this too, and the criterion would prove nothing.
        return $sign !== null && $sign <= $read + 8
            ? "rate_limit_requests is {$sign} — too close to the read budget to tell the two apart"
            : false;
    });

// --- AC6: /health is never itself limited -----------------------------------

it('answers /health while the read path is saturated', function () {
    $cap = readLimit('max_concurrent_reads') ?? 4;

    // Launch a burst in the background and ask /health while it runs.
    $payload = (string) tempnam(sys_get_temp_dir(), 'spec024h');
    file_put_contents($payload, json_encode(readBody(), JSON_THROW_ON_ERROR));

    $command = sprintf(
        'seq %d | xargs -P %d -I{} curl -s -o /dev/null -X POST %s -H %s -H %s --data-binary @%s > /dev/null 2>&1 &',
        max(20, $cap * 6),
        max(20, $cap * 6),
        escapeshellarg(ServiceHarness::baseUrl().'/v1/read'),
        escapeshellarg('Authorization: Bearer '.ServiceHarness::apiKey()),
        escapeshellarg('Content-Type: application/json'),
        escapeshellarg($payload),
    );
    shell_exec($command);

    $health = ServiceHarness::health();

    expect($health['status'] ?? null)->toBe('ok');

    @unlink($payload);
})->group('SPEC-024', 'integration')->skip($skipUnlessReachable);
