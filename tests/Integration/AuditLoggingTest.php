<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;
use Psr\Http\Message\ResponseInterface;

/**
 * SPEC-012 — audit logging for signing requests.
 *
 * The service kept no record of what it signed, so a fabricated credential
 * carrying this certificate could not be distinguished from a genuine one —
 * which makes every credential issued under it suspect.
 *
 * Records go to stdout, so these read them back out of the container log and
 * match on the correlation id the response carries. That coupling is the point
 * of AC3: an operator has to be able to get from a client-side failure to the
 * record that explains it.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 *
 * @see specs/SPEC-012-audit-logging.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/** The running signing-service container id, or null when it is not up. */
function spec012Container(): ?string
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

$skipUnlessContainer = fn () => spec012Container() === null
    ? 'signing-service container not running — start it with docker compose up -d'
    : false;

/**
 * POST to /v1/sign and return the status, the parsed body and the correlation
 * id the service reported.
 *
 * @param  array<string, mixed>  $body
 * @return array{status: int, cid: string, body: array<array-key, mixed>}
 */
function auditedSign(array $body = []): array
{
    $response = (new Client(['http_errors' => false]))->post(ServiceHarness::baseUrl().'/v1/sign', [
        'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
        'json' => $body + [
            'content' => base64_encode(ServiceHarness::fixtureBytes()),
            'mime_type' => 'image/png',
            'extra_assertions' => [[
                'label' => 'c2pa.actions.v2',
                'data' => ['actions' => [[
                    'action' => 'c2pa.created',
                    'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
                ]]],
            ]],
        ],
    ]);

    $decoded = json_decode((string) $response->getBody(), true);
    $parsed = is_array($decoded) ? $decoded : [];

    $cid = $response->getHeaderLine('X-Correlation-Id');
    if ($cid === '' && is_string($parsed['cid'] ?? null)) {
        $cid = $parsed['cid'];
    }

    return ['status' => $response->getStatusCode(), 'cid' => $cid, 'body' => $parsed];
}

/**
 * The audit record carrying $cid, or null when the log has none.
 *
 * @return array<array-key, mixed>|null
 */
function auditRecordFor(string $cid): ?array
{
    $container = spec012Container();
    if ($container === null || $cid === '') {
        return null;
    }

    // Give the write a moment to reach the container log.
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

/**
 * One field of a record as a string — records are decoded from JSON, so every
 * field is `mixed` until it is checked.
 *
 * @param  array<array-key, mixed>|null  $record
 */
function recordString(?array $record, string $key): string
{
    $value = $record[$key] ?? null;

    return is_string($value) ? $value : '';
}

// --- AC1: a successful signature is recorded --------------------------------

it('records a successful signature', function () {
    $result = auditedSign(['creator_name' => 'SPEC-012 audit']);
    expect($result['status'])->toBe(200);

    $record = auditRecordFor($result['cid']);

    expect($record)->not->toBeNull('no audit record carried the response correlation id');
    expect($record['outcome'] ?? null)->toBe('signed')
        ->and($record['event'] ?? null)->toBe('sign')
        ->and($record['mime_type'] ?? null)->toBe('image/png')
        ->and($record['creator_name'] ?? null)->toBe('SPEC-012 audit')
        ->and($record['input_sha256'] ?? null)->toBe(hash('sha256', ServiceHarness::fixtureBytes()))
        ->and($record['input_bytes'] ?? null)->toBe(strlen(ServiceHarness::fixtureBytes()))
        ->and($record['output_sha256'] ?? null)->toBeString()
        ->and($record['assertion_labels'] ?? null)->toContain('c2pa.actions.v2')
        ->and($record['digital_source_types'] ?? null)
        ->toContain('http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia')
        ->and($record['timestamped'] ?? null)->toBeBool()
        ->and($record['ts'] ?? null)->toBeString();

    expect(strtotime(recordString($record, 'ts')))->toBeInt();
})->group('SPEC-012', 'integration')->skip($skipUnlessReachable)->skip($skipUnlessContainer);

// --- AC2: a rejected request is recorded ------------------------------------

it('records a rejected request with the reason', function () {
    // image/gif became a supported type in SPEC-021; image/bmp is still
    // outside the allow-list, which is what this case needs.
    $result = auditedSign(['mime_type' => 'image/bmp']);
    expect($result['status'])->toBe(400);

    $record = auditRecordFor($result['cid']);

    expect($record)->not->toBeNull('a rejection produced no audit record');
    expect($record['outcome'] ?? null)->toBe('rejected')
        ->and($record['reason'] ?? null)->toBeString()
        ->and($record['reason'] ?? '')->not->toBe('')
        ->and($record['output_sha256'] ?? null)->toBeNull();
})->group('SPEC-012', 'integration')->skip($skipUnlessReachable)->skip($skipUnlessContainer);

// AC2, for a refusal that never reached the route handler.
//
// Measured 2026-08-07: an assertion nested deeply enough to overflow
// `JSON.stringify` in the size check threw past every audit call, so the request
// left no trace at all. "Every signing request is recorded, accepted and refused
// alike" held only for the refusals the service managed to reach.
it('records a refusal that failed inside validation, not just one it reached', function () {
    $depth = 20000;
    $nested = str_repeat('[', $depth).str_repeat(']', $depth);
    $body = sprintf(
        '{"content":%s,"mime_type":"image/png","extra_assertions":[{"label":"org.example.deep","data":%s}]}',
        json_encode(base64_encode(ServiceHarness::fixtureBytes())),
        $nested,
    );

    $response = (new Client(['http_errors' => false]))->post(ServiceHarness::baseUrl().'/v1/sign', [
        'headers' => [
            'Authorization' => 'Bearer '.ServiceHarness::apiKey(),
            'Content-Type' => 'application/json',
        ],
        'body' => $body,
    ]);

    $cid = $response->getHeaderLine('X-Correlation-Id');
    expect($cid)->not->toBe('');

    $record = auditRecordFor($cid);

    expect($record)->not->toBeNull('a request that failed during validation produced no audit record')
        ->and($record['event'] ?? null)->toBe('sign')
        ->and($record['outcome'] ?? null)->toBe('rejected')
        ->and($record['reason'] ?? null)->toBeString();
})->group('SPEC-012', 'integration')->skip($skipUnlessReachable)->skip($skipUnlessContainer);

// SPEC-024 AC4: a refused READ is recorded, while a successful one is not.
//
// SPEC-012 deliberately writes nothing for a read that succeeds — reading does
// not exercise the signing key, so there is nothing to attest to. A refusal is
// different: it is about the service's own health, which is what an operator
// needs to see. That distinction is the criterion, so both halves are asserted.
it('records a refused read but not a successful one', function () {
    $client = new Client(['http_errors' => false, 'timeout' => 30]);
    $url = ServiceHarness::baseUrl().'/v1/read';
    $options = [
        'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
        'json' => ['content' => base64_encode(ServiceHarness::fixtureBytes()), 'mime_type' => 'image/png'],
    ];

    $ok = $client->post($url, $options);
    expect($ok->getStatusCode())->toBe(200);
    expect(auditRecordFor($ok->getHeaderLine('X-Correlation-Id')))
        ->toBeNull('a successful read was audited; SPEC-012 decided against that');

    // Spend the read budget to provoke a refusal.
    $limit = readLimit('read_rate_limit_requests') ?? 0;
    $refusal = null;
    for ($i = 0; $i < $limit + 6; $i++) {
        $response = $client->post($url, $options);
        if ($response->getStatusCode() === 429) {
            $refusal = $response;
            break;
        }
    }

    expect($refusal)->not->toBeNull('the read budget was never exhausted, so this proves nothing');

    $cid = $refusal instanceof ResponseInterface ? $refusal->getHeaderLine('X-Correlation-Id') : '';
    $record = auditRecordFor($cid);

    expect($record)->not->toBeNull('a refused read produced no audit record')
        ->and($record['event'] ?? null)->toBe('read')
        ->and($record['outcome'] ?? null)->toBe('rejected')
        ->and($record['reason'] ?? null)->toBeString()
        ->and($record['token_id'] ?? null)->toBeString();
})->group('SPEC-024', 'integration')
    ->skip($skipUnlessReachable)
    ->skip($skipUnlessContainer)
    ->skip(function () {
        $limit = readLimit('read_rate_limit_requests');

        return $limit === null || $limit > 20
            ? 'needs a service with a small read_rate_limit_requests (run the rate-limited profile)'
            : false;
    });

// --- AC3: the correlation id reaches the client -----------------------------

it('returns a correlation id on success and on failure', function () {
    $ok = auditedSign();
    expect($ok['cid'])->not->toBe('');

    $bad = auditedSign(['mime_type' => 'image/bmp']);
    expect($bad['cid'])->not->toBe('')
        ->and($bad['body']['cid'] ?? null)->toBe($bad['cid'])
        ->and($bad['cid'])->not->toBe($ok['cid']);
})->group('SPEC-012', 'integration')->skip($skipUnlessReachable);

// --- AC4: errors no longer leak internals -----------------------------------

it('returns a generic message and a correlation id when signing fails', function () {
    // Valid base64, declared as a PNG, but not an image — c2pa fails inside the
    // signing path and its error text names the temp file.
    $result = auditedSign(['content' => base64_encode('definitely not a png')]);

    expect($result['status'])->toBeGreaterThanOrEqual(500)
        ->and($result['cid'])->not->toBe('');

    $error = recordString($result['body'], 'error');
    expect($error)->not->toContain('/tmp')
        ->and($error)->not->toContain('/app')
        ->and($error)->not->toContain('sign-')
        ->and($error)->not->toContain('.js');

    // AC7: the failure is still recorded, and the detail lives in the record.
    $record = auditRecordFor($result['cid']);
    expect($record)->not->toBeNull('a signing failure produced no audit record');
    expect($record['outcome'] ?? null)->toBe('failed')
        ->and($record['reason'] ?? null)->toBeString();
})->group('SPEC-012', 'integration')->skip($skipUnlessReachable)->skip($skipUnlessContainer);

// --- AC5: the record never contains secrets or payloads ---------------------

it('never records the token, the payload or unbounded caller strings', function () {
    // A creator_name over the request limit is refused outright (SPEC-011 AC6),
    // so the unbounded-input path into a record is an assertion label: it rides
    // inside the assertion size budget and would otherwise be copied verbatim.
    $longLabel = 'org.example.'.str_repeat('L', 500);
    $result = auditedSign([
        'creator_name' => 'SPEC-012 denylist',
        'extra_assertions' => [[
            'label' => $longLabel,
            'data' => ['x' => 1],
        ], [
            'label' => 'c2pa.actions.v2',
            'data' => ['actions' => [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia']]],
        ]],
    ]);

    $record = auditRecordFor($result['cid']);
    expect($record)->not->toBeNull();

    $json = json_encode($record, JSON_THROW_ON_ERROR);
    $contentB64 = base64_encode(ServiceHarness::fixtureBytes());

    expect($json)->not->toContain(ServiceHarness::apiKey())
        ->and($json)->not->toContain(substr($contentB64, 0, 64))
        ->and($json)->not->toContain('BEGIN CERTIFICATE')
        ->and($json)->not->toContain('PRIVATE KEY')
        ->and($json)->not->toContain('signed_content');

    // Caller-supplied strings are length-capped, so a caller cannot write
    // unbounded data into the operator's log.
    $labels = is_array($record['assertion_labels'] ?? null) ? $record['assertion_labels'] : [];
    expect($labels)->not->toBeEmpty();

    foreach ($labels as $label) {
        expect($label)->toBeString();
        expect(strlen(is_string($label) ? $label : ''))->toBeLessThan(strlen($longLabel));
    }

    expect(strlen(recordString($record, 'creator_name')))->toBeLessThanOrEqual(256);
})->group('SPEC-012', 'integration')->skip($skipUnlessReachable)->skip($skipUnlessContainer);

// --- AC6: the caller is identified without revealing the credential ---------

it('identifies the token without revealing it', function () {
    $first = auditedSign();
    $second = auditedSign();

    $a = auditRecordFor($first['cid']);
    $b = auditRecordFor($second['cid']);

    expect($a)->not->toBeNull()->and($b)->not->toBeNull();

    $tokenId = recordString($a, 'token_id');

    expect($tokenId)->not->toBe('')
        ->and($tokenId)->toBe(recordString($b, 'token_id'))
        ->and($tokenId)->not->toBe(ServiceHarness::apiKey())
        ->and(ServiceHarness::apiKey())->not->toContain($tokenId);
})->group('SPEC-012', 'integration')->skip($skipUnlessReachable)->skip($skipUnlessContainer);

// --- AC8: one valid JSON record per line ------------------------------------

it('writes each record as a single line of JSON', function () {
    $result = auditedSign();
    $container = spec012Container();

    $raw = shell_exec(sprintf('docker logs --tail 100 %s 2>&1', escapeshellarg((string) $container)));

    $records = 0;
    foreach (explode("\n", is_string($raw) ? $raw : '') as $line) {
        $line = trim($line);
        if ($line === '' || ! str_starts_with($line, '{')) {
            continue;
        }

        expect(json_decode($line, true))->toBeArray("not valid JSON on one line: {$line}");
        $records++;
    }

    expect($records)->toBeGreaterThan(0)
        ->and($result['cid'])->not->toBe('');
})->group('SPEC-012', 'integration')->skip($skipUnlessReachable)->skip($skipUnlessContainer);

// --- AC9: audit loss is visible, and never blocks signing -------------------

it('keeps signing and reports degraded when the audit write fails', function () {
    $container = spec012Container();

    // Copy the fixture in rather than assuming it is there: the container is
    // recreated on every `docker compose up`, so anything placed by hand is
    // gone by the next run.
    shell_exec(sprintf(
        'docker cp %s %s:/tmp/spec012-fixture.png 2>/dev/null',
        escapeshellarg(dirname(__DIR__).'/fixture.png'),
        escapeshellarg((string) $container),
    ));

    // Start a second instance whose stdout is /dev/full — every write fails
    // with ENOSPC — then sign against it and ask /health what it thinks.
    // A logging outage must not become a signing outage.
    $script = <<<'SH'
cd /app
PORT=3999 node server.js > /dev/full 2>/dev/null &
sleep 3
node -e '
  const fs = require("fs");
  const body = {
    content: fs.readFileSync("/tmp/spec012-fixture.png").toString("base64"),
    mime_type: "image/png",
    // Carry the AI marking: an instance running with REQUIRE_AI_MARKING=true
    // (SPEC-011 AC8) refuses anything else, and this probe asserts a 200.
    extra_assertions: [{label:"c2pa.actions.v2",data:{actions:[{action:"c2pa.created",digitalSourceType:"http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia"}]}}],
  };
  const auth = { "Authorization": "Bearer " + process.env.CONTENTAUTH_API_KEY, "Content-Type": "application/json" };
  (async () => {
    const sign = await fetch("http://127.0.0.1:3999/v1/sign", { method: "POST", headers: auth, body: JSON.stringify(body) });
    const health = await fetch("http://127.0.0.1:3999/health");
    console.log(JSON.stringify({ sign_status: sign.status, health: await health.json() }));
  })();
'
kill %1 2>/dev/null
SH;

    $raw = shell_exec(sprintf(
        'docker exec %s sh -c %s 2>&1',
        escapeshellarg((string) $container),
        escapeshellarg($script),
    ));

    $line = '';
    foreach (explode("\n", is_string($raw) ? $raw : '') as $candidate) {
        if (str_contains($candidate, 'sign_status')) {
            $line = trim($candidate);
        }
    }

    expect($line)->not->toBe('probe produced no output: '.(string) $raw);

    $decoded = json_decode($line, true);
    expect($decoded)->toBeArray();

    $probe = is_array($decoded) ? $decoded : [];
    $health = is_array($probe['health'] ?? null) ? $probe['health'] : [];

    // Signing still succeeds: whoever can break the write must not be able to
    // stop all signing.
    expect($probe['sign_status'] ?? null)->toBe(200)
        // ...but the loss is visible to monitoring rather than silent.
        ->and($health['audit_degraded'] ?? null)->toBeTrue();
})->group('SPEC-012', 'integration')->skip($skipUnlessContainer);

// --- AC10: the personal-data implication is stated --------------------------

it('documents what is and is not recorded', function () {
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/docs/service.md');

    expect($readme)->toContain('audit')
        ->and(strtolower($readme))->toContain('personal data')
        ->and($readme)->toContain('creator_name')
        ->and($readme)->toContain('retention');
})->group('SPEC-012');
