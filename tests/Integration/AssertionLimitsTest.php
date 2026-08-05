<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-011 — server-side assertion limits on `/v1/sign`.
 *
 * The service passed `extra_assertions` into the builder with no validation
 * beyond "is it an array", so a caller with a valid token could have any
 * assertion structure signed by the certificate — demonstrated 2026-08-05 by
 * signing an AI image as a Canon EOS R5 capture, which c2patool then reported as
 * `Trusted`.
 *
 * These drive the HTTP contract directly rather than the PHP client, because
 * the client cannot produce the shapes under test: `ManifestBuilder` emits
 * exactly one well-formed actions assertion. AC1 is the exception — it exists to
 * prove the limits are invisible to the legitimate path.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 *
 * @see specs/SPEC-011-assertion-limits.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/**
 * POST a raw body to /v1/sign, bypassing the PHP client.
 *
 * @param  array<string, mixed>  $body
 * @return array{status: int, error: string}
 */
function signRaw(array $body): array
{
    $response = (new Client(['http_errors' => false]))->post(ServiceHarness::baseUrl().'/v1/sign', [
        'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
        'json' => $body + [
            'content' => base64_encode(ServiceHarness::fixtureBytes()),
            'mime_type' => 'image/png',
        ],
    ]);

    $decoded = json_decode((string) $response->getBody(), true);
    $error = is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : '';

    return ['status' => $response->getStatusCode(), 'error' => $error];
}

/**
 * One well-formed actions assertion, as ManifestBuilder emits it.
 *
 * @return array<string, mixed>
 */
function validActionsAssertion(?string $sourceType = null): array
{
    return [
        'label' => 'c2pa.actions.v2',
        'data' => ['actions' => [[
            'action' => 'c2pa.created',
            'softwareAgent' => ['name' => 'SPEC-011'],
            'digitalSourceType' => $sourceType ?? DigitalSourceType::TrainedAlgorithmicMedia->value,
        ]]],
    ];
}

/**
 * A structure nested $depth levels deep.
 *
 * @return array<string, mixed>
 */
function nestedTo(int $depth): array
{
    $value = ['leaf' => true];
    for ($i = 0; $i < $depth; $i++) {
        $value = ['nested' => $value];
    }

    return $value;
}

// --- AC1: the legitimate client is unaffected -------------------------------

it('still signs a manifest built by the library, unchanged', function () {
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Png)
        ->withSoftwareAgent('SPEC-011 regression')
        ->build();

    $signed = $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest);
    $report = $reader->read(new Asset($signed->bytes, MediaType::Png));

    $actionsAssertions = array_filter(
        $report->assertions(),
        fn (array $assertion): bool => str_starts_with($assertion['label'], 'c2pa.actions'),
    );

    expect($report->isSignatureValid())->toBeTrue()
        ->and($report->isAiGenerated())->toBeTrue()
        ->and($actionsAssertions)->toHaveCount(1);
})->group('SPEC-011', 'integration')->skip($skipUnlessReachable);

// --- AC2: at most one actions assertion -------------------------------------

it('refuses more than one actions assertion', function () {
    $result = signRaw(['extra_assertions' => [
        validActionsAssertion(),
        ['label' => 'c2pa.actions', 'data' => ['actions' => [['action' => 'c2pa.opened']]]],
    ]]);

    expect($result['status'])->toBe(400)
        ->and(strtolower($result['error']))->toContain('actions');
})->group('SPEC-011', 'integration')->skip($skipUnlessReachable);

// --- AC3: bounded count ------------------------------------------------------

it('refuses more assertions than the limit allows', function () {
    $many = [validActionsAssertion()];
    for ($i = 0; $i < 32; $i++) {
        $many[] = ['label' => "org.example.filler.{$i}", 'data' => ['i' => $i]];
    }

    expect(signRaw(['extra_assertions' => $many])['status'])->toBe(400);
})->group('SPEC-011', 'integration')->skip($skipUnlessReachable);

// --- AC4: bounded size and depth --------------------------------------------

it('refuses an oversized assertion', function () {
    $result = signRaw(['extra_assertions' => [
        validActionsAssertion(),
        ['label' => 'org.example.big', 'data' => ['blob' => str_repeat('A', 128 * 1024)]],
    ]]);

    expect($result['status'])->toBe(400);
})->group('SPEC-011', 'integration')->skip($skipUnlessReachable);

it('refuses an assertion nested past the depth limit', function () {
    $result = signRaw(['extra_assertions' => [
        validActionsAssertion(),
        ['label' => 'org.example.deep', 'data' => nestedTo(64)],
    ]]);

    // Must be a refusal, not a crash: walking a hostile structure unboundedly
    // is itself the failure mode this criterion guards against.
    expect($result['status'])->toBe(400);
})->group('SPEC-011', 'integration')->skip($skipUnlessReachable);

// --- AC5: malformed entries --------------------------------------------------

it('refuses a malformed assertion entry', function (mixed $entry) {
    expect(signRaw(['extra_assertions' => [validActionsAssertion(), $entry]])['status'])->toBe(400);
})->with([
    'a string' => ['not an object'],
    'a number' => [42],
    'null' => [null],
    'a list' => [[['nested' => 'list']]],
    'no label' => [['data' => ['x' => 1]]],
    'empty label' => [['label' => '', 'data' => ['x' => 1]]],
    'non-string label' => [['label' => 7, 'data' => ['x' => 1]]],
])->group('SPEC-011', 'integration')->skip($skipUnlessReachable);

// --- AC6: bounded creator_name ----------------------------------------------

it('refuses a creator_name that is not a bounded string', function (mixed $creatorName) {
    expect(signRaw([
        'creator_name' => $creatorName,
        'extra_assertions' => [validActionsAssertion()],
    ])['status'])->toBe(400);
})->with([
    'far too long' => [str_repeat('x', 4096)],
    'not a string' => [['name' => 'array']],
])->group('SPEC-011', 'integration')->skip($skipUnlessReachable);

it('still accepts a request with no creator_name at all', function () {
    expect(signRaw(['extra_assertions' => [validActionsAssertion()]])['status'])->toBe(200);
})->group('SPEC-011', 'integration')->skip($skipUnlessReachable);

// --- AC7: a rejection leaks nothing ------------------------------------------

it('names the constraint without leaking internals or echoing the payload', function () {
    $secret = 'CANARY-'.bin2hex(random_bytes(8));
    $result = signRaw(['extra_assertions' => [
        validActionsAssertion(),
        ['label' => 'org.example.big', 'data' => ['blob' => str_repeat($secret, 8192)]],
    ]]);

    expect($result['status'])->toBe(400)
        ->and($result['error'])->not->toBe('')
        ->and($result['error'])->not->toContain($secret)
        ->and($result['error'])->not->toContain('/app')
        ->and($result['error'])->not->toContain('/tmp')
        ->and($result['error'])->not->toContain('node_modules')
        ->and($result['error'])->not->toContain('.js');
})->group('SPEC-011', 'integration')->skip($skipUnlessReachable);

// --- AC8: the AI-marking policy is opt-in and off by default -----------------

it('reports the AI-marking policy on /health', function () {
    $health = ServiceHarness::health();

    expect($health)->toHaveKey('require_ai_marking')
        ->and($health['require_ai_marking'])->toBeBool();
})->group('SPEC-011', 'integration')->skip($skipUnlessReachable);

it('takes no position on digitalSourceType by default', function () {
    // The service signs a camera-capture claim when the policy is off. That is
    // deliberate: requiring trainedAlgorithmicMedia would not make the
    // attestation truer, only narrow the direction of a possible lie, while
    // excluding the authenticity use case entirely (see the spec's Problem).
    $capture = 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture';

    expect(signRaw(['extra_assertions' => [validActionsAssertion($capture)]])['status'])->toBe(200);
})->group('SPEC-011', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(fn () => (ServiceHarness::health()['require_ai_marking'] ?? false) === true
        ? 'service runs with REQUIRE_AI_MARKING=true — unset it to cover the default'
        : false);

it('refuses a non-AI marking when the policy requires one', function () {
    $capture = 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture';

    expect(signRaw(['extra_assertions' => [validActionsAssertion($capture)]])['status'])->toBe(400)
        ->and(signRaw(['extra_assertions' => [validActionsAssertion()]])['status'])->toBe(200);
})->group('SPEC-011', 'integration')
    ->skip($skipUnlessReachable)
    ->skip(fn () => (ServiceHarness::health()['require_ai_marking'] ?? false) !== true
        ? 'service runs without REQUIRE_AI_MARKING — set it to true to cover the policy'
        : false);
