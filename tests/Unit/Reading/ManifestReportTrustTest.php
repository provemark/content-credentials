<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Reading\ValidationState;

/**
 * SPEC-013 — `isTrusted()` fails closed.
 *
 * Tests-first: RED until `isTrusted()` is redefined positively and
 * `isVerifiedAiGenerated()` exists. Drives the value object directly rather
 * than through a mocked service, because the criteria are about how a report
 * *reasons* over what it was given, not about how it was fetched.
 *
 * The defect being fixed: trust was "true unless a `signingCredential.untrusted`
 * code was present", so absence of evidence read as evidence of trust — an
 * asset carrying no C2PA data at all answered `true`.
 *
 * @see specs/SPEC-013-istrusted-fails-closed.md
 */

/**
 * A report with the AI marking, parameterised on the two things trust depends
 * on: the c2pa-rs verdict and the reported status codes.
 *
 * @param  array<array-key, mixed>  $codes  normalised to the list<string> the report expects
 */
function trustReport(?ValidationState $state, array $codes = []): ManifestReport
{
    return new ManifestReport(
        'urn:c2pa:test',
        null,
        [[
            'label' => 'c2pa.actions.v2',
            'data' => ['actions' => [[
                'action' => 'c2pa.created',
                'digitalSourceType' => DigitalSourceType::TrainedAlgorithmicMedia->value,
            ]]],
        ]],
        array_values(array_filter($codes, is_string(...))),
        $state,
    );
}

// --- AC1: absence of a manifest is not trust --------------------------------

it('does not trust an asset that carries no C2PA data at all', function () {
    $empty = new ManifestReport(null, null, [], [], null);

    expect($empty->isTrusted())->toBeFalse()
        ->and($empty->hasManifest())->toBeFalse()
        ->and($empty->isSignatureValid())->toBeFalse()
        ->and($empty->isAiGenerated())->toBeFalse();
})->group('SPEC-013');

// --- AC2: trust must be positively established ------------------------------

it('trusts a report whose validation state is Trusted', function () {
    expect(trustReport(ValidationState::Trusted)->isTrusted())->toBeTrue();
})->group('SPEC-013');

// --- AC3: valid is not trusted ----------------------------------------------

it('does not trust a valid signature whose certificate is not on a trust list', function (array $codes) {
    $report = trustReport(ValidationState::Valid, $codes);

    // Integrity and trust stay independent.
    expect($report->isTrusted())->toBeFalse()
        ->and($report->isSignatureValid())->toBeTrue();
})->with([
    'untrusted code reported' => [['signingCredential.untrusted']],
    'no codes reported at all' => [[]],
])->group('SPEC-013');

// --- AC4: an unknown verdict is not a trusted one ---------------------------

it('does not trust a report whose validation state is absent or unrecognised', function () {
    // SigningServiceReader maps an absent or unrecognised `validation_state`
    // string to null (SPEC-005), so null is what a report actually receives.
    expect(trustReport(null)->isTrusted())->toBeFalse()
        ->and(trustReport(null)->isSignatureValid())->toBeFalse();
})->group('SPEC-013');

// --- AC5: other credential failures are not trust ---------------------------

it('does not trust other signingCredential failures', function (string $code) {
    // The old definition named exactly one code, so every other credential
    // failure fell through to "trusted".
    expect(trustReport(null, [$code])->isTrusted())->toBeFalse();
})->with([
    'signingCredential.revoked',
    'signingCredential.expired',
    'signingCredential.invalid',
])->group('SPEC-013');

it('does not trust an invalid manifest', function () {
    expect(trustReport(ValidationState::Invalid)->isTrusted())->toBeFalse()
        ->and(trustReport(ValidationState::Invalid)->isSignatureValid())->toBeFalse();
})->group('SPEC-013');

// --- AC6: the safe check is the short one to write --------------------------

it('reports a verified AI marking only when the signature checked out', function () {
    $verified = trustReport(ValidationState::Valid);
    $unverified = trustReport(ValidationState::Invalid);
    $noManifest = new ManifestReport(null, null, [], [], null);

    expect($verified->isVerifiedAiGenerated())->toBeTrue()
        ->and($unverified->isVerifiedAiGenerated())->toBeFalse()
        ->and($noManifest->isVerifiedAiGenerated())->toBeFalse();
})->group('SPEC-013');

it('does not report a verified AI marking for a valid asset without the marking', function () {
    $noMarking = new ManifestReport(
        'urn:c2pa:test',
        null,
        [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created']]]]],
        [],
        ValidationState::Trusted,
    );

    expect($noMarking->isSignatureValid())->toBeTrue()
        ->and($noMarking->isAiGenerated())->toBeFalse()
        ->and($noMarking->isVerifiedAiGenerated())->toBeFalse();
})->group('SPEC-013');

// --- AC7/AC8: the distinction is stated where callers look -------------------
// Documentation criteria. Asserted against the docblocks a developer actually
// reads at the call site, so the wording cannot quietly drift back to implying
// that a claim is a verdict.

it('documents that the marking accessors report claims, not verdicts', function () {
    $doc = (string) (new ReflectionMethod(ManifestReport::class, 'isAiGenerated'))->getDocComment();

    expect(strtolower($doc))->toContain('claim')
        ->and($doc)->toContain('isSignatureValid');
})->group('SPEC-013');

it('documents that trust depends on service configuration, not on failure', function () {
    $doc = (string) (new ReflectionMethod(ManifestReport::class, 'isTrusted'))->getDocComment();

    expect(strtolower($doc))->toContain('trust list')
        ->and(strtolower($doc))->toContain('absence');
})->group('SPEC-013');
