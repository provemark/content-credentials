<?php

declare(strict_types=1);

use ContentCredentials\Core\Manifest\Exception\InvalidSoftwareAgentException;
use ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;
use ContentCredentials\Core\Manifest\ManifestBuilder;
use ContentCredentials\Core\Manifest\MediaType;
use ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * SPEC-001 — Core manifest builder (AI-generated image).
 *
 * @see specs/SPEC-001-manifest-builder.md
 */
const AI_TRAINED_URI = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

/**
 * The single AI-generated actions assertion the builder must emit.
 *
 * @param  array{name: string, version?: string}  $softwareAgent
 * @return array{label: string, data: array{actions: list<array{action: string, digitalSourceType: string, softwareAgent: array{name: string, version?: string}}>}}
 */
function spec001AiAssertion(array $softwareAgent): array
{
    return [
        'label' => 'c2pa.actions.v2',
        'data' => [
            'actions' => [[
                'action' => 'c2pa.created',
                'digitalSourceType' => AI_TRAINED_URI,
                'softwareAgent' => $softwareAgent,
            ]],
        ],
    ];
}

// --- AC1: builds the AI-generated marking for PNG --------------------------
// Whole-array equality proves format, the single assertion, the single
// c2pa.created action, the exact URI and softwareAgent, and the absence of any
// other key — all at once.

it('builds the AI-generated marking for PNG', function () {
    $arr = ManifestBuilder::forAiGeneratedImage(MediaType::Png)
        ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
        ->build()
        ->toArray();

    expect($arr)->toBe([
        'format' => 'image/png',
        'assertions' => [spec001AiAssertion(['name' => 'ACME GenAI Image Model', 'version' => '3.1.0'])],
    ]);
})->group('SPEC-001');

// --- AC2: supports JPEG and omits an unset optional version ----------------

it('supports JPEG and omits an unset software-agent version', function () {
    $arr = ManifestBuilder::forAiGeneratedImage(MediaType::Jpeg)
        ->withSoftwareAgent('X')
        ->build()
        ->toArray();

    // softwareAgent === ['name' => 'X'] with no 'version' key (exact match).
    expect($arr)->toBe([
        'format' => 'image/jpeg',
        'assertions' => [spec001AiAssertion(['name' => 'X'])],
    ]);
})->group('SPEC-001');

// --- AC3: rejects an unsupported media type (error path) -------------------

it('rejects an unsupported media type', function (string $mime) {
    expect(fn () => MediaType::fromMimeType($mime))
        ->toThrow(UnsupportedMediaTypeException::class);
})->with(['image/gif', 'image/webp', 'application/pdf'])->group('SPEC-001');

it('unsupported-type exception implements the Core exception interface', function () {
    // Note: Pest's toThrow() treats an interface name as a message (class_exists
    // is false for interfaces), so assert the instance type explicitly.
    try {
        MediaType::fromMimeType('image/gif');
        throw new RuntimeException('expected UnsupportedMediaTypeException was not thrown');
    } catch (UnsupportedMediaTypeException $e) {
        expect($e)->toBeInstanceOf(ContentCredentialsException::class);
    }
})->group('SPEC-001');

it('names the offending type in the unsupported-type error', function () {
    try {
        MediaType::fromMimeType('image/gif');
        throw new RuntimeException('expected UnsupportedMediaTypeException was not thrown');
    } catch (UnsupportedMediaTypeException $e) {
        expect($e->getMessage())->toContain('image/gif');
    }
})->group('SPEC-001');

// D2: fromMimeType trims, lowercases and strips parameters before matching.
it('normalises mime case and strips parameters', function (string $mime) {
    expect(MediaType::fromMimeType($mime))->toBe(MediaType::Png);
})->with([
    'image/png',
    'IMAGE/PNG',
    'image/png; charset=binary',
    '  image/png  ',
])->group('SPEC-001');

// --- AC4: rejects an empty software-agent name, at build() (error path) ----

it('rejects an empty or whitespace software-agent name at build()', function (string $name) {
    // withSoftwareAgent must NOT throw; the error surfaces at build() (per AC4).
    $builder = ManifestBuilder::forAiGeneratedImage(MediaType::Png)->withSoftwareAgent($name);

    expect(fn () => $builder->build())->toThrow(InvalidSoftwareAgentException::class);
})->with(['', '   ', "\t\n"])->group('SPEC-001');

// D3: a software agent is mandatory — build() without one is an error.
it('requires a software agent before build()', function () {
    $builder = ManifestBuilder::forAiGeneratedImage(MediaType::Png);

    expect(fn () => $builder->build())->toThrow(InvalidSoftwareAgentException::class);
})->group('SPEC-001');

// --- AC5: the builder is immutable / fluent --------------------------------

it('is immutable: with* returns a new, independent instance', function () {
    $b1 = ManifestBuilder::forAiGeneratedImage(MediaType::Png);
    $b2 = $b1->withSoftwareAgent('X');

    // Distinct instances.
    expect($b2)->not->toBe($b1);

    // b1's state did not change: it still has no agent and fails to build,
    // while b2 builds successfully with the agent set on it.
    expect(fn () => $b1->build())->toThrow(InvalidSoftwareAgentException::class);

    expect($b2->build()->toArray())->toBe([
        'format' => 'image/png',
        'assertions' => [spec001AiAssertion(['name' => 'X'])],
    ]);
})->group('SPEC-001');

// --- AC6: the AI URI is fixed and not caller-overridable -------------------

it('always emits the fixed trainedAlgorithmicMedia URI', function () {
    $arr = ManifestBuilder::forAiGeneratedImage(MediaType::Jpeg)
        ->withSoftwareAgent('X')
        ->build()
        ->toArray();

    $assertion = $arr['assertions'][0];
    expect($assertion['data'])->toBe([
        'actions' => [[
            'action' => 'c2pa.created',
            'digitalSourceType' => AI_TRAINED_URI,
            'softwareAgent' => ['name' => 'X'],
        ]],
    ]);
})->group('SPEC-001');

// --- D1: Core owns claim_generator_info (optional) -------------------------

it('includes claim_generator_info when set', function () {
    $arr = ManifestBuilder::forAiGeneratedImage(MediaType::Png)
        ->withSoftwareAgent('X')
        ->withClaimGenerator('Content Credentials', '0.1.0')
        ->build()
        ->toArray();

    expect($arr)->toBe([
        'claim_generator_info' => [['name' => 'Content Credentials', 'version' => '0.1.0']],
        'format' => 'image/png',
        'assertions' => [spec001AiAssertion(['name' => 'X'])],
    ]);
})->group('SPEC-001');

it('omits claim_generator_info when not set', function () {
    $arr = ManifestBuilder::forAiGeneratedImage(MediaType::Png)
        ->withSoftwareAgent('X')
        ->build()
        ->toArray();

    expect($arr)->not->toHaveKey('claim_generator_info');
})->group('SPEC-001');

// --- D4: Core boundary — assertions() mirrors toArray()['assertions'] ------

it('exposes assertions() matching the toArray assertions', function () {
    $manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Png)
        ->withSoftwareAgent('X')
        ->build();

    expect($manifest->assertions())->toBe($manifest->toArray()['assertions']);
})->group('SPEC-001');
