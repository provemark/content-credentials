<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;

/**
 * SPEC-026 — the digitalSourceType family.
 *
 * The enum is the vocabulary; the builder is the policy. Three synthetic terms
 * ride on `c2pa.created`; three editing terms describe an operation on an asset
 * that already existed.
 *
 * AC4 is AMENDED BY SPEC-028: the editing terms were refused here because this
 * package could not build the `c2pa.opened` + ingredient + `c2pa.edited`
 * structure they need. It can now, so they build — carrying `c2pa.edited`, and
 * requiring the original asset at signing time.
 *
 * @see specs/SPEC-026-digital-source-types.md
 * @see specs/SPEC-028-manipulated-content-ingredients.md
 */

/** The exact IPTC URIs, transcribed from cv.iptc.org on 2026-08-07. */
const IPTC_PREFIX = 'http://cv.iptc.org/newscodes/digitalsourcetype/';

// --- AC2: the URIs are IPTC's, verbatim -------------------------------------

it('carries the exact IPTC URI for every case', function () {
    $expected = [
        'TrainedAlgorithmicMedia' => IPTC_PREFIX.'trainedAlgorithmicMedia',
        'CompositeSynthetic' => IPTC_PREFIX.'compositeSynthetic',
        'AlgorithmicMedia' => IPTC_PREFIX.'algorithmicMedia',
        'CompositeWithTrainedAlgorithmicMedia' => IPTC_PREFIX.'compositeWithTrainedAlgorithmicMedia',
        'AlgorithmicallyEnhanced' => IPTC_PREFIX.'algorithmicallyEnhanced',
        'HumanEdits' => IPTC_PREFIX.'humanEdits',
    ];

    $actual = [];
    foreach (DigitalSourceType::cases() as $case) {
        $actual[$case->name] = $case->value;
    }

    expect($actual)->toBe($expected);
})->group('SPEC-026');

it('spells the composite term without the "d" the guidance adds', function () {
    // C2PA Implementation Guidance 2.4 writes "compositedWithTrainedAlgorithmicMedia".
    // IPTC has no such concept. Implementing from the prose would emit a URI
    // resolving to nothing, inside the assertion whose purpose is machine
    // readability. This is the test that keeps that typo out.
    $value = DigitalSourceType::CompositeWithTrainedAlgorithmicMedia->value;

    expect($value)->toBe(IPTC_PREFIX.'compositeWithTrainedAlgorithmicMedia');
    expect(str_contains($value, 'composited'))->toBeFalse();
})->group('SPEC-026');

it('declares no term IPTC has retired', function () {
    // minorHumanEdits and digitalArt were retired 2024-09-17, softwareImage in
    // 2022. Older examples on the web still use them.
    foreach (DigitalSourceType::cases() as $case) {
        expect($case->value)->not->toContain('minorHumanEdits')
            ->and($case->value)->not->toContain('digitalArt')
            ->and($case->value)->not->toContain('softwareImage');
    }
})->group('SPEC-026');

// --- AC1: every emittable type produces a well-formed manifest --------------

it('emits one c2pa.created action carrying the requested source type', function (DigitalSourceType $type) {
    $arr = ManifestBuilder::forSourceType($type, MediaType::Png)
        ->withSoftwareAgent('ACME GenAI', '1.0')
        ->build()
        ->toArray();

    // Whole-array equality: still exactly one assertion, one c2pa.created
    // action, and nothing else. This spec widens what can be said about
    // creation, not how many things are said.
    expect($arr)->toBe([
        'format' => 'image/png',
        'assertions' => [[
            'label' => 'c2pa.actions.v2',
            'data' => ['actions' => [[
                'action' => 'c2pa.created',
                'digitalSourceType' => $type->value,
                'softwareAgent' => ['name' => 'ACME GenAI', 'version' => '1.0'],
            ]]],
        ]],
    ]);
})->with([
    [DigitalSourceType::TrainedAlgorithmicMedia],
    [DigitalSourceType::CompositeSynthetic],
    [DigitalSourceType::AlgorithmicMedia],
])->group('SPEC-026');

it('offers a named constructor for each emittable type', function () {
    $sourceTypeOf = function (ManifestBuilder $builder): mixed {
        $actions = $builder->withSoftwareAgent('X')->build()->assertions()[0]['data']['actions'];

        return is_array($actions) && isset($actions[0]) && is_array($actions[0])
            ? ($actions[0]['digitalSourceType'] ?? null)
            : null;
    };

    expect($sourceTypeOf(ManifestBuilder::forSynthetic(MediaType::Png)))
        ->toBe(DigitalSourceType::CompositeSynthetic->value)
        ->and($sourceTypeOf(ManifestBuilder::forAlgorithmic(MediaType::Png)))
        ->toBe(DigitalSourceType::AlgorithmicMedia->value);
})->group('SPEC-026');

// --- AC3: the AI-generated path is unchanged --------------------------------

it('leaves the AI-generated manifest byte-identical to what SPEC-001 fixes', function () {
    // The regression that would matter most: this spec widens the vocabulary and
    // must not disturb the one assertion that already carries the Article 50
    // marking correctly.
    $arr = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
        ->build()
        ->toArray();

    expect($arr)->toBe([
        'format' => 'image/png',
        'assertions' => [[
            'label' => 'c2pa.actions.v2',
            'data' => ['actions' => [[
                'action' => 'c2pa.created',
                'digitalSourceType' => IPTC_PREFIX.'trainedAlgorithmicMedia',
                'softwareAgent' => ['name' => 'ACME GenAI Image Model', 'version' => '3.1.0'],
            ]]],
        ]],
    ]);
})->group('SPEC-026');

// --- AC4: an editing source type never claims creation ----------------------
//
// AMENDED BY SPEC-028. These three tests asserted that the builder REFUSED the
// editing terms, because this package could not build the ingredient structure
// they need. SPEC-028 builds it, so the refusal is gone and
// UnsupportedSourceTypeException is never raised.
//
// They are rewritten rather than deleted, because what the criterion actually
// guarded survives intact: an editing term must never ride on `c2pa.created` —
// a well-formed manifest claiming the asset was CREATED by an operation which by
// definition acts on one that already existed. That is now prevented by emitting
// `c2pa.edited` instead of by throwing, and the refusal moved to the Signing
// layer, where it can say what is missing (MissingParentAssetException).
//
// Deleting them would have lost the criterion; leaving them would have pinned
// behaviour SPEC-028 removes. Same move as NOTES Step 13, when SPEC-013 amended
// SPEC-003 D3.

it('classifies a source type that describes an operation on an existing asset', function (DigitalSourceType $type) {
    expect($type->requiresIngredient())->toBeTrue();

    $manifest = ManifestBuilder::forSourceType($type, MediaType::Png)
        ->withSoftwareAgent('X')
        ->build();

    expect($manifest->requiresParentAsset())->toBeTrue();
})->with([
    [DigitalSourceType::CompositeWithTrainedAlgorithmicMedia],
    [DigitalSourceType::AlgorithmicallyEnhanced],
    [DigitalSourceType::HumanEdits],
])->group('SPEC-026');

it('never emits a created action for an editing term', function (DigitalSourceType $type) {
    $assertions = ManifestBuilder::forSourceType($type, MediaType::Png)
        ->withSoftwareAgent('X')
        ->build()
        ->assertions();

    $actions = $assertions[0]['data']['actions'];
    expect($actions)->toBeArray();

    /** @var list<array<string, mixed>> $actions */
    expect(array_column($actions, 'action'))->toBe(['c2pa.edited']);
})->with([
    [DigitalSourceType::CompositeWithTrainedAlgorithmicMedia],
    [DigitalSourceType::AlgorithmicallyEnhanced],
    [DigitalSourceType::HumanEdits],
])->group('SPEC-026');
