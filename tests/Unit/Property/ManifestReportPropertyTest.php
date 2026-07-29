<?php

declare(strict_types=1);

use Eris\Generators;
use Eris\TestTrait;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Tests\Unit\Property\Gen;

uses(TestTrait::class);

/**
 * SPEC-003 — reader robustness.
 *
 * digitalSourceTypes() walks untrusted, service-supplied structures and is full
 * of defensive guards (is_array, str_starts_with, null coalescing). Defensive
 * code is exactly where property tests earn their keep: the guards are only
 * correct if they hold for input nobody thought to write an example for.
 */
const PBT_TRAINED = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

/** The genuine AI marking, as the builder emits it. */
function pbtRealMarking(): array
{
    return ManifestBuilder::forAiGeneratedImage(MediaType::Png)
        ->withSoftwareAgent('generator')
        ->build()
        ->assertions()[0];
}

/**
 * The marking must remain visible no matter how much unrelated or malformed
 * material surrounds it, and no matter where in the list it sits.
 */
it('finds the AI marking amid arbitrary junk, wherever it sits', function () {
    $this->forAll(
        Generators::seq(Gen::junkAssertion()),
        Generators::choose(0, 12),
    )->then(function (array $noise, int $position) {
        $assertions = $noise;
        array_splice($assertions, min($position, count($assertions)), 0, [pbtRealMarking()]);

        $report = new ManifestReport('urn:test:manifest', null, array_values($assertions), []);

        expect($report->isAiGenerated())->toBeTrue()
            ->and($report->digitalSourceTypes())->toContain(PBT_TRAINED);
    });
})->group('SPEC-003', 'pbt');

/**
 * Total function: on ANY assertion list — including malformed shapes the
 * service should never send but might — the reader answers instead of
 *  throwing and reports no duplicate source types.
 */
it('never throws and never duplicates, on any assertion list', function () {
    $this->forAll(
        Generators::seq(Gen::junkAssertion()),
    )->then(function (array $assertions) {
        $report = new ManifestReport('urn:test:manifest', null, array_values($assertions), []);

        $types = $report->digitalSourceTypes();   // must not throw

        expect($types)->toBe(array_values(array_unique($types)))
            ->and($report->isAiGenerated())->toBe(in_array(PBT_TRAINED, $types, true));
    });
})->group('SPEC-003', 'pbt');

/**
 * Absence of a marking must never be reported as presence. Junk alone — which
 * by construction carries no digitalSourceType — can never make the library
 * claim content is AI-generated. This is the safety-critical direction: a
 * false positive here is a false Article 50 claim.
 */
it('never claims AI-generated without a real marking', function () {
    $this->forAll(
        Generators::seq(Gen::junkAssertion()),
    )->then(function (array $assertions) {
        $report = new ManifestReport('urn:test:manifest', null, array_values($assertions), []);

        expect($report->isAiGenerated())->toBeFalse()
            ->and($report->digitalSourceTypes())->not->toContain(PBT_TRAINED);
    });
})->group('SPEC-003', 'pbt');

/**
 * D3 — trust is decided solely by the untrusted status code, whatever other
 * codes the service reports alongside it.
 */
it('treats trust as exactly the absence of the untrusted code', function () {
    $codes = Generators::seq(Generators::elements([
        'signingCredential.untrusted',
        'claimSignature.validated',
        'assertion.hashedURI.match',
        'timeStamp.validated',
        'some.unknown.code',
    ]));

    $this->forAll($codes)->then(function (array $statusCodes) {
        $report = new ManifestReport('urn:test:manifest', null, [], array_values($statusCodes));

        expect($report->isTrusted())
            ->toBe(! in_array('signingCredential.untrusted', $statusCodes, true));
    });
})->group('SPEC-003', 'pbt');
