<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\ExtC2paReader;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-036 — raising the declared specification version to 2.4.
 *
 * C2PA 2.4 §18.15.2 requires at least one actions assertion in the claim's
 * `created_assertions` array, where 2.3 permitted "either the created_assertions
 * or gathered_assertions array". That one word is the whole difference, and it
 * matters here because the actions assertion is the one this package exists to
 * produce: in `gathered_assertions` the specification says the generator is
 * declaring it "was not sourced from the claim generator and is not attributed
 * to the signer".
 *
 * **These assert the flag, not the placement, and that is deliberate.**
 * `"created": true` comes back on the assertion in the ordinary manifest report,
 * so it can be seen wherever the service runs. The `created_assertions` array
 * itself is visible only through `c2patool --detailed`, which CI does not
 * install — a test conditioned on that binary would report `skipped` on every
 * machine and never go red. The mapping from flag to placement is recorded as a
 * measurement in the spec, and SPEC-035 AC7 fails on any engine bump, which is
 * the prompt to re-measure it.
 *
 * Note also that c2pa-rs does not validate these placement rules: a manifest
 * that breaks them still reports `validation_state: Valid`. A verdict proves
 * nothing here.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 *
 * @see specs/SPEC-036-raising-the-declaration-to-2-4.md
 */
const SPEC036_DECLARED = '2.4.0';

$spec036SkipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/**
 * Sign through this package and return the active manifest as the engine
 * reports it, via `/v1/read` — the raw store rather than our own decoder,
 * because these criteria are about the structure c2pa-rs produced.
 *
 * @return array<string, mixed>
 */
function spec036Manifest(bool $manipulated = false): array
{
    [$signer] = ServiceHarness::signerAndReader();
    $source = ServiceHarness::fixtureBytes();

    $manifest = ($manipulated
        ? ManifestBuilder::forAiManipulated(MediaType::Png)
        : ManifestBuilder::forAiGenerated(MediaType::Png))
        ->withSoftwareAgent('SPEC-036 probe', '1.0.0')
        ->build();

    $signed = $manipulated
        ? $signer->sign(new Asset($source, MediaType::Png), $manifest, new Asset($source, MediaType::Png))
        : $signer->sign(new Asset($source, MediaType::Png), $manifest);

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

    /** @var array<string, mixed> $found */
    $found = $manifests[$active];

    return $found;
}

/**
 * The one actions assertion of a manifest, or null.
 *
 * @param  array<string, mixed>  $manifest
 * @return array<string, mixed>|null
 */
function spec036ActionsAssertion(array $manifest): ?array
{
    $assertions = $manifest['assertions'] ?? null;

    foreach (is_array($assertions) ? $assertions : [] as $assertion) {
        if (! is_array($assertion)) {
            continue;
        }

        $label = $assertion['label'] ?? null;
        if ($label === 'c2pa.actions.v2') {
            /** @var array<string, mixed> $assertion */
            return $assertion;
        }
    }

    return null;
}

// --- AC1: the actions assertion is attributed to the signer ------------------

it('marks the actions assertion as created on the creation path', function () {
    $assertion = spec036ActionsAssertion(spec036Manifest());

    // The SERVICE sets this, not the client: `created` means "attributed to the
    // signer", and the signer is the service. The client below sends no such
    // flag, which is the point — an older client keeps working.
    //
    // Pinned to true, not merely "set": false would round-trip as an absent key
    // and read as satisfied if this asserted presence alone.
    expect($assertion)->toBeArray()
        ->and($assertion['created'] ?? null)->toBeTrue();
})->group('SPEC-036', 'integration')->skip($spec036SkipUnlessReachable);

// --- AC2: and on the manipulated path ---------------------------------------

it('marks it as created on the manipulated path, keeping the ingredient reference', function () {
    $manifest = spec036Manifest(manipulated: true);
    $assertion = spec036ActionsAssertion($manifest);

    expect($assertion)->toBeArray()
        ->and($assertion['created'] ?? null)->toBeTrue();

    // The 2.4 requirement that c2pa.opened carry a hashed-uri to its ingredient
    // was already met before this spec; changing the flag must not lose it.
    $opened = null;
    $data = $assertion['data'] ?? null;
    $actions = is_array($data) ? ($data['actions'] ?? null) : null;
    foreach (is_array($actions) ? $actions : [] as $action) {
        if (is_array($action) && ($action['action'] ?? null) === 'c2pa.opened') {
            $opened = $action;
        }
    }

    $parameters = is_array($opened) ? ($opened['parameters'] ?? null) : null;
    $ingredients = is_array($parameters) ? ($parameters['ingredients'] ?? null) : null;
    $first = is_array($ingredients) ? ($ingredients[0] ?? null) : null;

    expect($first)->toBeArray();

    $url = is_array($first) ? ($first['url'] ?? null) : null;
    $hash = is_array($first) ? ($first['hash'] ?? null) : null;

    expect($url)->toContain('c2pa.ingredient')
        ->and($hash)->toBeString();
})->group('SPEC-036', 'integration')->skip($spec036SkipUnlessReachable);

// --- AC3: the declaration rises with the manifests ---------------------------

it('declares 2.4.0 and emits manifests shaped for it', function () {
    expect(ServiceHarness::health()['spec_version'] ?? null)->toBe(SPEC036_DECLARED);

    // The two halves together: the declaration, and the shape it claims.
    expect(spec036ActionsAssertion(spec036Manifest())['created'] ?? null)->toBeTrue();
})->group('SPEC-036', 'integration')->skip($spec036SkipUnlessReachable);

// --- AC5: the inherited thumbnail exception is pinned ------------------------

it('still carries the upstream thumbnail, and documents the exception', function () {
    // Asserted PRESENT, not absent. This is a departure we inherit from c2pa-rs
    // (#2106): the generator makes this thumbnail, yet places it in
    // gathered_assertions, which the specification defines as the field for
    // assertions sourced from elsewhere. Keeping it is deliberate — see AC5 —
    // and the alarm for upstream fixing it is SPEC-035 AC7's engine pin.
    expect(spec036Manifest())->toHaveKey('thumbnail');

    // The exception is only honest if it is written down where a reader looks.
    $docs = (string) file_get_contents(dirname(__DIR__, 2).'/docs/readers.md');
    expect($docs)->toContain('gathered_assertions');
})->group('SPEC-036', 'integration')->skip($spec036SkipUnlessReachable);

// --- AC6: the older in-process reader still finds the marking ----------------

it('keeps the Article 50 marking readable by the extension, on an older engine', function () {
    [$signer] = ServiceHarness::signerAndReader();

    $signed = $signer->sign(
        new Asset(ServiceHarness::fixtureBytes(), MediaType::Png),
        ManifestBuilder::forAiGenerated(MediaType::Png)
            ->withSoftwareAgent('SPEC-036 probe', '1.0.0')
            ->build(),
    );

    // ExtC2paReader runs c2pa-rs 0.89.0 — older than the engine that wrote this.
    // A placement change that made it blind to the marking would be a far worse
    // outcome than an unraised declaration.
    $report = (new ExtC2paReader)->read(new Asset($signed->bytes, MediaType::Png));

    expect($report->isAiGenerated())->toBeTrue()
        ->and($report->digitalSourceTypes())
        ->toContain('http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia');
})->group('SPEC-036', 'integration')
    ->skip($spec036SkipUnlessReachable)
    ->skip(fn () => ! extension_loaded('c2pa') ? 'ext-c2pa not loaded' : false);
