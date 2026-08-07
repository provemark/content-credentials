<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;
use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedSourceTypeException;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * SPEC-026 — the digitalSourceType family.
 *
 * The enum is the vocabulary; the builder is the policy. Two synthetic terms can
 * be emitted; three editing terms are declared so the refusal can name them, and
 * refused because they need `c2pa.opened` + an ingredient + `c2pa.edited`, which
 * this package cannot build.
 *
 * @see specs/SPEC-026-digital-source-types.md
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

// --- AC4: a source type that needs an ingredient is refused (error path) ----

it('refuses a source type that describes an operation on an existing asset', function (DigitalSourceType $type) {
    expect(fn () => ManifestBuilder::forSourceType($type, MediaType::Png))
        ->toThrow(UnsupportedSourceTypeException::class);
})->with([
    [DigitalSourceType::CompositeWithTrainedAlgorithmicMedia],
    [DigitalSourceType::AlgorithmicallyEnhanced],
    [DigitalSourceType::HumanEdits],
])->group('SPEC-026');

it('names ingredients as the missing capability when refusing', function () {
    try {
        ManifestBuilder::forSourceType(DigitalSourceType::CompositeWithTrainedAlgorithmicMedia, MediaType::Png);
        throw new RuntimeException('expected UnsupportedSourceTypeException was not thrown');
    } catch (UnsupportedSourceTypeException $e) {
        // The refusal has to teach: an absent constant says "no such thing", and
        // this says what is actually missing and why it cannot be faked.
        expect($e->getMessage())->toContain('ingredient')
            ->and($e->getMessage())->toContain('c2pa.opened')
            ->and($e)->toBeInstanceOf(ContentCredentialsException::class);
    }
})->group('SPEC-026');

it('refuses rather than emitting a created action for an editing term', function () {
    // The failure this criterion prevents: a well-formed manifest making a false
    // claim — that the asset was CREATED by an operation which by definition
    // acts on one that already existed.
    $emitted = null;

    try {
        $emitted = ManifestBuilder::forSourceType(DigitalSourceType::HumanEdits, MediaType::Png)
            ->withSoftwareAgent('X')
            ->build()
            ->toArray();
    } catch (UnsupportedSourceTypeException) {
        // expected
    }

    expect($emitted)->toBeNull();
})->group('SPEC-026');
