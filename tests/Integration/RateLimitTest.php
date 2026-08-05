<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-015 — rate limiting and concurrency bounds on `/v1/sign`.
 *
 * The service accepted unbounded concurrent work: no rate limit, no cap in
 * flight, no request timeout, against a path holding roughly four copies of the
 * asset at once. Measured 2026-08-05: signing parallelises (six concurrent in
 * ~0.42 s against ~1.52 s sequential) and does not block the event loop — so
 * these limits bound resource use, and AC4 exists because a saturated service
 * otherwise keeps reporting itself healthy.
 *
 * The limits are read from `GET /health` rather than hard-coded, so one suite
 * covers whatever an instance is configured with. AC2 needs a low limit to run
 * in reasonable time — start the service with `RATE_LIMIT_REQUESTS=5` — and
 * skips with that instruction otherwise, rather than silently passing.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=provenance`.
 *
 * @see specs/SPEC-015-rate-limiting.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/**
 * The `limits` block the service reports, or an empty array before SPEC-015.
 *
 * @return array<array-key, mixed>
 */
function serviceLimits(): array
{
    $limits = ServiceHarness::health()['limits'] ?? null;

    return is_array($limits) ? $limits : [];
}

function serviceLimit(string $key): ?int
{
    $value = serviceLimits()[$key] ?? null;

    return is_int($value) ? $value : null;
}

/**
 * A signing request body the service will accept.
 *
 * @return array<string, mixed>
 */
function signBody(): array
{
    return [
        'content' => base64_encode(ServiceHarness::fixtureBytes()),
        'mime_type' => 'image/png',
        'extra_assertions' => [[
            'label' => 'c2pa.actions.v2',
            'data' => ['actions' => [[
                'action' => 'c2pa.created',
                'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
            ]]],
        ]],
    ];
}

/**
 * Fire $count signing requests concurrently and return one entry per response.
 *
 * @return list<array{status: int, retry_after: string, cid: string}>
 */
function concurrentSigns(int $count): array
{
    $client = new Client(['http_errors' => false, 'timeout' => 30]);
    $url = ServiceHarness::baseUrl().'/v1/sign';
    $options = [
        'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
        'json' => signBody(),
    ];

    $promises = [];
    for ($i = 0; $i < $count; $i++) {
        $promises[] = $client->postAsync($url, $options);
    }

    $results = [];
    foreach (Utils::settle($promises)->wait() as $settled) {
        $response = $settled['value'] ?? null;
        if ($response === null) {
            $results[] = ['status' => 0, 'retry_after' => '', 'cid' => ''];

            continue;
        }

        $results[] = [
            'status' => $response->getStatusCode(),
            'retry_after' => $response->getHeaderLine('Retry-After'),
            'cid' => $response->getHeaderLine('X-Correlation-Id'),
        ];
    }

    return $results;
}

// --- AC7 + AC4: the service reports its limits and its saturation -----------

it('reports its configured limits and how many signs are in flight', function () {
    $health = ServiceHarness::health();

    expect($health)->toHaveKey('limits')
        ->and($health)->toHaveKey('in_flight')
        ->and($health['in_flight'])->toBeInt();

    foreach (['max_concurrent_signs', 'rate_limit_requests', 'rate_limit_window_ms'] as $key) {
        expect(serviceLimits())->toHaveKey($key)
            ->and(serviceLimits()[$key])->toBeInt();
    }
})->group('SPEC-015', 'integration')->skip($skipUnlessReachable);

it('has its limits switched on by default', function () {
    // A default-off protection is one nobody turns on. Zero is allowed, but
    // only as an explicit choice an operator can see on /health.
    expect(serviceLimit('max_concurrent_signs'))->toBeGreaterThan(0)
        ->and(serviceLimit('rate_limit_requests'))->toBeGreaterThan(0);
})->group('SPEC-015', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(fn () => serviceLimits() === [] ? 'service does not report limits (pre-SPEC-015)' : false);

// --- AC1: the legitimate path is unaffected ---------------------------------

it('signs a normal sequence of requests without interference', function () {
    [$signer, $reader] = ServiceHarness::signerAndReader();

    for ($i = 0; $i < 3; $i++) {
        $manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Png)
            ->withSoftwareAgent('SPEC-015 sequence')
            ->build();

        $signed = $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest);
        $report = $reader->read(new Asset($signed->bytes, MediaType::Png));

        expect($report->isSignatureValid())->toBeTrue()
            ->and($report->isAiGenerated())->toBeTrue();
    }
})->group('SPEC-015', 'integration')->skip($skipUnlessReachable);

// --- AC3: requests in flight are capped -------------------------------------

it('refuses the excess when more signs arrive than the cap allows', function () {
    $cap = serviceLimit('max_concurrent_signs') ?? 0;
    $results = concurrentSigns($cap * 3);

    $accepted = array_filter($results, fn (array $r): bool => $r['status'] === 200);
    $refused = array_filter($results, fn (array $r): bool => $r['status'] === 429);

    // A burst must degrade by refusing the excess, not by failing everything.
    expect($refused)->not->toBeEmpty('nothing was refused above the concurrency cap')
        ->and($accepted)->not->toBeEmpty('the cap refused everything, including requests within it')
        ->and(count($accepted) + count($refused))->toBe(count($results));

    foreach ($refused as $refusal) {
        expect($refusal['retry_after'])->not->toBe('')
            ->and($refusal['cid'])->not->toBe('');
    }
})->group('SPEC-015', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(function () {
        $cap = serviceLimit('max_concurrent_signs');

        return $cap === null || $cap < 1 || $cap > 8
            ? 'needs a service reporting a small max_concurrent_signs (1-8)'
            : false;
    });

// --- AC2: a token exceeding its rate is refused -----------------------------

it('refuses a token that exceeds its rate, and serves it again after the window', function () {
    $limit = serviceLimit('rate_limit_requests') ?? 0;

    $first = concurrentSigns($limit + 4);
    $refused = array_filter($first, fn (array $r): bool => $r['status'] === 429);

    expect($refused)->not->toBeEmpty('the rate limit refused nothing');

    foreach ($refused as $refusal) {
        expect($refusal['retry_after'])->not->toBe('');
    }

    // The budget recovers — a rate limit that never resets is an outage.
    $window = serviceLimit('rate_limit_window_ms') ?? 0;
    usleep(($window + 500) * 1000);

    $after = concurrentSigns(1);
    expect($after[0]['status'])->toBe(200, 'the token was still refused after its window elapsed');
})->group('SPEC-015', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(function () {
        $limit = serviceLimit('rate_limit_requests');
        $window = serviceLimit('rate_limit_window_ms');

        if ($limit === null || $window === null) {
            return 'service does not report rate limits (pre-SPEC-015)';
        }

        // Keep the suite quick and deterministic: exercising a 60/minute budget
        // would mean 60 real signatures and a minute of waiting.
        return $limit > 10 || $window > 10_000
            ? "start the service with RATE_LIMIT_REQUESTS=5 RATE_LIMIT_WINDOW_MS=2000 to exercise this (now {$limit}/{$window}ms)"
            : false;
    });

// --- AC4: /health stays answerable and is never rate-limited ----------------

it('answers /health while signing is saturating the cap', function () {
    $cap = serviceLimit('max_concurrent_signs') ?? 1;

    $client = new Client(['http_errors' => false, 'timeout' => 30]);
    $url = ServiceHarness::baseUrl().'/v1/sign';

    $promises = [];
    for ($i = 0; $i < $cap * 3; $i++) {
        $promises[] = $client->postAsync($url, [
            'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
            'json' => signBody(),
        ]);
    }

    // While that is in flight, /health must answer — and say it is busy.
    usleep(120_000);
    $health = ServiceHarness::health();

    Utils::settle($promises)->wait();

    expect($health)->toHaveKey('status')
        ->and($health['status'])->toBe('ok')
        ->and($health['in_flight'] ?? -1)->toBeGreaterThan(
            0,
            'the service reported nothing in flight while a burst was being signed'
        );
})->group('SPEC-015', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(fn () => serviceLimits() === [] ? 'service does not report limits (pre-SPEC-015)' : false);

// --- AC5: a stalled client cannot hold a slot -------------------------------

it('closes a connection whose body never arrives', function () {
    $url = parse_url(ServiceHarness::baseUrl());
    $host = is_array($url) && is_string($url['host'] ?? null) ? $url['host'] : '127.0.0.1';
    $port = is_array($url) && is_int($url['port'] ?? null) ? $url['port'] : 3000;

    $timeoutMs = serviceLimit('request_timeout_ms') ?? 0;
    $opened = @fsockopen($host, $port, $errno, $errstr, 5);

    expect($opened)->not->toBeFalse("could not connect to {$host}:{$port}: {$errstr}");

    if (! is_resource($opened)) {
        return;
    }

    $socket = $opened;

    // Announce a body, then never send it.
    fwrite($socket, "POST /v1/sign HTTP/1.1\r\nHost: {$host}\r\nContent-Length: 1000\r\n"
        ."Content-Type: application/json\r\n\r\n");

    stream_set_timeout($socket, (int) ceil($timeoutMs / 1000) + 5);
    $response = stream_get_contents($socket);
    $info = stream_get_meta_data($socket);
    fclose($socket);

    // Either the server answered (408/400) or it closed the connection; what it
    // must not do is hold the slot open indefinitely.
    expect($info['timed_out'])->toBeFalse('the connection was still open after the request timeout');
    expect(is_string($response))->toBeTrue();
})->group('SPEC-015', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(function () {
        $timeout = serviceLimit('request_timeout_ms');

        if ($timeout === null) {
            return 'service does not report request_timeout_ms (pre-SPEC-015)';
        }

        return $timeout > 15_000
            ? "request_timeout_ms is {$timeout} — too long to exercise in a test run"
            : false;
    });
