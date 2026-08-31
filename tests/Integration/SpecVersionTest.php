<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-035 — declaring a C2PA specification version.
 *
 * The declared value is `2.3.0`, established by the audit recorded in the spec:
 * we satisfy 2.3 and fail 2.4 on one requirement that c2pa-rs itself fails, so
 * `2.3.0` is the highest value that is true. SemVer rather than `2.3`, because
 * 2.3 §10.2.2 says the field "may be present, and if so, shall contain a SemVer
 * formatted specVersion field".
 *
 * AC1, AC2 and AC3 read the store through `/v1/read` rather than through our own
 * `ManifestReport`, because they are about `claim_generator_info` — which the
 * reader does not surface, and which is the structure the engine produced.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 *
 * @see specs/SPEC-035-declaring-a-spec-version.md
 */
const SPEC035_DECLARED = '2.3.0';

$spec035SkipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/**
 * Sign the fixture and return the active manifest as the engine reports it.
 *
 * @return array<string, mixed>
 */
function spec035SignedManifest(?string $claimGenerator = null): array
{
    [$signer] = ServiceHarness::signerAndReader();

    $builder = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('SPEC-035 probe', '1.0.0');

    if ($claimGenerator !== null) {
        $builder = $builder->withClaimGenerator($claimGenerator);
    }

    $signed = $signer->sign(
        new Asset(ServiceHarness::fixtureBytes(), MediaType::Png),
        $builder->build(),
    );

    $response = (new Client(['http_errors' => false]))->post(ServiceHarness::baseUrl().'/v1/read', [
        'headers' => ['Authorization' => 'Bearer '.ServiceHarness::apiKey()],
        'json' => ['content' => base64_encode($signed->bytes), 'mime_type' => 'image/png'],
    ]);

    $decoded = json_decode((string) $response->getBody(), true);
    $store = is_array($decoded) ? $decoded : [];

    $active = $store['active_manifest'] ?? null;
    $manifests = $store['manifests'] ?? null;

    if (! is_string($active) || ! is_array($manifests) || ! is_array($manifests[$active] ?? null)) {
        return [];
    }

    /** @var array<string, mixed> $manifest */
    $manifest = $manifests[$active];

    return $manifest;
}

/**
 * The one actions assertion, as `[label, actions]`, or `[null, []]`.
 *
 * The store is untrusted input as far as the type system is concerned, and
 * level max says so at every hop — hence the narrowing rather than a chain of
 * `??`.
 *
 * @param  array<string, mixed>  $manifest
 * @return array{0: list<string>, 1: list<mixed>}
 */
function spec035Actions(array $manifest): array
{
    $assertions = $manifest['assertions'] ?? null;
    $labels = [];
    $actions = [];

    foreach (is_array($assertions) ? $assertions : [] as $assertion) {
        if (! is_array($assertion)) {
            continue;
        }

        $label = $assertion['label'] ?? null;
        if (! is_string($label) || ! str_starts_with($label, 'c2pa.actions')) {
            continue;
        }

        $labels[] = $label;
        $data = $assertion['data'] ?? null;
        $found = is_array($data) ? ($data['actions'] ?? null) : null;
        $actions = is_array($found) ? array_values($found) : [];
    }

    return [$labels, $actions];
}

/**
 * The `specVersion` values the manifest declares, in order.
 *
 * @param  array<string, mixed>  $manifest
 * @return list<mixed>
 */
function spec035Declared(array $manifest): array
{
    $info = $manifest['claim_generator_info'] ?? [];

    return array_values(array_filter(array_map(
        fn (mixed $entry) => is_array($entry) ? ($entry['specVersion'] ?? null) : null,
        is_array($info) ? $info : [],
    ), fn (mixed $v) => $v !== null));
}

// --- AC1: a signed manifest declares a specification version -----------------

it('declares the specification version in claim_generator_info', function () {
    // Pinned exact rather than "is present": a merely non-empty value would pass
    // on a typo, and this string is a signed claim about which rules were
    // followed.
    expect(spec035Declared(spec035SignedManifest()))->toBe([SPEC035_DECLARED]);
})->group('SPEC-035', 'integration')->skip($spec035SkipUnlessReachable);

// --- AC2: the declaration is guarded against becoming untrue -----------------

it('satisfies every requirement of the version it declares', function () {
    // Scope, stated here so that a passing guard is not read as full
    // conformance: these are the requirements the SPEC-035 audit found that
    // touch what this package emits, enumerated from the 2.3 and 2.4 version
    // histories — not the full specification text, which is far larger.
    $manifest = spec035SignedManifest();
    [$actionLabels, $actions] = spec035Actions($manifest);

    // Row 1 — the claim is version 2.
    expect($manifest['claim_version'] ?? null)->toBe(2);

    // Rows 2 and 3 — exactly one actions assertion, labelled exactly.
    expect($actionLabels)->toBe(['c2pa.actions.v2']);

    // Row 4 — the first action is created or opened.
    $first = $actions[0] ?? null;
    expect(is_array($first) ? ($first['action'] ?? null) : null)
        ->toBeIn(['c2pa.created', 'c2pa.opened']);

    // Row 5 — digitalSourceType, where present, is one this package may emit.
    foreach ($actions as $action) {
        $sourceType = is_array($action) ? ($action['digitalSourceType'] ?? null) : null;
        if ($sourceType !== null) {
            expect($sourceType)->toStartWith('http://cv.iptc.org/newscodes/digitalsourcetype/');
        }
    }

    $declared = spec035Declared($manifest)[0] ?? null;

    // Row 6 — our own declaration is SemVer-formatted.
    expect($declared)->toBeString()->toMatch('/^\d+\.\d+\.\d+$/');

    // Row 7 — the declared value equals the version this list was written for.
    // This row is the mechanism, not a formality: raising the constant without
    // extending the list fails here rather than silently declaring more than has
    // been checked.
    expect($declared)->toBe(SPEC035_DECLARED);
})->group('SPEC-035', 'integration')->skip($spec035SkipUnlessReachable);

// --- AC3: the caller cannot set or override the declared version -------------

it('ignores a specVersion a caller tries to smuggle through the generator name', function () {
    // `creator_name` is the only caller-controlled value that reaches
    // claim_generator_info at all, so it is the only way in worth exercising
    // against a running service; the unit suite's source guard covers the
    // absence of any other path.
    $manifest = spec035SignedManifest('specVersion 9.9.9');

    expect(spec035Declared($manifest))->toBe([SPEC035_DECLARED]);
})->group('SPEC-035', 'integration')->skip($spec035SkipUnlessReachable);

// --- AC5: a deployment can be checked without signing ------------------------

it('reports the declared specification version on /health', function () {
    $health = ServiceHarness::health();

    expect($health)->toHaveKey('spec_version')
        ->and($health['spec_version'])->toBe(SPEC035_DECLARED);
})->group('SPEC-035', 'integration')->skip($spec035SkipUnlessReachable);

// --- AC6: reading reports what a foreign manifest declares -------------------

it('reports the declared version through the reader, and absence without failing', function () {
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $signed = $signer->sign(
        new Asset(ServiceHarness::fixtureBytes(), MediaType::Png),
        ManifestBuilder::forAiGenerated(MediaType::Png)
            ->withSoftwareAgent('SPEC-035 probe', '1.0.0')
            ->build(),
    );

    expect($reader->read(new Asset($signed->bytes, MediaType::Png))->declaredSpecVersion())
        ->toBe(SPEC035_DECLARED);

    // Absence is absence, not failure — the SPEC-003 contract. The unsigned
    // fixture carries no manifest at all.
    expect($reader->read(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png))->declaredSpecVersion())
        ->toBeNull();
})->group('SPEC-035', 'integration')->skip($spec035SkipUnlessReachable);
