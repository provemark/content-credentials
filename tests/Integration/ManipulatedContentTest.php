<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningFailedException;
use Provemark\ContentCredentials\Core\Signing\SignedAsset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-028 — marking manipulated content, through the real engine.
 *
 * The builder half is unit-tested; nothing there proves the manifest c2pa-rs
 * actually produces. What has to hold is that our single `c2pa.edited` action
 * plus a parent asset comes back as `c2pa.opened` + a `parentOf` ingredient +
 * `c2pa.edited`, and validates.
 *
 * Route A — supplying `c2pa.opened` ourselves — was measured as `Invalid`
 * (`assertion.action.ingredientMismatch`), because that action carries a hash
 * over the ingredient assertion the service builds. Hence route B: the service
 * sets the edit intent and adds the ingredient, and c2pa-rs writes the linkage.
 *
 * Run with `vendor/bin/pest --group=integration` against a service started with
 * `RATE_LIMIT_REQUESTS=1000` (NOTES Step 17).
 *
 * @see specs/SPEC-028-manipulated-content-ingredients.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/**
 * A raw POST, for the shapes the PHP client refuses to build (AC3/AC4 stop them
 * before any request, which is the point of those criteria — so the SERVICE's
 * own guards can only be reached from outside it).
 *
 * @param  array<string, mixed>  $body
 * @return array{status: int, error: string, cid: string|null}
 */
function spec028RawSign(array $body): array
{
    $response = (new Client(['http_errors' => false]))->post(ServiceHarness::baseUrl().'/v1/sign', [
        'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
        'json' => $body,
    ]);

    $decoded = json_decode((string) $response->getBody(), true);
    $decoded = is_array($decoded) ? $decoded : [];

    return [
        'status' => $response->getStatusCode(),
        'error' => is_string($decoded['error'] ?? null) ? $decoded['error'] : '',
        'cid' => is_string($decoded['cid'] ?? null) ? $decoded['cid'] : null,
    ];
}

/**
 * The manifest store as the engine reports it — not through our own decoder,
 * because AC2 is about the structure c2pa-rs produced.
 *
 * @return array<string, mixed>
 */
function spec028RawStore(string $bytes): array
{
    $response = (new Client(['http_errors' => false]))->post(ServiceHarness::baseUrl().'/v1/read', [
        'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
        'json' => ['content' => base64_encode($bytes), 'mime_type' => 'image/png'],
    ]);

    /** @var array<string, mixed> $decoded */
    $decoded = is_array($raw = json_decode((string) $response->getBody(), true)) ? $raw : [];

    return $decoded;
}

/**
 * Narrow one step into a decoded JSON document. The store is untrusted input
 * as far as the type system is concerned, and level max says so at every hop.
 *
 * @return array<mixed>
 */
function spec028Arr(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * AC7 asserts that a hardened service ACCEPTS the edited shape, so the happy
 * paths below run in both configurations. Only the AI editing term is used
 * here, which `REQUIRE_AI_MARKING` must recognise — that is the point of AC7.
 *
 * @return array{0: SignedAsset, 1: ManifestReport}
 */
function spec028Signed(): array
{
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiManipulated(MediaType::Png)
        ->withSoftwareAgent('ACME Inpainting', '2.0')
        ->build();

    $original = ServiceHarness::fixtureBytes();

    $signed = $signer->sign(
        new Asset($original, MediaType::Png),
        $manifest,
        new Asset($original, MediaType::Png),
    );

    return [$signed, $reader->read(new Asset($signed->bytes, MediaType::Png))];
}

// --- AC1: a manipulated asset signs and reads back as manipulated -----------

it('signs a manipulated asset and reads it back as manipulated', function () {
    [, $report] = spec028Signed();

    expect($report->hasManifest())->toBeTrue()
        ->and($report->isSignatureValid())->toBeTrue()
        ->and($report->involvesGenerativeAi())->toBeTrue()
        // Edited, not created. isAiGenerated() gates Article 50 decisions in
        // code already written against it, and must not start answering a
        // different question (SPEC-013 is the record of what that costs).
        ->and($report->isAiGenerated())->toBeFalse()
        ->and($report->digitalSourceTypes())
        ->toContain('http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia');
})->skip($skipUnlessReachable)->group('SPEC-028', 'integration');

// --- AC2: the structure C2PA defines, not a lookalike -----------------------

it('produces c2pa.opened first, a parentOf ingredient, and c2pa.edited', function () {
    [$signed] = spec028Signed();

    $store = spec028RawStore($signed->bytes);
    $label = is_string($store['active_manifest'] ?? null) ? $store['active_manifest'] : '';
    $active = spec028Arr(spec028Arr($store['manifests'] ?? null)[$label] ?? null);

    $actionAssertions = array_values(array_filter(
        spec028Arr($active['assertions'] ?? null),
        fn ($a) => is_array($a) && is_string($a['label'] ?? null)
            && str_starts_with($a['label'], 'c2pa.actions'),
    ));

    // Exactly one actions assertion: c2pa-rs inserts c2pa.opened INTO ours
    // rather than adding a second, which is what makes route B compatible with
    // the invariant that the client owns the actions assertion.
    expect($actionAssertions)->toHaveCount(1);

    /** @var list<array<string, mixed>> $actions */
    $actions = spec028Arr(spec028Arr(spec028Arr($actionAssertions[0] ?? null)['data'] ?? null)['actions'] ?? null);
    expect(array_column($actions, 'action'))->toBe(['c2pa.opened', 'c2pa.edited']);

    // The linkage we deliberately do not build ourselves: a JUMBF url plus a
    // hash over the ingredient assertion the service constructs.
    $opened = spec028Arr($actions[0] ?? null);
    $reference = spec028Arr(spec028Arr(spec028Arr($opened['parameters'] ?? null)['ingredients'] ?? null)[0] ?? null);
    expect($reference['url'] ?? null)->toBeString();
    expect($reference['hash'] ?? null)->toBeString();

    expect(spec028Arr($actions[1] ?? null)['digitalSourceType'] ?? null)
        ->toBe('http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia');

    $ingredients = spec028Arr($active['ingredients'] ?? null);
    expect($ingredients)->toHaveCount(1);
    expect(spec028Arr($ingredients[0] ?? null)['relationship'] ?? null)->toBe('parentOf');

    // The failure a hand-built action sequence is most likely to produce.
    /** @var list<array<string, mixed>> $statuses */
    $statuses = spec028Arr($store['validation_status'] ?? null);
    $codes = array_column($statuses, 'code');
    expect($codes)->not->toContain('assertion.action.malformed');
    expect($codes)->not->toContain('assertion.action.ingredientMismatch');
})->skip($skipUnlessReachable)->group('SPEC-028', 'integration');

// --- AC5: the service refuses an unusable parent (error path) ---------------

it('refuses a parent whose content is not valid base64', function () {
    $response = spec028RawSign([
        'content' => base64_encode(ServiceHarness::fixtureBytes()),
        'mime_type' => 'image/png',
        'extra_assertions' => ManifestBuilder::forAiManipulated(MediaType::Png)
            ->withSoftwareAgent('ACME Inpainting', '2.0')
            ->build()
            ->assertions(),
        'parent' => ['content' => 'not base64 !!!', 'mime_type' => 'image/png'],
    ]);

    expect($response['status'])->toBe(400);
    expect($response['error'])->toContain('parent');
    expect($response['cid'])->toBeString();
})->skip($skipUnlessReachable)->group('SPEC-028', 'integration');

it('refuses a parent whose mime_type is not supported', function () {
    $response = spec028RawSign([
        'content' => base64_encode(ServiceHarness::fixtureBytes()),
        'mime_type' => 'image/png',
        'extra_assertions' => ManifestBuilder::forAiManipulated(MediaType::Png)
            ->withSoftwareAgent('ACME Inpainting', '2.0')
            ->build()
            ->assertions(),
        'parent' => ['content' => base64_encode('x'), 'mime_type' => 'image/bmp'],
    ]);

    expect($response['status'])->toBe(400);
    expect($response['error'])->toContain('parent');
})->skip($skipUnlessReachable)->group('SPEC-028', 'integration');

it('refuses an edited manifest that arrives with no parent at all', function () {
    // The PHP client cannot produce this — SPEC-028 AC3 stops it — so it is
    // driven raw. c2pa-rs signs this shape happily and reports Valid, which is
    // exactly why the service has to refuse it itself.
    $response = spec028RawSign([
        'content' => base64_encode(ServiceHarness::fixtureBytes()),
        'mime_type' => 'image/png',
        'extra_assertions' => ManifestBuilder::forAiManipulated(MediaType::Png)
            ->withSoftwareAgent('ACME Inpainting', '2.0')
            ->build()
            ->assertions(),
    ]);

    expect($response['status'])->toBe(400);
    expect($response['error'])->toContain('parent');
})->skip($skipUnlessReachable)->group('SPEC-028', 'integration');

it('refuses a parent supplied for a manifest that creates', function () {
    $response = spec028RawSign([
        'content' => base64_encode(ServiceHarness::fixtureBytes()),
        'mime_type' => 'image/png',
        'extra_assertions' => ManifestBuilder::forAiGenerated(MediaType::Png)
            ->withSoftwareAgent('ACME GenAI', '1.0')
            ->build()
            ->assertions(),
        'parent' => [
            'content' => base64_encode(ServiceHarness::fixtureBytes()),
            'mime_type' => 'image/png',
        ],
    ]);

    expect($response['status'])->toBe(400);
    expect($response['error'])->toContain('parent');
})->skip($skipUnlessReachable)->group('SPEC-028', 'integration');

// --- AC6 (service half): the 413 says the limit covers both assets ----------

it('names both assets when refusing a manipulation request for size', function () {
    $limit = bodyLimitBytes();

    // Each asset is under the limit on its own; together they are not. That is
    // the case the message has to explain — a caller sending 12 MB and 12 MB
    // must not read a refusal implying either one was too large by itself.
    $half = max(1, (int) ($limit * 0.4));

    $response = (new Client(['http_errors' => false, 'timeout' => 60]))
        ->post(ServiceHarness::baseUrl().'/v1/sign', [
            'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
            'json' => [
                'content' => base64_encode(random_bytes($half)),
                'mime_type' => 'image/png',
                'extra_assertions' => ManifestBuilder::forAiManipulated(MediaType::Png)
                    ->withSoftwareAgent('ACME Inpainting', '2.0')
                    ->build()
                    ->assertions(),
                'parent' => [
                    'content' => base64_encode(random_bytes($half)),
                    'mime_type' => 'image/png',
                ],
            ],
        ]);

    $decoded = json_decode((string) $response->getBody(), true);
    $error = is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : '';

    expect($response->getStatusCode())->toBe(413)
        ->and(strtolower($error))->toContain('large')
        // The body parser refuses before any route, so this cannot be
        // conditional on a parent actually being present — it is stated
        // unconditionally, the same constraint SPEC-017 found for the
        // correlation id and SPEC-021 for the video wording.
        ->and($error)->toContain('parent')
        ->and($error)->not->toContain('.js');
})->skip($skipUnlessReachable)
    ->skip(fn () => bodyLimitBytes() === null ? 'service does not report max_body_bytes' : false)
    ->group('SPEC-028', 'integration');

// --- AC8: the audit record shows an ingredient was present ------------------

it('records the parent asset in the audit trail', function () {
    $result = auditedSign([
        'extra_assertions' => ManifestBuilder::forAiManipulated(MediaType::Png)
            ->withSoftwareAgent('ACME Inpainting', '2.0')
            ->build()
            ->assertions(),
        'parent' => [
            'content' => base64_encode(ServiceHarness::fixtureBytes()),
            'mime_type' => 'image/png',
        ],
    ]);

    expect($result['status'])->toBe(200);

    $record = auditRecordFor($result['cid']);
    expect($record)->not->toBeNull();

    // Accountability for "we signed a claim that this was derived from
    // something" requires knowing there was a something, and which one.
    expect($record['parent_bytes'] ?? null)->toBe(strlen(ServiceHarness::fixtureBytes()));
    expect($record['parent_sha256'] ?? null)->toBe(hash('sha256', ServiceHarness::fixtureBytes()));
})->skip($skipUnlessReachable)
    ->skip(fn () => spec012Container() === null ? 'signing service container not found — audit log unreadable' : false)
    ->skip(fn () => (ServiceHarness::health()['require_ai_marking'] ?? false) === true
        ? 'covered in the defaults profile; the hardened one runs the same path'
        : false)
    ->group('SPEC-028', 'integration');

it('leaves the parent fields null when nothing was derived', function () {
    // Absent lineage must read as absent, not as an empty string or a zero that
    // could be mistaken for a zero-byte parent.
    $result = auditedSign();

    expect($result['status'])->toBe(200);

    $record = auditRecordFor($result['cid']);
    expect($record)->not->toBeNull();

    // `?? 'missing'` cannot express this: null coalescing treats a real null the
    // same as an absent key, so it would report a correct record as broken. The
    // field has to be present AND null — present, so a reader can tell the
    // service considered lineage at all; null, so nothing reads as a zero-byte
    // parent.
    $record = spec028Arr($record);
    expect($record)->toHaveKey('parent_bytes');
    expect($record)->toHaveKey('parent_sha256');
    expect($record['parent_bytes'])->toBeNull();
    expect($record['parent_sha256'])->toBeNull();
})->skip($skipUnlessReachable)
    ->skip(fn () => spec012Container() === null ? 'signing service container not found — audit log unreadable' : false)
    ->group('SPEC-028', 'integration');

// --- AC13: the service owns the c2pa.opened action --------------------------

it('refuses an actions array that supplies c2pa.opened itself', function (bool $withParent) {
    // Before this guard the request answered 200 and produced a manifest reading
    // validation_state: Invalid / assertion.action.ingredientMismatch — our
    // certificate on an asset no verifier accepts. c2pa-rs inserts c2pa.opened
    // with a hash over the ingredient assertion it builds, so a second one
    // cannot be linked.
    $body = [
        'content' => base64_encode(ServiceHarness::fixtureBytes()),
        'mime_type' => 'image/png',
        'extra_assertions' => [[
            'label' => 'c2pa.actions.v2',
            'data' => ['actions' => [
                ['action' => 'c2pa.opened'],
                [
                    'action' => 'c2pa.edited',
                    'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia',
                    'softwareAgent' => ['name' => 'raw'],
                ],
            ]],
        ]],
    ];

    if ($withParent) {
        $body['parent'] = [
            'content' => base64_encode(ServiceHarness::fixtureBytes()),
            'mime_type' => 'image/png',
        ];
    }

    $response = spec028RawSign($body);

    expect($response['status'])->toBe(400)
        ->and($response['error'])->toContain('c2pa.opened')
        ->and($response['cid'])->toBeString();
})->with([[true], [false]])
    ->skip($skipUnlessReachable)
    ->group('SPEC-028', 'integration');

// --- AC8, the half that was missed: a REFUSED request is recorded too -------

it('records the parent on a refused request as well as an accepted one', function () {
    // AC8 says "accepted or refused". The first implementation added the fields
    // to the success path only, and both of its tests exercised that path, so
    // nothing caught it — a refusal is exactly when an auditor most wants to
    // know what was submitted.
    $fixture = ServiceHarness::fixtureBytes();

    $result = auditedSign([
        // Refused by AC4/AC5: a parent for a manifest that marks creation.
        'parent' => ['content' => base64_encode($fixture), 'mime_type' => 'image/png'],
    ]);

    expect($result['status'])->toBe(400);

    $record = spec028Arr(auditRecordFor($result['cid']));

    expect($record['outcome'] ?? null)->toBe('rejected');
    expect($record)->toHaveKey('parent_bytes');
    expect($record['parent_bytes'])->toBe(strlen($fixture));
})->skip($skipUnlessReachable)
    ->skip(fn () => spec012Container() === null ? 'signing service container not found — audit log unreadable' : false)
    ->group('SPEC-028', 'integration');

// --- AC7: REQUIRE_AI_MARKING recognises the edited shape --------------------

it('accepts the edited shape under REQUIRE_AI_MARKING', function () {
    // The policy asks whether this manifest marks AI involvement. It does — on
    // the c2pa.edited action, because the first action is c2pa.opened and
    // carries no digitalSourceType at all. Reading only the first action would
    // refuse exactly the content this spec exists to enable.
    [, $report] = spec028Signed();

    expect($report->isSignatureValid())->toBeTrue();
})->skip($skipUnlessReachable)->group('SPEC-028', 'integration');

it('still refuses a non-AI marking under REQUIRE_AI_MARKING', function () {
    // The widening must not become "accept anything with a source type
    // anywhere". algorithmicMedia is synthetic and explicitly not trained.
    [$signer] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAlgorithmic(MediaType::Png)
        ->withSoftwareAgent('ACME Procedural', '1.0')
        ->build();

    expect(fn () => $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest))
        ->toThrow(SigningFailedException::class);
})->skip(fn () => (ServiceHarness::health()['require_ai_marking'] ?? false) !== true
    ? 'service does not run with REQUIRE_AI_MARKING=true'
    : false)->group('SPEC-028', 'integration');
