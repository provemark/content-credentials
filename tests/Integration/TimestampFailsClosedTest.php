<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-007 AC5 — an unreachable TSA fails closed.
 *
 * The criterion was verified by hand on 2026-07-28 and never automated, which
 * is why its traceability cell read as prose and `bin/spec-check.php` reported
 * it unresolved for six weeks. It needs a configuration no other profile can
 * carry: with `CONTENTAUTH_TSA_URL` pointing at a dead endpoint EVERY signing
 * request fails, so this file is the only thing the `tsa-unreachable` profile
 * runs — the same shape as `rate-limited`, which exists for the same reason.
 *
 * What fails closed means here, and why it is the interesting half: SPEC-007
 * takes the async path whenever a TSA is configured, so a timestamp is either
 * obtained or the signature is not produced at all. The failure mode this
 * guards against is not an error — it is a 200 carrying an untimestamped
 * signature, which a caller would bank as success and only discover later, when
 * the certificate that signed it has expired and nothing proves when it was
 * used.
 *
 * Measured 2026-08-13 against a service with CONTENTAUTH_TSA_URL=http://127.0.0.1:9/tsa:
 * HTTP 500, body {error, cid}, no signed_content, in 0.1s.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 *
 * @see specs/SPEC-007-tsa-timestamping.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/**
 * Only a configuration that declares its TSA dead may run this.
 *
 * The first version of this guard asked `/health` whether `timestamping` was
 * true. That is the wrong question: it reports whether a TSA is CONFIGURED, not
 * whether it can be reached. Measured 2026-08-13 on a developer machine whose
 * `.env` carries a working TSA — signing returned 200 with a timestamp and this
 * file failed, correctly and uselessly. `/health` deliberately does not publish
 * the URL, so the test cannot tell reachable from dead by asking.
 *
 * So the profile declares it. `CC_TSA_EXPECT_UNREACHABLE=1` is set by the
 * `tsa-unreachable` CI profile and by nothing else, which also keeps the
 * criterion honest: a configuration nobody set up on purpose cannot accidentally
 * assert that signing must fail.
 */
$skipUnlessDeadTsa = fn () => getenv('CC_TSA_EXPECT_UNREACHABLE') !== '1'
    ? 'needs the tsa-unreachable profile (CC_TSA_EXPECT_UNREACHABLE=1)'
    : false;

it('refuses to sign when the timestamp authority cannot be reached', function () {
    $response = (new Client(['http_errors' => false]))->post(
        ServiceHarness::baseUrl().'/v1/sign',
        [
            'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
            'json' => [
                'content' => base64_encode(ServiceHarness::fixtureBytes()),
                'mime_type' => 'image/png',
                'extra_assertions' => [],
            ],
            'timeout' => 60,
        ],
    );

    $status = $response->getStatusCode();
    $decoded = json_decode((string) $response->getBody(), true);
    $body = is_array($decoded) ? $decoded : [];

    expect($status)->toBeGreaterThanOrEqual(400, 'a signature was produced without a timestamp')
        // The whole point of the criterion: not "it errored" but "it returned
        // nothing signable". A 500 that still carried signed_content would be a
        // caller banking an untimestamped signature.
        ->and($body)->not->toHaveKey('signed_content')
        ->and($body)->toHaveKey('error')
        // The correlation id is what makes the refusal traceable in the audit
        // stream; SPEC-017 put the middleware ahead of the parser so that even
        // an unparseable request carries one.
        ->and($body)->toHaveKey('cid');
})->group('SPEC-007', 'integration')
    ->skip($skipUnlessReachable)
    ->skip($skipUnlessDeadTsa);

it('reports the timestamp authority as configured on /health', function () {
    // The guard above is only meaningful if /health tells the truth about the
    // configuration. Without this, a service that silently dropped the TSA would
    // make the criterion above skip rather than fail — the silent-skip failure
    // mode this repository has documented five times.
    expect(ServiceHarness::health()['timestamping'] ?? null)->toBeTrue();
})->group('SPEC-007', 'integration')
    ->skip($skipUnlessReachable)
    ->skip($skipUnlessDeadTsa);
