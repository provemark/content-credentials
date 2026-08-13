<?php

declare(strict_types=1);

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\Exception\AssetTooLargeException;
use Provemark\ContentCredentials\Core\Signing\Exception\MissingParentAssetException;
use Provemark\ContentCredentials\Core\Signing\Exception\UnexpectedParentAssetException;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Signing\SigningServiceSigner;
use Provemark\ContentCredentials\Laravel\ContentCredentialsManager;

/**
 * SPEC-028 AC3/AC4 — the parent asset is mandatory-by-manifest.
 *
 * Tests-first: RED until implemented. Driven by a mock PSR-18 client, because
 * the point of both criteria is that NOTHING is sent — the guard runs before
 * the request is built, so a caller pays no encoding for a request that cannot
 * succeed (SPEC-025's argument, applied to a second asset).
 *
 * Measured and recorded in the spec: c2pa-rs enforces neither of these. An edit
 * intent with no ingredient signs happily, and a c2pa.created action alongside a
 * parentOf ingredient signs happily too. These guards are the only ones there
 * are.
 *
 * @see specs/SPEC-028-manipulated-content-ingredients.md
 */
function spec028Signer(MockClient $client): SigningServiceSigner
{
    $factory = new Psr17Factory;

    return new SigningServiceSigner(
        $client,
        $factory,
        $factory,
        new SigningServiceConfig('https://sign.test', 'secret'),
    );
}

function spec028Png(): Asset
{
    return new Asset('not really a png', MediaType::Png);
}

// --- AC3: a manifest that needs a parent, signed without one ----------------

it('refuses to sign a manipulated manifest with no parent asset', function () {
    $client = new MockClient;
    $manifest = ManifestBuilder::forAiManipulated(MediaType::Png)
        ->withSoftwareAgent('ACME Inpainting', '2.0')
        ->build();

    expect(fn () => spec028Signer($client)->sign(spec028Png(), $manifest))
        ->toThrow(MissingParentAssetException::class);

    // The guard is worthless if it fires after the body has been built: the
    // asset would already have been base64-encoded, which is the cost SPEC-025
    // exists to avoid paying twice.
    expect($client->getRequests())->toBe([]);
})->group('SPEC-028');

it('names the source type in the missing-parent message', function () {
    $client = new MockClient;
    $manifest = ManifestBuilder::forAiManipulated(MediaType::Png)
        ->withSoftwareAgent('ACME Inpainting', '2.0')
        ->build();

    try {
        spec028Signer($client)->sign(spec028Png(), $manifest);

        // Not $this->fail(): Pest 4 binds $this so that static analysis resolves
        // it to TestCall, where that method does not exist. A thrown
        // RuntimeException fails the test just as loudly and says the same
        // thing, and it is the shape ReaderSelectionTest already uses for
        // "the call that should have thrown did not".
        throw new RuntimeException('expected MissingParentAssetException');
    } catch (MissingParentAssetException $e) {
        // A caller who reaches this has asked for something C2PA records as an
        // operation on an existing asset. The message has to say which claim
        // needs the original, or they cannot tell what to supply.
        expect($e->getMessage())->toContain('compositeWithTrainedAlgorithmicMedia');
    }
})->group('SPEC-028');

// --- AC4: a parent supplied where the manifest does not take one ------------

it('refuses a parent asset for a manifest that creates rather than edits', function () {
    $client = new MockClient;
    $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
        ->build();

    // Accepting and discarding it would sign a manifest that omits exactly what
    // the caller believed they were asserting — and c2pa-rs would report Valid.
    expect(fn () => spec028Signer($client)->sign(spec028Png(), $manifest, spec028Png()))
        ->toThrow(UnexpectedParentAssetException::class);

    expect($client->getRequests())->toBe([]);
})->group('SPEC-028');

// --- AC1 (client half): the parent rides in its own field -------------------

it('sends the parent asset as its own base64 field', function () {
    $client = new MockClient;
    $client->addResponse(new Response(200, [], (string) json_encode([
        'signed_content' => base64_encode('signed bytes'),
    ])));

    $manifest = ManifestBuilder::forAiManipulated(MediaType::Png)
        ->withSoftwareAgent('ACME Inpainting', '2.0')
        ->build();

    spec028Signer($client)->sign(
        new Asset('edited bytes', MediaType::Png),
        $manifest,
        new Asset('original bytes', MediaType::Png),
    );

    $sent = json_decode((string) $client->getRequests()[0]->getBody(), true);
    expect($sent)->toBeArray();

    /** @var array<string, mixed> $sent */
    $parent = $sent['parent'] ?? null;
    expect($parent)->toBeArray();

    /** @var array<string, mixed> $parent */
    expect($parent['content'])->toBe(base64_encode('original bytes'));
    expect($parent['mime_type'])->toBe('image/png');
    expect($sent['content'])->toBe(base64_encode('edited bytes'));
})->group('SPEC-028');

it('omits the parent field entirely when there is no parent', function () {
    $client = new MockClient;
    $client->addResponse(new Response(200, [], (string) json_encode([
        'signed_content' => base64_encode('signed bytes'),
    ])));

    $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
        ->build();

    spec028Signer($client)->sign(new Asset('bytes', MediaType::Png), $manifest);

    $sent = json_decode((string) $client->getRequests()[0]->getBody(), true);

    // Absent, not null: every request that exists today must stay byte-identical
    // on the wire, or this is a behaviour change for callers who never asked
    // for one.
    expect($sent)->not->toHaveKey('parent');
})->group('SPEC-028');

// --- AC6 (client half): one size budget for the pair, per OQ5 ---------------

it('counts both assets against one request budget', function () {
    $client = new MockClient;
    $config = new SigningServiceConfig('https://sign.test', 'secret', maxRequestBytes: 100);
    $factory = new Psr17Factory;
    $signer = new SigningServiceSigner($client, $factory, $factory, $config);

    $manifest = ManifestBuilder::forAiManipulated(MediaType::Png)
        ->withSoftwareAgent('ACME Inpainting', '2.0')
        ->build();

    // 60 + 60 = 120 over a budget of 100, while each asset passes on its own.
    // A per-asset limit would let this through and leave the service to 413 it,
    // which is the outcome the client-side guard exists to prevent.
    expect(fn () => $signer->sign(
        new Asset(str_repeat('e', 60), MediaType::Png),
        $manifest,
        new Asset(str_repeat('o', 60), MediaType::Png),
    ))->toThrow(AssetTooLargeException::class);

    expect($client->getRequests())->toBe([]);
})->group('SPEC-028');

// --- the documented call actually exists ------------------------------------

it('forwards the parent through the Laravel manager', function () {
    // docs/marking.md shows `ContentCredentials::sign($asset, $manifest, parent:
    // ...)`, and SPEC-028's API sketch shows the facade. A documented example
    // that does not compile is worse than no example, and nothing else here
    // would have caught it — the Core signer's tests bypass the manager.
    $method = new ReflectionMethod(
        ContentCredentialsManager::class,
        'sign',
    );

    expect($method->getNumberOfParameters())->toBe(3);
    expect($method->getParameters()[2]->getName())->toBe('parent');
    expect($method->getParameters()[2]->isOptional())->toBeTrue();
})->group('SPEC-028');

// Both exceptions implement ContentCredentialsException, and there is no test
// for it here: PHPStan proved the assertion always true once the classes
// existed, because the `implements` clause is enforced by the type system. That
// is the seventh vacuous test this repository has caught and the second one a
// tool caught rather than a person (NOTES Step 23). Removed rather than
// silenced — a test that cannot fail is not evidence.
