<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Reading\SignerInfo;
use Provemark\ContentCredentials\Core\Reading\ValidationState;

/**
 * SPEC-026 AC7 — the reading side keeps its meaning, and gains one.
 *
 * `isAiGenerated()` means exactly `trainedAlgorithmicMedia` and stays that way:
 * it gates Article 50 decisions in code already written against it, and silently
 * changing what it answers is the failure SPEC-013 exists to remember.
 *
 * @see specs/SPEC-026-digital-source-types.md
 */
function spec026Report(DigitalSourceType ...$types): ManifestReport
{
    $actions = [];
    foreach ($types as $type) {
        $actions[] = ['action' => 'c2pa.created', 'digitalSourceType' => $type->value];
    }

    return new ManifestReport(
        'urn:c2pa:test',
        new SignerInfo('Test', 'CN', 'Es256'),
        [['label' => 'c2pa.actions.v2', 'data' => ['actions' => $actions]]],
        [],
        ValidationState::Valid,
    );
}

it('keeps isAiGenerated() meaning exactly trainedAlgorithmicMedia', function () {
    expect(spec026Report(DigitalSourceType::TrainedAlgorithmicMedia)->isAiGenerated())->toBeTrue()
        // Widening this would change what already-written code decides.
        ->and(spec026Report(DigitalSourceType::CompositeSynthetic)->isAiGenerated())->toBeFalse()
        ->and(spec026Report(DigitalSourceType::AlgorithmicMedia)->isAiGenerated())->toBeFalse();
})->group('SPEC-026');

it('reports generative-AI involvement separately', function (DigitalSourceType $type, bool $expected) {
    expect(spec026Report($type)->involvesGenerativeAi())->toBe($expected);
})->with([
    'wholly generated' => [DigitalSourceType::TrainedAlgorithmicMedia, true],
    'mix containing generative AI' => [DigitalSourceType::CompositeSynthetic, true],
    // The whole point of algorithmicMedia: synthetic, but no model and no
    // training data. Reporting it as generative would erase the distinction the
    // term exists to make.
    'algorithmic, not generative' => [DigitalSourceType::AlgorithmicMedia, false],
])->group('SPEC-026');

it('reports no generative involvement for an unmarked manifest', function () {
    expect(spec026Report()->involvesGenerativeAi())->toBeFalse()
        ->and((new ManifestReport(null, null, [], [], null))->involvesGenerativeAi())->toBeFalse();
})->group('SPEC-026');

it('sees generative involvement when it is one of several source types', function () {
    $report = spec026Report(
        DigitalSourceType::AlgorithmicMedia,
        DigitalSourceType::CompositeSynthetic,
    );

    expect($report->involvesGenerativeAi())->toBeTrue()
        ->and($report->isAiGenerated())->toBeFalse();
})->group('SPEC-026');
