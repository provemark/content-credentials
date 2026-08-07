<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\ValidationState;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-014 — trust-list verification in `/v1/read`.
 *
 * Integration tests against the running service (docker compose up). Excluded
 * from `composer check`; run with `vendor/bin/pest --group=integration`.
 *
 * AC1 and AC3 describe two *different service configurations* — trust
 * verification on and off — which one process cannot hold at once. Each is
 * therefore gated on what `GET /health` reports (AC6) and skips otherwise, so
 * the full set is covered by running the suite once per configuration rather
 * than by a test silently asserting the wrong mode.
 */
$skipUnlessReachable = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — start it with docker compose up -d'
    : false;

$skipUnlessTrustActive = fn () => match (ServiceHarness::trustVerificationActive()) {
    null => 'service does not report trust_verification on /health (pre-SPEC-014)',
    false => 'service is running without trust settings — set CONTENTAUTH_TRUST_SETTINGS and restart',
    true => false,
};

$skipUnlessTrustInactive = fn () => match (ServiceHarness::trustVerificationActive()) {
    null => 'service does not report trust_verification on /health (pre-SPEC-014)',
    true => 'service is running WITH trust settings — unset CONTENTAUTH_TRUST_SETTINGS to cover the default',
    false => false,
};

/** Sign the fixture and read the result back through the service. */
$signAndRead = function () {
    [$signer, $reader] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('SPEC-014 trust verification')
        ->build();

    $signed = $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest);

    return $reader->read(new Asset($signed->bytes, MediaType::Png));
};

// --- AC6: the service reports whether trust verification is active ----------

it('reports trust verification status on /health', function () {
    $health = ServiceHarness::health();

    expect($health)->toHaveKey('trust_verification')
        ->and($health['trust_verification'])->toBeBool();
})->group('SPEC-014', 'integration')->skip($skipUnlessReachable);

// --- AC1: a trusted certificate reads as Trusted ----------------------------
// This is the criterion that closes SPEC-013's recorded open consequence: until
// the service can produce `Trusted`, isTrusted() is correct but permanently
// false.

it('reads a signed asset as Trusted when trust verification is active', function () use ($signAndRead) {
    $report = $signAndRead();

    expect($report->hasManifest())->toBeTrue()
        ->and($report->validationState())->toBe(ValidationState::Trusted)
        ->and($report->validationStatusCodes())->not->toContain('signingCredential.untrusted')
        ->and($report->isTrusted())->toBeTrue()
        ->and($report->isSignatureValid())->toBeTrue();
})->group('SPEC-014', 'integration')
    ->skip($skipUnlessReachable)
    ->skip($skipUnlessTrustActive);

// --- AC2: anchors that do not cover the certificate stay untrusted ----------
// Needs a *second* service configured with non-covering anchors, so it is gated
// on an explicit URL rather than silently passing against the primary service.

it('keeps a signature valid but untrusted when the anchors do not cover it', function () {
    $url = getenv('CONTENTAUTH_SERVICE_URL_FOREIGN_ANCHORS');

    $previous = getenv('CONTENTAUTH_SERVICE_URL');
    putenv('CONTENTAUTH_SERVICE_URL='.$url);

    try {
        [$signer, $reader] = ServiceHarness::signerAndReader();

        $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
            ->withSoftwareAgent('SPEC-014 foreign anchors')
            ->build();

        $signed = $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest);
        $report = $reader->read(new Asset($signed->bytes, MediaType::Png));

        // Integrity and trust are independent: the signature is still valid.
        expect($report->validationState())->toBe(ValidationState::Valid)
            ->and($report->validationStatusCodes())->toContain('signingCredential.untrusted')
            ->and($report->isSignatureValid())->toBeTrue()
            ->and($report->isTrusted())->toBeFalse();
    } finally {
        putenv($previous === false ? 'CONTENTAUTH_SERVICE_URL' : 'CONTENTAUTH_SERVICE_URL='.$previous);
    }
})->group('SPEC-014', 'integration')->skip(
    fn () => getenv('CONTENTAUTH_SERVICE_URL_FOREIGN_ANCHORS') === false
        ? 'set CONTENTAUTH_SERVICE_URL_FOREIGN_ANCHORS to a service started with anchors that do not cover the test cert'
        : false
);

// --- AC3: with no trust settings, behaviour is unchanged --------------------

it('leaves reads unchanged when no trust settings are configured', function () use ($signAndRead) {
    $report = $signAndRead();

    expect($report->hasManifest())->toBeTrue()
        ->and($report->validationState())->toBe(ValidationState::Valid)
        ->and($report->validationStatusCodes())->toContain('signingCredential.untrusted')
        ->and($report->isSignatureValid())->toBeTrue();
})->group('SPEC-014', 'integration')
    ->skip($skipUnlessReachable)
    ->skip($skipUnlessTrustInactive);

// --- AC7: trust verification must not turn absence into an error ------------
// SPEC-010's contract has to survive with trust verification switched on. The
// isTrusted() half of AC7 belongs to SPEC-013 and is asserted in its suite;
// here we pin the part SPEC-014 owns — an empty store, not a failure.

it('still reads a manifest-less asset as an empty report under trust verification', function () {
    [, $reader] = ServiceHarness::signerAndReader();

    $report = $reader->read(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png));

    expect($report->hasManifest())->toBeFalse()
        ->and($report->isAiGenerated())->toBeFalse()
        ->and($report->validationStatusCodes())->toBe([]);
})->group('SPEC-014', 'integration')
    ->skip($skipUnlessReachable)
    ->skip($skipUnlessTrustActive);
