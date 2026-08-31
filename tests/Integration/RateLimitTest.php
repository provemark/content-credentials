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
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
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
 * A large but valid PNG, built without GD so it works on any runner.
 *
 * The concurrency criteria need requests that genuinely overlap, and that turns
 * out to be about request *cost*, not request *count*. Measured against a
 * service with no TSA configured — where a signature of the small fixture takes
 * ~58ms — even 40 parallel curl processes never put more than about two
 * requests in flight, because forking the clients costs more than the server
 * spends answering them. `in_flight` stayed at 0 for the whole burst and
 * nothing was ever refused.
 *
 * The concurrency tests passed locally only because this machine's .env points
 * at a TSA, whose round-trip made each signature slow enough to overlap. That
 * is a test passing for an incidental reason, which is worse than one that
 * fails.
 */
function largePngBytes(int $side = 900): string
{
    $raw = '';
    for ($y = 0; $y < $side; $y++) {
        $raw .= "\x00";                       // filter: none
        $raw .= random_bytes($side * 3);      // RGB, incompressible on purpose
    }

    $chunk = function (string $type, string $data): string {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    };

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NN', $side, $side)."\x08\x02\x00\x00\x00")
        .$chunk('IDAT', (string) gzcompress($raw, 1))
        .$chunk('IEND', '');
}

/**
 * A signing request body the service will accept.
 *
 * @return array<string, mixed>
 */
function signBody(bool $large = false): array
{
    return [
        'content' => base64_encode($large ? largePngBytes() : ServiceHarness::fixtureBytes()),
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

/**
 * Fire $count signing requests as genuinely parallel OS processes.
 *
 * Guzzle's pool was not enough here. Measured against a service with no TSA
 * configured — where a signature takes ~58ms rather than the ~250ms a TSA
 * round-trip adds — a burst of 12 through Guzzle never overlapped enough to
 * reach a cap of 4, so the concurrency criteria passed locally only because
 * this machine's .env happened to point at a TSA. That is a test passing for
 * the wrong reason. `xargs -P` gives real parallelism regardless of how fast
 * the service is.
 *
 * @return array<int, int> status code => how many responses carried it
 */
function parallelSigns(int $count): array
{
    $payload = (string) tempnam(sys_get_temp_dir(), 'spec015');
    file_put_contents($payload, json_encode(signBody(true), JSON_THROW_ON_ERROR));

    $command = sprintf(
        'seq %d | xargs -P %d -I{} curl -s -o /dev/null -w %s -X POST %s -H %s -H %s --data-binary @%s',
        $count,
        $count,
        escapeshellarg('%{http_code}\n'),
        escapeshellarg(ServiceHarness::baseUrl().'/v1/sign'),
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
        $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
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

    // Retried rather than fired once. A single burst is a race: whether the cap
    // is reached depends on how fast the clients start relative to how fast the
    // service drains, and on a quick enough runner 20 requests can arrive
    // spread out enough that nothing ever exceeds it. Observed failing exactly
    // that way in CI on a run whose predecessor passed with identical code.
    //
    // Retrying does not weaken the criterion — it establishes the precondition
    // the criterion needs, that the cap was actually exceeded. Exits on the
    // first burst that shows both outcomes; only a service that never refuses
    // anything pays the full deadline.
    $accepted = 0;
    $refused = 0;
    $deadline = microtime(true) + 60;

    while (microtime(true) < $deadline) {
        $counts = parallelSigns(max(30, $cap * 8));
        $accepted += $counts[200] ?? 0;
        $refused += $counts[429] ?? 0;

        if ($accepted > 0 && $refused > 0) {
            break;
        }
    }

    // A burst must degrade by refusing the excess, not by failing everything.
    expect($refused)->toBeGreaterThan(0, 'nothing was refused above the concurrency cap')
        ->and($accepted)->toBeGreaterThan(0, 'the cap refused everything, including requests within it');
})->group('SPEC-015', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(function () {
        $cap = serviceLimit('max_concurrent_signs');
        $rate = serviceLimit('rate_limit_requests');

        if ($cap === null || $cap < 1 || $cap > 8) {
            return 'needs a service reporting a small max_concurrent_signs (1-8)';
        }

        // The rate limiter answers 429 too, so a low budget makes the two
        // indistinguishable and this criterion untestable.
        return $rate !== null && $rate < max(20, $cap * 5)
            ? "rate_limit_requests is {$rate} — too low to isolate the concurrency cap"
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

    // Enough parallelism that the service is genuinely busy while we look —
    // a handful of requests drains faster than /health can be polled when no
    // TSA round-trip is in the path (see parallelSigns).
    $burst = max(20, $cap * 5);
    $payload = (string) tempnam(sys_get_temp_dir(), 'spec015');
    file_put_contents($payload, json_encode(signBody(true), JSON_THROW_ON_ERROR));

    // Sustain the load rather than fire one burst and hope to catch it.
    //
    // The previous version fired `$burst` requests once and polled to a deadline
    // for them. That fixed *when* we look but not *how long there is to look
    // at*, and it went red again on 2026-08-31 in the `hardened` profile, on a
    // commit that changed one markdown file. `in_flight` counts only requests
    // past the rate-limit and concurrency gates (`service/server.js`) and drops
    // again on response close, so a burst that drains quickly — or whose
    // requests are refused at the door and therefore never counted — leaves
    // nothing to observe, and the poll spends its whole deadline seeing zero.
    //
    // Driving the load from a sentinel makes the busy window last as long as we
    // are looking, instead of the other way round.
    $sentinel = (string) tempnam(sys_get_temp_dir(), 'spec015run');
    $codes = (string) tempnam(sys_get_temp_dir(), 'spec015codes');

    $command = sprintf(
        // Paced, and capped as a safety net rather than as a limit we expect to
        // reach. The happy path exits after one burst, so this costs what the
        // previous version cost; the pacing and the cap only bite when the test
        // is already failing, and they are what keeps that failure from
        // draining the rate-limit budget the rest of the suite shares — one
        // clear red turning into ten confusing ones.
        'for _ in $(seq 1 60); do [ -f %s ] || break; '
        .'seq %d | xargs -P %d -I{} curl -s -o /dev/null -w "%%{http_code}\n" '
        .'-X POST %s -H %s -H %s --data-binary @%s >> %s; sleep 0.2; done',
        escapeshellarg($sentinel),
        $burst,
        $burst,
        escapeshellarg(ServiceHarness::baseUrl().'/v1/sign'),
        escapeshellarg('Authorization: Bearer '.ServiceHarness::apiKey()),
        escapeshellarg('Content-Type: application/json'),
        escapeshellarg($payload),
        escapeshellarg($codes),
    );

    shell_exec($command.' > /dev/null 2>&1 &');

    // Exits as soon as the service reports work in flight, so the common case
    // is fast and only a genuine failure pays the full deadline.
    $peak = 0;
    $health = [];
    $deadline = microtime(true) + 15;

    while (microtime(true) < $deadline) {
        $health = ServiceHarness::health();
        $seen = $health['in_flight'] ?? 0;
        $peak = max($peak, is_int($seen) ? $seen : 0);

        if ($peak > 0) {
            break;
        }

        usleep(20_000);
    }

    // Stop the load BEFORE asserting: a failing expectation must not leave a
    // loop running against the shared service for the rest of the suite.
    @unlink($sentinel);
    sleep(3);

    // What the service actually answered, so a failure says *why* nothing was
    // in flight and not merely that nothing was. The 2026-08-31 failure could
    // not distinguish "refused at the door" from "drained before we looked",
    // and that ambiguity is what made it expensive to diagnose. An empty
    // summary is itself the answer: no client ever ran.
    $answered = array_count_values(array_filter(array_map(
        trim(...),
        explode("\n", (string) @file_get_contents($codes)),
    )));
    ksort($answered);
    $summary = json_encode($answered, JSON_THROW_ON_ERROR);

    @unlink($payload);
    @unlink($codes);

    expect($health)->toHaveKey('status')
        ->and($health['status'])->toBe('ok')
        ->and($peak)->toBeGreaterThan(
            0,
            "the service reported nothing in flight while signing was sustained for up to 15s; it answered {$summary}"
        );
})->group('SPEC-015', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(function () {
        if (serviceLimits() === []) {
            return 'service does not report limits (pre-SPEC-015)';
        }

        // Saturation cannot be observed if the rate limiter refuses the burst
        // before anything is signed — the same reason AC3 skips here.
        $cap = serviceLimit('max_concurrent_signs') ?? 1;
        $rate = serviceLimit('rate_limit_requests');

        return $rate !== null && $rate < max(20, $cap * 5)
            ? "rate_limit_requests is {$rate} — the burst is refused before anything is in flight"
            : false;
    });

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
