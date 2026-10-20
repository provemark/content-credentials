<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-007 Amendment 1 — the service checks the timestamp it promised.
 *
 * c2pa-node 0.9.8 and later sign through CallbackSigner + signAsync without
 * contacting the TSA, and answer 200 (contentauth/c2pa-js#244, NOTES Step 74).
 * The service now reads back every signature made with a TSA configured and
 * refuses one without a validated timestamp (AC6).
 *
 * The engines that drop the timestamp are not the ones CI runs, so the check
 * itself is a pure function exported from server.js and tested here on
 * manifest-store JSON (AC7): a trimmed fixture from a real `/v1/read`, the
 * refusal cases derived from it, and the engine's own output for two committed
 * signed assets, one timestamped and one not. Requiring server.js must not
 * start a server; PORT is overridden anyway, as in SPEC-029's helper test.
 *
 * AC6's wiring in the sign handler is verified by hand on a 0.9.10 build,
 * where every signature with a TSA configured must be refused (Traceability).
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 */
function spec007am1Container(): ?string
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

/**
 * Run a script against the service module inside the container, with `input`
 * on stdin, and return the last line of JSON it printed.
 *
 * @return array<array-key, mixed>
 */
function spec007am1Node(string $script, mixed $input): array
{
    $container = spec007am1Container();

    if ($container === null) {
        throw new RuntimeException('signing-service container not running');
    }

    $process = proc_open(
        // Wrapped, so the script may `return` early as in SPEC-029's helper test.
        sprintf('docker exec -i -e PORT=3995 %s node -e %s', escapeshellarg($container), escapeshellarg('(async function(){'.$script.'})()')),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('cannot start docker exec');
    }

    fwrite($pipes[0], (string) json_encode($input));
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $line = '';
    foreach (explode("\n", $stdout) as $candidate) {
        if (str_starts_with(trim($candidate), '{')) {
            $line = trim($candidate);
        }
    }

    $decoded = json_decode($line, true);

    if (! is_array($decoded)) {
        throw new RuntimeException("no result from the service module: {$stdout} {$stderr}");
    }

    return $decoded;
}

/**
 * The fields the check reads, from the trimmed fixture: the active manifest's
 * label, its signature time, and its success codes.
 *
 * @return array{label: string, time: string, success: list<array{code: string}>}
 */
function spec007am1Fields(): array
{
    $store = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/spec007-store-timestamped.json'), true);
    $label = is_array($store) && is_string($store['active_manifest'] ?? null) ? $store['active_manifest'] : '';
    $manifests = is_array($store) && is_array($store['manifests'] ?? null) ? $store['manifests'] : [];
    $manifest = is_array($manifests[$label] ?? null) ? $manifests[$label] : [];
    $info = is_array($manifest['signature_info'] ?? null) ? $manifest['signature_info'] : [];
    $results = is_array($store) && is_array($store['validation_results'] ?? null) ? $store['validation_results'] : [];
    $active = is_array($results['activeManifest'] ?? null) ? $results['activeManifest'] : [];
    $success = [];
    foreach (is_array($active['success'] ?? null) ? $active['success'] : [] as $entry) {
        if (is_array($entry) && is_string($entry['code'] ?? null)) {
            $success[] = ['code' => $entry['code']];
        }
    }

    if ($label === '' || ! is_string($info['time'] ?? null) || $success === []) {
        throw new RuntimeException('the timestamped store fixture lacks a field the check reads');
    }

    return ['label' => $label, 'time' => $info['time'], 'success' => $success];
}

/**
 * A store in the `/v1/read` shape, built from those fields; each argument
 * replaces one of them so a case states exactly what it changes.
 *
 * @param  list<array{code: string}>|null  $success  null leaves out validation_results
 * @param  list<array{code: string}>  $failure
 * @return array<string, mixed>
 */
function spec007am1Build(?string $active, string $label, mixed $time, ?array $success, array $failure = []): array
{
    $info = $time === null ? [] : ['time' => $time];
    $store = ['manifests' => [$label => ['signature_info' => $info]]];

    if ($active !== null) {
        $store['active_manifest'] = $active;
    }
    if ($success !== null) {
        $store['validation_results'] = ['activeManifest' => ['success' => $success, 'failure' => $failure]];
    }

    return $store;
}

$skipUnlessContainer = fn () => spec007am1Container() === null || ! ServiceHarness::reachable()
    ? 'signing-service container not running — start it with docker-compose up -d'
    : false;

// --- AC7: the check is a pure function --------------------------------------

it('accepts a store with a time and timeStamp.validated, and refuses every store without them', function () {
    ['label' => $label, 'time' => $time, 'success' => $success] = spec007am1Fields();
    $withoutTimestamp = array_values(array_filter($success, fn (array $e) => $e['code'] !== 'timeStamp.validated'));

    $cases = [
        'timestamped' => spec007am1Build($label, $label, $time, $success),
        'no time' => spec007am1Build($label, $label, null, $success),
        'malformed time' => spec007am1Build($label, $label, 'not a date', $success),
        'time not a string' => spec007am1Build($label, $label, 1760000000, $success),
        'no timeStamp.validated' => spec007am1Build($label, $label, $time, $withoutTimestamp),
        'timeStamp.validated under failure' => spec007am1Build($label, $label, $time, $withoutTimestamp, [['code' => 'timeStamp.validated']]),
        'no validation results' => spec007am1Build($label, $label, $time, null),
        'no active manifest' => spec007am1Build(null, $label, $time, $success),
        'active manifest missing' => spec007am1Build('urn:c2pa:nowhere', $label, $time, $success),
        'empty store' => [],
        'null' => null,
        'not an object' => 'text',
    ];

    $result = spec007am1Node(<<<'JS'
    const m = require('/app/server.js');
    if (typeof m.carriesValidatedTimestamp !== 'function') { console.log(JSON.stringify({ missing: true })); return; }
    const cases = JSON.parse(require('fs').readFileSync(0, 'utf8'));
    const out = {}, threw = [];
    for (const [name, store] of Object.entries(cases)) {
      try { out[name] = m.carriesValidatedTimestamp(store); } catch (e) { threw.push(`${name}: ${e.message}`); }
    }
    console.log(JSON.stringify({ missing: false, out, threw }));
    JS, $cases);

    expect($result['missing'] ?? null)->toBeFalse('server.js does not export carriesValidatedTimestamp')
        ->and($result['threw'] ?? null)->toBe([])
        ->and($result['out'] ?? null)->toBe([
            'timestamped' => true,
            'no time' => false,
            'malformed time' => false,
            'time not a string' => false,
            'no timeStamp.validated' => false,
            'timeStamp.validated under failure' => false,
            'no validation results' => false,
            'no active manifest' => false,
            'active manifest missing' => false,
            'empty store' => false,
            'null' => false,
            'not an object' => false,
        ]);
})->group('SPEC-007', 'integration')->skip($skipUnlessContainer);

it('reads the engine output of a timestamped asset as timestamped, and of an untimestamped one as not', function () {
    // signed-spec042.png carries a validated DigiCert timestamp; the c2patool
    // 0.27 fixture was signed without one. Both measured with c2patool.
    $fixtures = [
        'timestamped' => base64_encode((string) file_get_contents(dirname(__DIR__).'/Fixtures/signed-spec042.png')),
        'untimestamped' => base64_encode((string) file_get_contents(dirname(__DIR__).'/Fixtures/spec045-c2patool027-trusted.png')),
    ];

    $result = spec007am1Node(<<<'JS'
    const m = require('/app/server.js');
    if (typeof m.carriesValidatedTimestamp !== 'function') { console.log(JSON.stringify({ missing: true })); return; }
    const { Reader } = require('/app/node_modules/@contentauth/c2pa-node');
    const assets = JSON.parse(require('fs').readFileSync(0, 'utf8'));
    (async () => {
      const out = {};
      for (const [name, b64] of Object.entries(assets)) {
        const reader = await Reader.fromAsset({ buffer: Buffer.from(b64, 'base64'), mimeType: 'image/png' },
          { verify: { remote_manifest_fetch: false } });
        out[name] = m.carriesValidatedTimestamp(reader ? reader.json() : null);
      }
      console.log(JSON.stringify({ missing: false, out }));
      process.exit(0);
    })();
    JS, $fixtures);

    expect($result['missing'] ?? null)->toBeFalse('server.js does not export carriesValidatedTimestamp')
        ->and($result['out'] ?? null)->toBe(['timestamped' => true, 'untimestamped' => false]);
})->group('SPEC-007', 'integration')->skip($skipUnlessContainer);

// --- AC8: the audit record states the outcome -------------------------------
// On c2pa-node 0.9.10 before this amendment the record said `true` for an asset
// without a timestamp. The two must agree for every signed record.

it('records timestamped exactly when the returned asset carries a timestamp', function () {
    $response = (new Client(['http_errors' => false]))->post(ServiceHarness::baseUrl().'/v1/sign', [
        'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
        'json' => [
            'content' => base64_encode(ServiceHarness::fixtureBytes()),
            'mime_type' => 'image/png',
            'extra_assertions' => [[
                'label' => 'c2pa.actions.v2',
                'data' => ['actions' => [[
                    'action' => 'c2pa.created',
                    'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
                    'softwareAgent' => ['name' => 'SPEC-007 AC8'],
                ]]],
            ]],
        ],
    ]);

    $decoded = json_decode((string) $response->getBody(), true);
    $body = is_array($decoded) ? $decoded : [];
    $cid = $response->getHeaderLine('X-Correlation-Id');

    expect($response->getStatusCode())->toBe(200, 'signing failed: '.json_encode($body))
        ->and($body['signed_content'] ?? null)->toBeString();

    [, $reader] = ServiceHarness::signerAndReader();
    $signed = is_string($body['signed_content'] ?? null) ? base64_decode($body['signed_content'], true) : false;
    $report = $reader->read(new Asset($signed === false ? '' : $signed, MediaType::Png));

    $record = null;
    $container = (string) spec007am1Container();
    for ($attempt = 0; $attempt < 10 && $record === null; $attempt++) {
        $raw = shell_exec(sprintf('docker logs --tail 400 %s 2>&1', escapeshellarg($container)));
        foreach (explode("\n", is_string($raw) ? $raw : '') as $line) {
            $entry = json_decode(trim($line), true);
            if (is_array($entry) && ($entry['cid'] ?? null) === $cid) {
                $record = $entry;
            }
        }
        $record === null && usleep(200_000);
    }

    expect($record)->toBeArray()
        ->and($record['outcome'] ?? null)->toBe('signed')
        ->and($record['timestamped'] ?? null)->toBe($report->hasTimestamp());
})->group('SPEC-007', 'integration')
    ->skip($skipUnlessContainer)
    ->skip(fn () => getenv('CC_TSA_EXPECT_UNREACHABLE') === '1' ? 'every signature fails in this profile' : false);

// --- Decision A1: /health reports the check ---------------------------------

it('reports on /health whether it checks the timestamp after signing', function () {
    $health = ServiceHarness::health();

    expect($health)->toHaveKey('timestamp_check')
        ->and($health['timestamp_check'])->toBe($health['timestamping'] ?? null);
})->group('SPEC-007', 'integration')->skip(fn () => ServiceHarness::reachable() ? false : 'signing service not reachable');
