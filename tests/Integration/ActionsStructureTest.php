<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-029 — validating the actions structure `/v1/sign` reads.
 *
 * SPEC-011 validates the assertion envelope — count, size, depth, label — and
 * never the one structure it then walks. Four of the five actions helpers use
 * `for…of` over `data.actions`, so a non-iterable value reaches the catch-all
 * handler as a 500 with no named constraint, and a non-array value passes every
 * guard to be refused by c2pa-rs after a real signing attempt.
 *
 * These drive the HTTP contract directly rather than the PHP client, because the
 * client cannot produce the shapes under test: `ManifestBuilder` emits exactly
 * one well-formed actions assertion. AC1 and AC9 are the exceptions — they exist
 * to prove the constraint is invisible to the legitimate path, in both service
 * configurations.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`
 * against a service started with `RATE_LIMIT_REQUESTS=1000` (NOTES Step 17: the
 * suite trips a default 60/minute budget).
 *
 * @see specs/SPEC-029-actions-structure-validation.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/** The running signing-service container id, or null when it is not up. */
function spec029Container(): ?string
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

$skipUnlessContainer = fn () => spec029Container() === null
    ? 'signing-service container not running — start it with docker compose up -d'
    : false;

/**
 * POST a raw body to /v1/sign, bypassing the PHP client, and report the
 * correlation id so the audit record can be found.
 *
 * `$extraAssertions` is deliberately `mixed`: the shapes under test are exactly
 * the ones no typed signature would let through.
 *
 * @return array{status: int, error: string, cid: string}
 */
function spec029Sign(mixed $extraAssertions): array
{
    $response = (new Client(['http_errors' => false]))->post(ServiceHarness::baseUrl().'/v1/sign', [
        'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
        'json' => [
            'content' => base64_encode(ServiceHarness::fixtureBytes()),
            'mime_type' => 'image/png',
            'extra_assertions' => $extraAssertions,
        ],
    ]);

    $decoded = json_decode((string) $response->getBody(), true);
    $decoded = is_array($decoded) ? $decoded : [];

    $cid = $response->getHeaderLine('X-Correlation-Id');
    if ($cid === '' && is_string($decoded['cid'] ?? null)) {
        $cid = $decoded['cid'];
    }

    return [
        'status' => $response->getStatusCode(),
        'error' => is_string($decoded['error'] ?? null) ? $decoded['error'] : '',
        'cid' => $cid,
    ];
}

/**
 * The audit record carrying $cid, or null when the log has none.
 *
 * Deliberately a local copy rather than a call into `AuditLoggingTest.php`: a
 * helper defined in another test file only exists when Pest collects that file,
 * so depending on one breaks the ordinary "run this single file" loop
 * (ServiceHarness::mediaFixture() carries the same note).
 *
 * @return array<array-key, mixed>|null
 */
function spec029AuditRecordFor(string $cid): ?array
{
    $container = spec029Container();
    if ($container === null || $cid === '') {
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

/**
 * An actions assertion wrapping whatever `data` is handed to it.
 *
 * @return list<array<string, mixed>>
 */
function spec029Assertion(mixed $data): array
{
    return [['label' => 'c2pa.actions.v2', 'data' => $data]];
}

// --- AC1: the legitimate client is unaffected --------------------------------
// Regression guards: these pass before the change as well as after. They are
// here because the constraint has to be invisible to what ManifestBuilder emits,
// and both entry points are exercised — the edited shape puts c2pa.opened first
// and is the one the helpers read most.

it('still signs a manifest built by the library, unchanged', function () {
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('SPEC-029', '1.0')
        ->build();

    $signed = $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest);
    $report = $reader->read(new Asset($signed->bytes, MediaType::Png));

    expect($report->hasManifest())->toBeTrue()
        ->and($report->isSignatureValid())->toBeTrue()
        ->and($report->isAiGenerated())->toBeTrue();
})->group('SPEC-029', 'integration')->skip($skipUnlessReachable);

it('still signs a manipulated manifest with its parent, unchanged', function () {
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiManipulated(MediaType::Png)
        ->withSoftwareAgent('SPEC-029 Inpainting', '1.0')
        ->build();

    $original = ServiceHarness::fixtureBytes();
    $signed = $signer->sign(
        new Asset($original, MediaType::Png),
        $manifest,
        new Asset($original, MediaType::Png),
    );
    $report = $reader->read(new Asset($signed->bytes, MediaType::Png));

    expect($report->hasManifest())->toBeTrue()
        ->and($report->isSignatureValid())->toBeTrue()
        ->and($report->involvesGenerativeAi())->toBeTrue();
})->group('SPEC-029', 'integration')->skip($skipUnlessReachable);

// --- AC2: a non-iterable actions value ---------------------------------------

it('refuses a non-iterable actions value with a named constraint', function () {
    $result = spec029Sign(spec029Assertion(['actions' => 123]));

    expect($result['status'])->toBe(400)
        ->and($result['error'])->toContain('actions');
})->group('SPEC-029', 'integration')->skip($skipUnlessReachable);

it('records the constraint rather than an unhandled engine error', function () {
    $result = spec029Sign(spec029Assertion(['actions' => 123]));

    $record = spec029AuditRecordFor($result['cid']);

    expect($record)->not->toBeNull('no audit record was written for the refusal');
    expect($record['outcome'] ?? null)->toBe('rejected');

    $reason = is_string($record['reason'] ?? null) ? $record['reason'] : '';
    expect(str_starts_with($reason, 'unhandled:'))->toBeFalse(
        'the refusal reached the catch-all handler instead of being named: '.$reason,
    );
})->group('SPEC-029', 'integration')->skip($skipUnlessContainer);

// --- AC3: a non-array actions value, refused before the engine ---------------
// The status code alone is not enough. A service that signs first and refuses
// afterwards would answer 400 too, so the criterion is asserted on the audit
// outcome: `rejected` means the boundary caught it, `failed` means c2pa-rs did.

it('refuses a non-array actions value before the engine is reached', function () {
    $result = spec029Sign(spec029Assertion(['actions' => 'xx']));

    expect($result['status'])->toBe(400);

    $record = spec029AuditRecordFor($result['cid']);

    expect($record)->not->toBeNull('no audit record was written for the refusal');
    expect($record['outcome'] ?? null)->toBe(
        'rejected',
        'outcome "failed" means the payload reached Builder.withJson() and spent a signing attempt',
    );
})->group('SPEC-029', 'integration')->skip($skipUnlessContainer);

// --- AC4: malformed entries inside the actions array -------------------------

it('refuses a malformed action entry', function (mixed $entry, string $label) {
    $result = spec029Sign(spec029Assertion(['actions' => [$entry]]));

    expect($result['status'])->toBe(400, "a {$label} action entry was not refused");
})->with([
    'null' => [null, 'null'],
    'string' => ['c2pa.created', 'string'],
    'number' => [7, 'number'],
    'nested array' => [[['action' => 'c2pa.created']], 'nested array'],
])->group('SPEC-029', 'integration')->skip($skipUnlessReachable);

// --- AC5: a non-object `data` ------------------------------------------------

it('refuses an actions assertion whose data is not an object', function (mixed $data, string $label) {
    $result = spec029Sign(spec029Assertion($data));

    expect($result['status'])->toBe(400, "a {$label} data value was not refused");
})->with([
    'string' => ['text', 'string'],
    'null' => [null, 'null'],
    'number' => [1, 'number'],
])->group('SPEC-029', 'integration')->skip($skipUnlessReachable);

// --- AC6: an actions assertion must carry at least one action ----------------
// Three outcomes, and the third is the one that keeps SPEC-011's settled
// permission intact. Testing only the two refusals would pass against an
// implementation that also refuses the absent case.

it('refuses an actions assertion with no actions key', function () {
    $result = spec029Sign(spec029Assertion([]));

    expect($result['status'])->toBe(400);
})->group('SPEC-029', 'integration')->skip($skipUnlessReachable);

it('refuses an empty actions array without signing anything', function () {
    $result = spec029Sign(spec029Assertion(['actions' => []]));

    expect($result['status'])->toBe(400);

    // The status is not the point. Measured 2026-08-08: this is the only shape
    // that gets a signature today, and the resulting asset is unreadable by
    // c2pa-rs 0.90.4, c2patool 0.27.3 and c2pa-rs 0.89.0 alike. So the criterion
    // is that nothing was signed, which only the audit outcome can show.
    $record = spec029AuditRecordFor($result['cid']);

    expect($record)->not->toBeNull('no audit record was written');
    expect($record['outcome'] ?? null)->toBe(
        'rejected',
        'an empty actions array was signed; the asset it produces cannot be read by any engine',
    );
})->group('SPEC-029', 'integration')->skip($skipUnlessContainer);

it('still signs a request carrying no actions assertion at all', function () {
    $result = spec029Sign([]);

    // SPEC-011 settled "at most one, not required" and SPEC-029 does not reopen
    // it. Absent is a worse claim — it reads back Invalid with
    // assertion.action.malformed, a verifier telling the truth about a claim-v2
    // rule — where empty is a worse artefact.
    expect($result['status'])->toBe(200, 'SPEC-011 permits a request with no actions assertion');
})->group('SPEC-029', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(
        fn () => (ServiceHarness::health()['require_ai_marking'] ?? false) === true
            ? 'service requires an AI marking, which a request with no actions assertion cannot carry'
            : false,
    );

// --- AC7: the refusal leaks nothing ------------------------------------------

it('names the constraint without leaking internals or echoing the payload', function () {
    $result = spec029Sign(spec029Assertion(['actions' => 'xx']));

    expect($result['status'])->toBe(400)
        ->and($result['error'])->not->toBe('');

    foreach (['/app', '/tmp', 'server.js', 'Symbol(', 'not iterable', 'cbor', 'Builder', 'node_modules'] as $leak) {
        expect(str_contains($result['error'], $leak))->toBeFalse(
            "the refusal leaked \"{$leak}\": ".$result['error'],
        );
    }
})->group('SPEC-029', 'integration')->skip($skipUnlessReachable);

// --- AC8: the helpers are total ----------------------------------------------
// Against the module, without HTTP — which requires server.js to export them and
// to guard its listen() behind `require.main === module`. That is the point: the
// criterion is about the helpers themselves, so that the next helper added
// cannot re-introduce the assumption in a call site no route exercises.

it('exposes the actions helpers, and none of them throws on an accepted payload', function () {
    $container = spec029Container();

    $script = <<<'JS'
    const m = require('/app/server.js');
    const names = ['suppliesOpenedAction', 'needsParentAsset', 'markingSourceTypes',
                   'firstActionSourceTypes', 'allSourceTypes'];
    const missing = names.filter((n) => typeof m[n] !== 'function');
    if (missing.length) { console.log(JSON.stringify({ missing, threw: [] })); return; }

    const payloads = [
      undefined, null, 'text', 7, {},
      [], [null], ['text'], [7],
      [{ label: 'c2pa.actions.v2' }],
      [{ label: 'c2pa.actions.v2', data: null }],
      [{ label: 'c2pa.actions.v2', data: 'text' }],
      [{ label: 'c2pa.actions.v2', data: {} }],
      [{ label: 'c2pa.actions.v2', data: { actions: null } }],
      [{ label: 'c2pa.actions.v2', data: { actions: 123 } }],
      [{ label: 'c2pa.actions.v2', data: { actions: 'xx' } }],
      [{ label: 'c2pa.actions.v2', data: { actions: [] } }],
      [{ label: 'c2pa.actions.v2', data: { actions: [null] } }],
      [{ label: 'c2pa.actions.v2', data: { actions: [{ action: 'c2pa.created' }] } }],
    ];

    const threw = [];
    for (const name of names) {
      for (const [i, payload] of payloads.entries()) {
        try { m[name](payload); } catch (e) { threw.push(`${name}#${i}: ${e.message}`); }
      }
    }
    console.log(JSON.stringify({ missing: [], threw }));
    JS;

    // PORT is overridden so that a server.js which still listens at require time
    // binds a free port instead of dying on EADDRINUSE against the live service
    // — otherwise this fails for the wrong reason (NOTES Step 21).
    $raw = shell_exec(sprintf(
        'docker exec -e PORT=3998 %s node -e %s 2>&1',
        escapeshellarg((string) $container),
        escapeshellarg('(function(){'.$script.'})()'),
    ));

    $line = '';
    foreach (explode("\n", is_string($raw) ? $raw : '') as $candidate) {
        if (str_starts_with(trim($candidate), '{')) {
            $line = trim($candidate);
        }
    }

    expect($line)->not->toBe('', 'the probe produced no result: '.(string) $raw);

    $decoded = json_decode($line, true);
    $strings = static fn (mixed $value): array => array_map(
        static fn (mixed $item): string => is_string($item) ? $item : var_export($item, true),
        is_array($value) ? $value : [],
    );

    $missing = $strings(is_array($decoded) ? ($decoded['missing'] ?? null) : null);
    $threw = $strings(is_array($decoded) ? ($decoded['threw'] ?? null) : null);

    expect($missing)->toBe([], 'server.js does not export the actions helpers: '.implode(', ', $missing));
    expect($threw)->toBe([], 'helpers threw: '.implode(' | ', $threw));
})->group('SPEC-029', 'integration')->skip($skipUnlessContainer);

// --- AC9: the policy path still reads the marking ----------------------------
// markingSourceTypes() is one of the four helpers being made total, and it is
// the one a policy decision hangs on. Only meaningful on a hardened service.

it('still accepts a manipulated manifest when the AI-marking policy is on', function () {
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiManipulated(MediaType::Png)
        ->withSoftwareAgent('SPEC-029 Inpainting', '1.0')
        ->build();

    $original = ServiceHarness::fixtureBytes();
    $signed = $signer->sign(
        new Asset($original, MediaType::Png),
        $manifest,
        new Asset($original, MediaType::Png),
    );

    expect($reader->read(new Asset($signed->bytes, MediaType::Png))->involvesGenerativeAi())->toBeTrue();
})->group('SPEC-029', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(
        fn () => (ServiceHarness::health()['require_ai_marking'] ?? false) !== true
            ? 'service does not require an AI marking; covered by the hardened CI profile'
            : false,
    );
