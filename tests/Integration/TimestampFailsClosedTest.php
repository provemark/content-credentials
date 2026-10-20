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
 * The request carries a valid manifest, and the test asserts that the refusal
 * came from the TSA. Until 2026-10-20 it sent `extra_assertions: []`. That is a
 * claim-v2 manifest without an actions assertion, which c2pa-rs 0.91 refuses on
 * its own since it verifies after signing (c2pa-rs #2277). On c2pa-node 0.9.10
 * this test therefore passed while the service signed every VALID manifest
 * without a timestamp and without contacting the TSA (contentauth/c2pa-js#244).
 * A refusal is only evidence when its reason is the one under test.
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
/** The running signing-service container id, or null when it is not up. */
function spec007Container(): ?string
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

/** The TSA URL the running service was started with, read from its environment. */
function spec007TsaUrl(string $container): string
{
    $raw = shell_exec(sprintf('docker exec %s printenv CONTENTAUTH_TSA_URL 2>/dev/null', escapeshellarg($container)));

    return is_string($raw) ? trim($raw) : '';
}

/**
 * The `reason` of the audit record carrying $cid, or '' when the log has none.
 * SPEC-012 keeps the engine's message out of the response and puts it here.
 */
function spec007AuditReason(string $container, string $cid): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $raw = shell_exec(sprintf('docker logs --tail 400 %s 2>&1', escapeshellarg($container)));

        foreach (explode("\n", is_string($raw) ? $raw : '') as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded) && ($decoded['cid'] ?? null) === $cid) {
                return is_string($decoded['reason'] ?? null) ? $decoded['reason'] : '';
            }
        }

        usleep(200_000);
    }

    return '';
}

$skipUnlessContainer = fn () => spec007Container() === null
    ? 'signing-service container not running — the refusal reason is read from its audit log'
    : false;

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
                // A manifest the engine accepts, so the TSA is what decides.
                'extra_assertions' => [[
                    'label' => 'c2pa.actions.v2',
                    'data' => ['actions' => [[
                        'action' => 'c2pa.created',
                        'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
                        'softwareAgent' => ['name' => 'SPEC-007 AC5'],
                    ]]],
                ]],
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

    // And it was the TSA that refused, not something else on the way. The
    // engine names the URL it could not reach (c2pa-node 0.9.5: "an error
    // occurred from the underlying http resolver: error sending request for url
    // (http://127.0.0.1:9/tsa)").
    $container = (string) spec007Container();
    $tsaUrl = spec007TsaUrl($container);
    $cid = is_string($body['cid'] ?? null) ? $body['cid'] : '';

    expect($tsaUrl)->not->toBe('', 'the service container carries no CONTENTAUTH_TSA_URL')
        ->and(spec007AuditReason($container, $cid))->toContain($tsaUrl);
})->group('SPEC-007', 'integration')
    ->skip($skipUnlessReachable)
    ->skip($skipUnlessContainer)
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
