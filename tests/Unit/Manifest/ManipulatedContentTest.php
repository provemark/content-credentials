<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;
use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedSourceTypeException;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;

/**
 * SPEC-028 — marking manipulated content (the builder half).
 *
 * Tests-first: these reference behaviour that does not exist yet and are RED
 * until implemented. SPEC-026 refused every source type whose
 * `requiresIngredient()` is true; SPEC-028 lifts that refusal for all three and
 * changes what the builder emits for them — `c2pa.edited`, not `c2pa.created`,
 * because c2pa-rs adds the `c2pa.opened` action and its ingredient linkage
 * itself (SPEC-028, OQ1 measured).
 *
 * @see specs/SPEC-028-manipulated-content-ingredients.md
 */
function spec028Agent(ManifestBuilder $builder): ManifestBuilder
{
    return $builder->withSoftwareAgent('ACME Inpainting', '2.0');
}

/**
 * The single actions assertion's action list, for a built manifest.
 *
 * @return list<array<string, mixed>>
 */
function spec028Actions(ManifestBuilder $builder): array
{
    $assertions = spec028Agent($builder)->build()->assertions();

    expect($assertions)->toHaveCount(1);
    expect($assertions[0]['label'])->toBe('c2pa.actions.v2');

    $actions = $assertions[0]['data']['actions'];
    expect($actions)->toBeArray();

    /** @var list<array<string, mixed>> $actions */
    return $actions;
}

// --- AC12: every declared source type builds --------------------------------

it('builds a manifest for every declared source type, refusing none', function () {
    foreach (DigitalSourceType::cases() as $case) {
        $manifest = spec028Agent(ManifestBuilder::forSourceType($case, MediaType::Png))->build();

        expect($manifest->assertions())->toHaveCount(1);
    }
})->group('SPEC-028');

it('keeps UnsupportedSourceTypeException as public API that nothing throws', function () {
    // Kept rather than deleted: it is public API since 0.8.0 and someone may be
    // catching it. Same treatment as forAiGeneratedImage() in SPEC-022 — kept
    // indefinitely, no runtime deprecation.
    expect(class_exists(UnsupportedSourceTypeException::class))->toBeTrue();
})->group('SPEC-028');

// --- AC2 (builder half): the edited shape, not the created one --------------

it('emits c2pa.edited for a source type that acts on an existing asset', function () {
    $actions = spec028Actions(ManifestBuilder::forAiManipulated(MediaType::Png));

    expect($actions)->toHaveCount(1);
    expect($actions[0]['action'])->toBe('c2pa.edited');
    expect($actions[0]['digitalSourceType'])
        ->toBe('http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia');

    $agent = $actions[0]['softwareAgent'];
    expect($agent)->toBeArray();

    /** @var array<string, mixed> $agent */
    expect($agent['name'])->toBe('ACME Inpainting');
})->group('SPEC-028');

it('does not emit a c2pa.opened action of its own', function () {
    // Route A — supplying c2pa.opened ourselves — produced an INVALID manifest
    // (assertion.action.ingredientMismatch), because the action carries a hash
    // over the ingredient assertion the service builds. c2pa-rs adds it.
    $actions = spec028Actions(ManifestBuilder::forAiManipulated(MediaType::Png));

    expect(array_column($actions, 'action'))->not->toContain('c2pa.opened');
})->group('SPEC-028');

it('still emits c2pa.created for the source types that create', function () {
    foreach ([
        ManifestBuilder::forAiGenerated(MediaType::Png),
        ManifestBuilder::forSynthetic(MediaType::Png),
        ManifestBuilder::forAlgorithmic(MediaType::Png),
    ] as $builder) {
        $actions = spec028Actions($builder);

        expect($actions)->toHaveCount(1);
        expect($actions[0]['action'])->toBe('c2pa.created');
    }
})->group('SPEC-028');

// --- AC3/AC4 support: the manifest states whether it needs a parent ---------

it('reports which manifests need a parent asset', function () {
    $needs = spec028Agent(ManifestBuilder::forAiManipulated(MediaType::Png))->build();
    $does_not = spec028Agent(ManifestBuilder::forAiGenerated(MediaType::Png))->build();

    expect($needs->requiresParentAsset())->toBeTrue();
    expect($does_not->requiresParentAsset())->toBeFalse();
})->group('SPEC-028');
