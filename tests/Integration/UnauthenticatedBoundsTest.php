<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-030 — bounding the service before authentication.
 *
 * Every budget the service has is spent after the bearer token is verified, and
 * the body is parsed before it. Measured 2026-08-08: a 26 MB body with an
 * INVALID token answered 413, which only the parser can produce, and sixty
 * invalid-token requests produced sixty 401s and zero 429s.
 *
 * Two profiles, because the criteria cannot coexist in one configuration. AC2
 * and AC3 need a generous failed-authentication budget so their few bad-token
 * requests are answered 401/413 rather than 429; AC4, AC5 and AC8 need a small
 * one so the budget can actually be exhausted. Same split as SPEC-014's
 * trust-on/trust-off and SPEC-024's read-limited.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 *
 * @see specs/SPEC-030-bounding-the-unauthenticated-path.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/** The running signing-service container id, or null when it is not up. */
function spec030Container(): ?string
{
    foreach (['docker compose', 'docker-compose'] as $binary) {
        $raw = shell_exec($binary.' ps -q service 2>/dev/null');
        $id = is_string($raw) ? trim($raw) : '';

        if ($id !== '') {
            return $id;
        }
    }

    return null;
}

/** The failed-authentication budget the running service reports, or null when it reports none. */
function spec030AuthLimit(): ?int
{
    $limits = ServiceHarness::health()['limits'] ?? null;
    $limit = is_array($limits) ? ($limits['auth_fail_limit'] ?? null) : null;

    return is_int($limit) ? $limit : null;
}

/**
 * A small budget means the `auth-limited` profile: exhaustible inside a test.
 * A generous one (or none reported yet) means an ordinary profile.
 */
function spec030IsAuthLimited(): bool
{
    $limit = spec030AuthLimit();

    return $limit !== null && $limit > 0 && $limit <= 10;
}

$skipUnlessAuthLimited = fn () => ! spec030IsAuthLimited()
    ? 'needs the auth-limited profile (a small AUTH_FAIL_LIMIT)'
    : false;

$skipWhenAuthLimited = fn () => spec030IsAuthLimited()
    ? 'the auth-limited profile refuses bad tokens with 429 before these can observe 401'
    : false;

$skipUnlessContainer = fn () => spec030Container() === null
    ? 'signing-service container not running — start it with docker compose up -d'
    : false;

/**
 * POST to /v1/sign with an explicit token, reporting status and correlation id.
 *
 * @return array{status: int, cid: string, retryAfter: string, error: string}
 */
function spec030Post(string $token, int $paddingBytes = 0): array
{
    $body = [
        'content' => base64_encode(ServiceHarness::fixtureBytes()),
        'mime_type' => 'image/png',
    ];

    if ($paddingBytes > 0) {
        // Oversize the BODY without oversizing the asset: the parser refuses on
        // the declared body length, so any field will do.
        $body['creator_name'] = str_repeat('A', $paddingBytes);
    }

    $response = (new Client(['http_errors' => false]))->post(ServiceHarness::baseUrl().'/v1/sign', [
        'headers' => ['Authorization' => 'Bearer '.$token],
        'json' => $body,
    ]);

    $decoded = json_decode((string) $response->getBody(), true);
    $decoded = is_array($decoded) ? $decoded : [];

    $cid = $response->getHeaderLine('X-Correlation-Id');
    if ($cid === '' && is_string($decoded['cid'] ?? null)) {
        $cid = $decoded['cid'];
    }

    return [
        'status' => $response->getStatusCode(),
        'cid' => $cid,
        'retryAfter' => $response->getHeaderLine('Retry-After'),
        'error' => is_string($decoded['error'] ?? null) ? $decoded['error'] : '',
    ];
}

/**
 * Every audit record the container has written for $cid.
 *
 * A local copy rather than a call into another test file, which only exists when
 * Pest collects that file (ServiceHarness::mediaFixture() carries the same note).
 *
 * @return list<array<array-key, mixed>>
 */
function spec030AuditRecords(string $cid): array
{
    $container = spec030Container();
    if ($container === null || $cid === '') {
        return [];
    }

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $raw = shell_exec(sprintf('docker logs --tail 600 %s 2>&1', escapeshellarg($container)));
        $found = [];

        foreach (explode("\n", is_string($raw) ? $raw : '') as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '{') || ! str_contains($line, $cid)) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['cid'] ?? null) === $cid) {
                $found[] = $decoded;
            }
        }

        if ($found !== []) {
            return $found;
        }

        usleep(200_000);
    }

    return [];
}

/** How many audit records the container has written since $sinceLine, matching $needle. */
function spec030CountRecords(string $needle): int
{
    $container = spec030Container();
    if ($container === null) {
        return 0;
    }

    $raw = shell_exec(sprintf('docker logs --tail 1000 %s 2>&1', escapeshellarg($container)));
    $count = 0;

    foreach (explode("\n", is_string($raw) ? $raw : '') as $line) {
        $line = trim($line);
        if ($line !== '' && str_starts_with($line, '{') && str_contains($line, $needle)) {
            $count++;
        }
    }

    return $count;
}

/** The rate-limit window the running service reports, in milliseconds. */
function spec030WindowMs(): int
{
    $limits = ServiceHarness::health()['limits'] ?? null;
    $window = is_array($limits) ? ($limits['rate_limit_window_ms'] ?? null) : null;

    return is_int($window) ? $window : 60_000;
}

/** A body comfortably past MAX_BODY_SIZE, in bytes of padding. */
function spec030OversizedPadding(): int
{
    $limits = ServiceHarness::health()['limits'] ?? null;
    $max = is_array($limits) && is_int($limits['max_body_bytes'] ?? null) ? $limits['max_body_bytes'] : 20 * 1024 * 1024;

    return $max + 1_000_000;
}

// --- AC1: the authenticated path is unchanged --------------------------------
// Regression guard. The correlation-id middleware must stay first: SPEC-017 put
// it ahead of the parser so a request that fails to parse still carries an id,
// and nothing here may push it behind authentication.

it('still signs and reads back with a valid token, carrying a correlation id', function () {
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('SPEC-030', '1.0')
        ->build();

    $signed = $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest);
    $report = $reader->read(new Asset($signed->bytes, MediaType::Png));

    expect($report->isSignatureValid())->toBeTrue()
        ->and($report->isAiGenerated())->toBeTrue();

    $refused = spec030Post(ServiceHarness::apiKey(), spec030OversizedPadding());
    expect($refused['cid'])->not->toBe('', 'a refusal must still carry a correlation id');
})->group('SPEC-030', 'integration')->skip($skipUnlessReachable);

// --- AC2: an oversized body with an invalid token is refused on the token ----

it('answers 401 rather than 413 when the token is invalid', function () {
    $result = spec030Post('not-the-api-key', spec030OversizedPadding());

    // 413 can only come from the parser, so it is proof the body was read before
    // anyone asked who sent it. Asserting "401 somewhere" would pass today for a
    // small body; the oversize is what makes this criterion mean anything.
    expect($result['status'])->toBe(401, 'the body was parsed before the token was checked');
})->group('SPEC-030', 'integration')->skip($skipUnlessReachable)->skip($skipWhenAuthLimited);

it('writes no body-parser refusal for a request it never parsed', function () {
    $needle = 'request body too large';

    $before = spec030CountRecords($needle);

    spec030Post('not-the-api-key', spec030OversizedPadding());
    usleep(400_000);
    $afterInvalid = spec030CountRecords($needle);

    expect($afterInvalid)->toBe($before, 'the body was parsed and refused before authentication ran');

    // The control case, and it is what makes the assertion above mean anything.
    // The first version of this test looped over the records for that request,
    // found none, performed zero assertions and was reported RISKY by Pest —
    // an assertion that nothing happened is only meaningful next to a
    // demonstration that something could have (NOTES Step 26).
    spec030Post(ServiceHarness::apiKey(), spec030OversizedPadding());
    usleep(400_000);

    expect(spec030CountRecords($needle))->toBeGreaterThan(
        $afterInvalid,
        'the same oversized body WITH a valid token wrote no record either, so the '
        .'assertion above proves nothing',
    );
})->group('SPEC-030', 'integration')->skip($skipUnlessContainer)->skip($skipWhenAuthLimited);

// --- AC3: an oversized body with a valid token, now attributable -------------

it('still refuses an oversized body from a valid token, and attributes it', function () {
    $result = spec030Post(ServiceHarness::apiKey(), spec030OversizedPadding());

    expect($result['status'])->toBe(413);

    $records = spec030AuditRecords($result['cid']);
    expect($records)->not->toBe([], 'the refusal was not audited');

    $record = $records[0];
    expect($record['token_id'] ?? null)->toBeString(
        'SPEC-017 could not attribute a parser refusal; with auth first, it can',
    );
})->group('SPEC-030', 'integration')->skip($skipUnlessContainer)->skip($skipWhenAuthLimited);

// --- AC4: failed authentication is bounded, globally -------------------------

it('refuses failed authentication past the budget, with Retry-After', function () {
    $limit = spec030AuthLimit() ?? 0;

    $refusals = [];
    for ($i = 0; $i <= $limit + 2; $i++) {
        $result = spec030Post('wrong-token-'.$i);
        if ($result['status'] === 429) {
            $refusals[] = $result;
        }
    }

    expect($refusals)->not->toBe([], 'the failed-authentication budget was never reached');
    expect($refusals[0]['retryAfter'])->not->toBe('', 'a 429 must say when to retry');
    expect($refusals[0]['cid'])->not->toBe('');
})->group('SPEC-030', 'integration')->skip($skipUnlessReachable)->skip($skipUnlessAuthLimited);

it('spends one budget for the whole service rather than one per source', function () {
    $limit = spec030AuthLimit() ?? 0;

    // Two distinct "sources" as far as anything the service could key on: two
    // different bad tokens. Together they must reach the refusal at $limit in
    // TOTAL. A per-token or per-address implementation would give each its own
    // budget and never refuse within this loop.
    $statuses = [];
    for ($i = 0; $i <= $limit; $i++) {
        $statuses[] = spec030Post($i % 2 === 0 ? 'wrong-token-a' : 'wrong-token-b')['status'];
    }

    // NOT toContain(429, '...'): that method is variadic, so a second argument is
    // a second NEEDLE, not a message — it would assert the array contains the
    // explanation too. NOTES Step 21 records this exact trap; this test walked
    // into it and reported a correct implementation as broken.
    expect(in_array(429, $statuses, true))->toBeTrue(
        'the budget is not a single global counter: two bad tokens each got their own',
    );
})->group('SPEC-030', 'integration')->skip($skipUnlessReachable)->skip($skipUnlessAuthLimited);

// --- AC5: the failed-authentication budget never touches the authenticated ones

it('still signs with a valid token while the failed-authentication budget is exhausted', function () {
    $limit = spec030AuthLimit() ?? 0;

    for ($i = 0; $i <= $limit + 2; $i++) {
        spec030Post('wrong-token-'.$i);
    }

    // True by construction — the budget is spent only on failure, and a valid
    // token does not fail — which is exactly why it is tested. Spending it on
    // every ATTEMPT instead would look like a one-word simplification and would
    // hand any unauthenticated caller a lever to stop all signing.
    $result = spec030Post(ServiceHarness::apiKey());

    expect($result['status'])->toBe(200, 'an unauthenticated flood blocked a legitimate signature');
})->group('SPEC-030', 'integration')->skip($skipUnlessReachable)->skip($skipUnlessAuthLimited);

// --- AC6: /health reports what is in force, and what has been tried ----------

it('reports the failed-authentication budget and a running count on /health', function () {
    $health = ServiceHarness::health();
    $limits = is_array($health['limits'] ?? null) ? $health['limits'] : [];

    expect($limits)->toHaveKey('auth_fail_limit')
        ->and($limits['auth_fail_limit'])->toBeInt();

    expect($health)->toHaveKey('auth_failures')
        ->and($health['auth_failures'])->toBeInt();
})->group('SPEC-030', 'integration')->skip($skipUnlessReachable);

it('counts a failed authentication without needing a token to see it', function () {
    $before = ServiceHarness::health()['auth_failures'] ?? null;
    expect($before)->toBeInt();

    spec030Post('wrong-token-counted');

    $after = ServiceHarness::health()['auth_failures'] ?? null;
    expect($after)->toBeInt()->toBeGreaterThan(
        is_int($before) ? $before : PHP_INT_MAX,
        'a failed authentication was not counted',
    );
})->group('SPEC-030', 'integration')->skip($skipUnlessReachable);

it('never rate limits /health itself', function () {
    // SPEC-024 AC6: an orchestrator must always be able to reach it. Weak by
    // construction — /health sits outside /v1 — and kept as a smoke check.
    for ($i = 0; $i < 20; $i++) {
        expect(ServiceHarness::health())->toHaveKey('status');
    }
})->group('SPEC-030', 'integration')->skip($skipUnlessReachable);

// --- AC8: an unauthenticated flood cannot flood the audit log ----------------

it('bounds audit records by the budget rather than by the number of requests', function () {
    $limit = spec030AuthLimit() ?? 0;
    $attempts = $limit * 3;

    // Wait the current window out first. Earlier tests in this file have already
    // spent it, and both of its records are already written — so measuring a
    // delta inside it counts zero and the upper bound would hold vacuously.
    // That is the bound working, not the test working.
    usleep(spec030WindowMs() * 1000 + 400_000);

    $before = spec030CountRecords('"outcome":"unauthenticated"');

    for ($i = 0; $i < $attempts; $i++) {
        spec030Post('wrong-token-flood-'.$i);
    }

    $written = spec030CountRecords('"outcome":"unauthenticated"') - $before;

    // Both bounds, and the lower one is not decoration: with nothing written at
    // all the upper bound holds vacuously, which is exactly how the contradiction
    // in this criterion was found (spec amended 2026-08-08).
    expect($written)->toBeGreaterThan(0, 'failed authentication was not audited at all');

    // At most two per window: the first failure, and the moment the budget runs
    // out. Independent of how many attempts arrive.
    expect($written)->toBeLessThanOrEqual(
        2,
        "{$written} records for {$attempts} attempts; an unauthenticated caller "
        .'controls how much an operator log grows',
    );
})->group('SPEC-030', 'integration')
    ->skip($skipUnlessContainer)
    ->skip($skipUnlessAuthLimited)
    ->skip(
        fn () => spec030WindowMs() > 10_000
            ? 'needs a short RATE_LIMIT_WINDOW_MS so a window turns over inside the test'
            : false,
    );
