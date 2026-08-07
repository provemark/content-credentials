<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningFailedException;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-026 — the digitalSourceType family, through the real engine.
 *
 * Widening a vocabulary proves nothing on its own: what has to hold is that a
 * manifest carrying each term signs, reads back `Valid`, and comes back with the
 * term intact. The service composes its own claim generator and passes our
 * assertions through, so a source type it has never seen is a real question.
 *
 * Run with `vendor/bin/pest --group=integration` against a service started with
 * `RATE_LIMIT_REQUESTS=1000` (NOTES Step 17).
 *
 * @see specs/SPEC-026-digital-source-types.md
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

/**
 * The non-AI source types cannot be signed by a service configured to accept
 * only AI marking — which is AC5, asserted below. So AC1 and AC7 describe a
 * configuration AC5 excludes, and the two cannot be tested in one process. Same
 * split as SPEC-014's trust-on/trust-off, covered by the `defaults` and
 * `hardened` CI profiles between them.
 */
$skipWhenAiMarkingRequired = fn () => (ServiceHarness::health()['require_ai_marking'] ?? false) === true
    ? 'service runs with REQUIRE_AI_MARKING=true — the non-AI terms are refused by design (AC5)'
    : false;

/** Sign a fixture marked with one source type and read it back. */
function spec026RoundTrip(DigitalSourceType $type): void
{
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forSourceType($type, MediaType::Png)
        ->withSoftwareAgent('SPEC-026', '1.0')
        ->build();

    $signed = $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest);
    $report = $reader->read(new Asset($signed->bytes, MediaType::Png));

    expect($report->hasManifest())->toBeTrue()
        ->and($report->isSignatureValid())->toBeTrue()
        // The term survived the round trip — which is the whole claim.
        ->and($report->digitalSourceTypes())->toContain($type->value);
}

// --- AC1: every emittable type signs and reads back -------------------------
// Written out rather than looped: when one breaks, the failure has to name it.

it('signs and reads back trainedAlgorithmicMedia', function () {
    spec026RoundTrip(DigitalSourceType::TrainedAlgorithmicMedia);
})->skip($skipUnlessReachable)->group('SPEC-026', 'integration');

it('signs and reads back compositeSynthetic', function () {
    spec026RoundTrip(DigitalSourceType::CompositeSynthetic);
})->skip($skipUnlessReachable)->skip($skipWhenAiMarkingRequired)->group('SPEC-026', 'integration');

it('signs and reads back algorithmicMedia', function () {
    spec026RoundTrip(DigitalSourceType::AlgorithmicMedia);
})->skip($skipUnlessReachable)->skip($skipWhenAiMarkingRequired)->group('SPEC-026', 'integration');

// --- AC7: the reading side, through the real engine -------------------------

it('reads a compositeSynthetic asset as generative but not AI-generated', function () {
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forSynthetic(MediaType::Png)
        ->withSoftwareAgent('SPEC-026')
        ->build();

    $signed = $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest);
    $report = $reader->read(new Asset($signed->bytes, MediaType::Png));

    // The distinction AC7 exists for, asserted end to end rather than against a
    // hand-built report: isAiGenerated() keeps meaning trainedAlgorithmicMedia.
    expect($report->involvesGenerativeAi())->toBeTrue()
        ->and($report->isAiGenerated())->toBeFalse();
})->skip($skipUnlessReachable)->skip($skipWhenAiMarkingRequired)->group('SPEC-026', 'integration');

it('reads an algorithmicMedia asset as neither', function () {
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAlgorithmic(MediaType::Png)
        ->withSoftwareAgent('SPEC-026')
        ->build();

    $signed = $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest);
    $report = $reader->read(new Asset($signed->bytes, MediaType::Png));

    // Synthetic, but no model and no training data. Reporting it as generative
    // would erase the distinction the term exists to make.
    expect($report->involvesGenerativeAi())->toBeFalse()
        ->and($report->isAiGenerated())->toBeFalse();
})->skip($skipUnlessReachable)->skip($skipWhenAiMarkingRequired)->group('SPEC-026', 'integration');

// --- AC5: REQUIRE_AI_MARKING still means what it says ------------------------

it('refuses algorithmicMedia when the service requires AI marking', function () {
    [$signer] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAlgorithmic(MediaType::Png)
        ->withSoftwareAgent('SPEC-026 AC5')
        ->build();

    // algorithmicMedia is the sharpest test of that policy: synthetic, and
    // explicitly NOT trained on sampled data — near enough to AI marking to be
    // mistaken for it, and not what the policy names. Widening the enum must not
    // quietly widen what a deployment marking only AI content will sign.
    expect(fn () => $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest))
        ->toThrow(SigningFailedException::class);
})->skip($skipUnlessReachable)
    ->skip(fn () => (ServiceHarness::health()['require_ai_marking'] ?? false) !== true
        ? 'service runs with REQUIRE_AI_MARKING unset — run the hardened profile'
        : false)
    ->group('SPEC-026', 'integration');

it('still signs trainedAlgorithmicMedia when the service requires AI marking', function () {
    // The other half: the policy must keep accepting what it exists to accept.
    [$signer] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('SPEC-026 AC5')
        ->build();

    $signed = $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest);

    expect($signed->bytes)->not->toBe('');
})->skip($skipUnlessReachable)
    ->skip(fn () => (ServiceHarness::health()['require_ai_marking'] ?? false) !== true
        ? 'service runs with REQUIRE_AI_MARKING unset — run the hardened profile'
        : false)
    ->group('SPEC-026', 'integration');
