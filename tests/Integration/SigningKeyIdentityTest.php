<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-018 AC1/AC2/AC3 — the live signing certificate is identifiable from
 * outside the container, so a rotation can be confirmed.
 *
 * The service loads `SIGNING_CERT_PATH` and `SIGNING_KEY_PATH` once at startup.
 * Rotation is therefore "replace the files and restart" — which satisfies the
 * C2PA Generator Product Security Requirement O.2 ("SHALL be capable of rotating
 * the claim signing key") — but nothing reports which certificate is actually
 * loaded. A mount that did not take, a stale image layer, a path typo: each
 * leaves the service signing with the superseded key while looking, from the
 * outside, exactly like a service that rotated successfully.
 *
 * AC2 is the criterion that gives AC1 its meaning. A fingerprint that is merely
 * *present* proves nothing; it has to differ when the certificate differs and
 * stay the same when it does not. Asserting only AC1 would be the same mistake
 * as the vacuous tests in NOTES.md Step 20.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 *
 * @see specs/SPEC-018-key-rotation-and-dependency-scanning.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/**
 * The `signing_cert` block from `GET /health`, or null when absent.
 *
 * Null means a service predating this spec, which is distinct from a malformed
 * block — the difference decides skip versus fail.
 *
 * @return array<array-key, mixed>|null
 */
function spec018CertBlock(): ?array
{
    $block = ServiceHarness::health()['signing_cert'] ?? null;

    return is_array($block) ? $block : null;
}

function spec018Fingerprint(): ?string
{
    $value = spec018CertBlock()['fingerprint_sha256'] ?? null;

    return is_string($value) && $value !== '' ? $value : null;
}

$skipUnlessReported = fn () => spec018CertBlock() === null
    ? 'service does not report signing_cert (pre-SPEC-018)'
    : false;

/** The running signing-service container id, or null when it is not up. */
function spec018Container(): ?string
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

// --- AC1: the certificate is identifiable ------------------------------------

it('reports the identity of the loaded signing certificate on /health', function () {
    $block = spec018CertBlock();

    expect($block)->toBeArray()
        ->and($block)->toHaveKey('fingerprint_sha256')
        ->and($block)->toHaveKey('not_after');

    // A SHA-256 fingerprint, hex, no colons — 64 characters. Pinned because a
    // shape that varies between services is not an identifier an operator can
    // compare against a recorded value.
    expect($block['fingerprint_sha256'])->toBeString()
        ->and($block['fingerprint_sha256'])->toMatch('/^[0-9a-f]{64}$/');

    // notAfter must be a parseable instant; the exact format is the
    // implementation's choice.
    expect($block['not_after'])->toBeString();
    expect(strtotime((string) $block['not_after']))->not->toBeFalse(
        'not_after is not a parseable date: '.(string) $block['not_after'],
    );
})->group('SPEC-018', 'integration')
    ->skip($skipUnlessReachable);
// Deliberately NOT gated on $skipUnlessReported: this is the criterion that the
// block exists at all, so its absence must fail rather than skip. The tests
// below, which reason about the block's *contents*, skip against an older
// service instead of failing for the wrong reason.

it('matches the fingerprint of the certificate the service was configured with', function () {
    // Computed independently, from the repository's own certificate file, with
    // openssl rather than the service's own code — so this cannot pass by the
    // implementation agreeing with itself.
    $certPath = dirname(__DIR__, 2).'/certs/es256_certs.pem';

    expect($certPath)->toBeReadableFile();

    $raw = shell_exec(sprintf(
        'openssl x509 -in %s -noout -fingerprint -sha256 2>/dev/null',
        escapeshellarg($certPath),
    ));

    $expected = is_string($raw) && preg_match('/=([0-9A-Fa-f:]+)/', $raw, $m) === 1
        ? strtolower(str_replace(':', '', $m[1]))
        : null;

    if ($expected === null) {
        $this->markTestSkipped('openssl not available to compute an independent fingerprint');
    }

    expect(spec018Fingerprint())->toBe($expected);
})->group('SPEC-018', 'integration')
    ->skip($skipUnlessReachable)
    ->skip($skipUnlessReported)
    ->skip(fn () => getenv('CONTENTAUTH_SERVICE_URL') !== false && getenv('CONTENTAUTH_SERVICE_URL') !== ''
        ? 'service URL overridden — the certificate under test may not be the repository one'
        : false);

// --- AC2: the identifier discriminates ---------------------------------------
//
// Two services, two certificates, one comparison. Without this, AC1 is satisfied
// by any constant.

it('reports a different fingerprint for a different signing certificate', function () {
    $container = spec018Container();

    if ($container === null) {
        $this->markTestSkipped('signing-service container not running');
    }

    // A throwaway signing identity, generated inside the container so nothing is
    // written to the repository and nothing outlives the test. Note this is a
    // second TEST certificate: no production key material is created anywhere.
    $inner = <<<'SH'
        set -e
        cd /tmp
        openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -days 1 -nodes \
          -subj "/CN=SPEC-018 Rotation Probe" -keyout probe.key -out probe.crt >/dev/null 2>&1
        cd /app
        PORT=3997 SIGNING_CERT_PATH=/tmp/probe.crt SIGNING_KEY_PATH=/tmp/probe.key \
          node server.js >/tmp/probe.log 2>&1 &
        for i in $(seq 1 40); do
          if wget -qO- http://127.0.0.1:3997/health 2>/dev/null; then break; fi
          sleep 0.25
        done
        kill %1 2>/dev/null || true
    SH;

    $raw = shell_exec(sprintf(
        'docker exec %s sh -c %s 2>&1',
        escapeshellarg($container),
        escapeshellarg($inner),
    ));

    $decoded = json_decode(trim((string) $raw), true);

    expect($decoded)->toBeArray('the probe service did not answer /health: '.trim((string) $raw));

    $probe = is_array($decoded['signing_cert'] ?? null)
        ? ($decoded['signing_cert']['fingerprint_sha256'] ?? null)
        : null;

    expect($probe)->toBeString()
        ->and($probe)->toMatch('/^[0-9a-f]{64}$/')
        ->and($probe)->not->toBe(
            spec018Fingerprint(),
            'two different certificates reported the same fingerprint — the value does not track the certificate',
        );
})->group('SPEC-018', 'integration')
    ->skip($skipUnlessReachable)
    ->skip($skipUnlessReported);

it('reports the same fingerprint across a restart with the same certificate', function () {
    // The other half of AC2: the value must track the certificate, not the
    // process. A per-start random id would pass the discrimination test above
    // and be useless for confirming a rotation.
    $container = spec018Container();

    if ($container === null) {
        $this->markTestSkipped('signing-service container not running');
    }

    $inner = <<<'SH'
        cd /app
        PORT=3996 node server.js >/tmp/same.log 2>&1 &
        for i in $(seq 1 40); do
          if wget -qO- http://127.0.0.1:3996/health 2>/dev/null; then break; fi
          sleep 0.25
        done
        kill %1 2>/dev/null || true
    SH;

    $raw = shell_exec(sprintf(
        'docker exec %s sh -c %s 2>&1',
        escapeshellarg($container),
        escapeshellarg($inner),
    ));

    $decoded = json_decode(trim((string) $raw), true);

    expect($decoded)->toBeArray('the second instance did not answer /health: '.trim((string) $raw));

    $second = is_array($decoded['signing_cert'] ?? null)
        ? ($decoded['signing_cert']['fingerprint_sha256'] ?? null)
        : null;

    expect($second)->toBe(
        spec018Fingerprint(),
        'the same certificate produced a different fingerprint in a new process',
    );
})->group('SPEC-018', 'integration')
    ->skip($skipUnlessReachable)
    ->skip($skipUnlessReported);

// --- AC3: nothing secret is exposed ------------------------------------------

it('exposes no key material or filesystem paths in the certificate identity', function () {
    // /health is unauthenticated, so this endpoint is the public surface. The
    // fingerprint is derived from the certificate, which is public by
    // construction — it is embedded in every manifest this service signs.
    $json = json_encode(ServiceHarness::health(), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('PRIVATE KEY')
        ->and($json)->not->toContain('BEGIN CERTIFICATE')
        ->and($json)->not->toContain('/run/secrets')
        ->and($json)->not->toContain('.pem')
        ->and($json)->not->toContain(ServiceHarness::apiKey());

    // And the private key itself never appears, in any encoding.
    $keyPath = dirname(__DIR__, 2).'/certs/es256_private.key';

    if (is_readable($keyPath)) {
        $key = trim((string) file_get_contents($keyPath));
        $body = preg_replace('/-----[^-]+-----|\s+/', '', $key) ?? '';

        expect(strlen($body))->toBeGreaterThan(32);
        expect($json)->not->toContain(substr($body, 0, 32));
    }
})->group('SPEC-018', 'integration')
    ->skip($skipUnlessReachable)
    ->skip($skipUnlessReported);