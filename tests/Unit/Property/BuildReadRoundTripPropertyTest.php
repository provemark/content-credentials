<?php

declare(strict_types=1);

use Eris\TestTrait;
use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Tests\Unit\Property\Gen;

uses(TestTrait::class);

/**
 * SPEC-001 × SPEC-003 — the round-trip invariant.
 *
 * The example tests pin chosen cases ('X', 'ACME GenAI Image Model'). This
 * quantifies over the whole input space instead: whatever the builder marks as
 * AI-generated, the reader must recognise as AI-generated — for EVERY media
 * type, EVERY non-blank agent name and EVERY version.
 *
 * This is the property that fails loudest if a future change (yours or an
 * assistant's) touches the marking on one side of the library only.
 */
it('reads back as AI-generated whatever the builder marked — for all inputs', function () {
    $this->forAll(
        Gen::mediaType(),
        Gen::softwareAgentName(),
        Gen::optionalVersion(),
    )->then(function (MediaType $type, string $name, ?string $version) {
        $manifest = ManifestBuilder::forAiGeneratedImage($type)
            ->withSoftwareAgent($name, $version)
            ->build();

        // The reader's view of exactly what the builder produced.
        $report = new ManifestReport('urn:test:manifest', null, $manifest->assertions(), []);

        expect($report->isAiGenerated())->toBeTrue()
            ->and($report->digitalSourceTypes())
            ->toBe([DigitalSourceType::TrainedAlgorithmicMedia->value]);
    });
})->group('SPEC-001', 'SPEC-003', 'pbt');

/**
 * SPEC-001 AC6 — the marking is fixed, never caller-influenced.
 *
 * No agent name, however adversarial (JSON, markup, newlines), may leak into
 * or alter the digitalSourceType. The name belongs in softwareAgent and
 * nowhere else.
 */
it('never lets the software-agent name influence the marking', function () {
    $this->forAll(
        Gen::mediaType(),
        Gen::softwareAgentName(),
    )->then(function (MediaType $type, string $name) {
        $assertions = ManifestBuilder::forAiGeneratedImage($type)
            ->withSoftwareAgent($name)
            ->build()
            ->assertions();

        $action = $assertions[0]['data']['actions'][0];

        expect($action['digitalSourceType'])
            ->toBe(DigitalSourceType::TrainedAlgorithmicMedia->value)
            ->and($action['action'])->toBe('c2pa.created')
            ->and($action['softwareAgent']['name'])->toBe($name);
        // verbatim, not mangled
    });
})->group('SPEC-001', 'pbt');

/**
 * SPEC-001 AC5 — immutability under an arbitrary chain of with* calls.
 *
 * The example test checks one chain. This checks that no ORDER of builder
 * calls can mutate an earlier instance: a builder captured up front must still
 * produce its original output after any further chaining off it.
 */
it('is immutable under any order of with* calls', function () {
    $this->forAll(
        Gen::mediaType(),
        Gen::softwareAgentName(),
        Gen::softwareAgentName(),
    )->then(function (MediaType $type, string $first, string $second) {
        $base = ManifestBuilder::forAiGeneratedImage($type)->withSoftwareAgent($first);
        $snapshot = $base->build()->toArray();

        // Chain further calls off $base; they must not touch $base itself.
        $base->withSoftwareAgent($second)->withClaimGenerator('Other', '9.9.9')->build();

        expect($base->build()->toArray())->toBe($snapshot);
    });
})->group('SPEC-001', 'pbt');

/**
 * D4 — the Core boundary holds for every input: assertions() must always be
 * exactly the assertions inside toArray().
 */
it('keeps assertions() in step with toArray() for all inputs', function () {
    $this->forAll(
        Gen::mediaType(),
        Gen::softwareAgentName(),
        Gen::optionalVersion(),
    )->then(function (MediaType $type, string $name, ?string $version) {
        $manifest = ManifestBuilder::forAiGeneratedImage($type)
            ->withSoftwareAgent($name, $version)
            ->withClaimGenerator('Content Credentials', '0.1.0')
            ->build();

        expect($manifest->assertions())->toBe($manifest->toArray()['assertions'])
            ->and($manifest->toArray()['format'])->toBe($type->value)
            ->and($manifest->mediaType())->toBe($type);
    });
})->group('SPEC-001', 'pbt');
