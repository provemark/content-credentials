<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\C2paVerifierReader;
use Provemark\ContentCredentials\Core\Reading\ExtC2paReader;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-045 AC2 — every reader reports the same ingredients with the same
 * verdicts.
 *
 * The files are the unit suite's (`tests/Fixtures/spec045-*`), written by
 * C2PA Sign + c2patool 0.9.12 (with and without a site logo), by c2patool
 * 0.27.22 and by c2pa-rs. The logo-bearing one joined with Amendment 4, once
 * verifier 0.6.1 read its data box. The comparison only means something under equal
 * configuration, so the in-process readers get the trust settings the running
 * service has — the SPEC-019 lesson of 2026-08-08.
 *
 * `ServiceHarness::accessors()` now carries `ingredients`, so the SPEC-019 and
 * SPEC-042 comparisons cover it on their own assets as well.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 *
 * @see specs/SPEC-045-ingredients-with-their-own-verdict.md
 */
$skipUnlessService = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — the comparison needs it'
    : false;

function spec045IntegrationAsset(string $name): Asset
{
    $bytes = (string) file_get_contents(dirname(__DIR__).'/Fixtures/'.$name);

    return new Asset($bytes, str_ends_with($name, '.jpg') ? MediaType::Jpeg : MediaType::Png);
}

function spec045IntegrationAnchorsPem(): string
{
    $settings = json_decode((string) file_get_contents(ServiceHarness::trustSettingsPath()), true, 512, JSON_THROW_ON_ERROR);
    $trust = is_array($settings) ? ($settings['trust'] ?? null) : null;
    $pem = is_array($trust) ? ($trust['trust_anchors'] ?? null) : null;

    if (! is_string($pem)) {
        throw new RuntimeException('certs/c2pa-trust.settings.json carries no trust.trust_anchors');
    }

    return $pem;
}

/** @return array<string, array{string}> */
function spec045ComparableFixtures(): array
{
    return [
        'c2pasign trusted' => ['spec045-c2pasign-trusted.png'],
        'c2pasign rogue' => ['spec045-c2pasign-rogue.png'],
        'c2patool 0.27 trusted' => ['spec045-c2patool027-trusted.png'],
        'c2patool 0.27 rogue' => ['spec045-c2patool027-rogue.png'],
        'c2pa-rs CIE-sig-CA' => ['spec045-c2pa-rs-CIE-sig-CA.jpg'],
        'graft' => ['spec045-graft.png'],
        // Amendment 4: since verifier 0.6.1 the logo in c2pa.databoxes is read.
        'c2pasign logo' => ['spec045-c2pasign-logo.png'],
    ];
}

it('reports the same ingredients through the service and the verifier', function (string $fixture) {
    [, $service] = ServiceHarness::signerAndReader();
    $verifier = ServiceHarness::trustVerificationActive() === true
        ? new C2paVerifierReader((string) file_get_contents(ServiceHarness::trustSettingsPath()))
        : new C2paVerifierReader;

    $asset = spec045IntegrationAsset($fixture);
    $viaService = ServiceHarness::accessors($service->read($asset));

    // Pinned to something, so two readers that both found nothing cannot agree.
    expect($viaService['ingredients'])->not->toBe([])
        ->and(ServiceHarness::accessors($verifier->read($asset))['ingredients'])->toBe($viaService['ingredients']);
})->with(spec045ComparableFixtures())->skip($skipUnlessService)->group('integration', 'SPEC-045');

it('reports the same ingredients through the service and ext-c2pa', function (string $fixture) {
    [, $service] = ServiceHarness::signerAndReader();
    $extension = ServiceHarness::trustVerificationActive() === true
        ? new ExtC2paReader(spec045IntegrationAnchorsPem())
        : new ExtC2paReader;

    $asset = spec045IntegrationAsset($fixture);

    $viaService = ServiceHarness::accessors($service->read($asset));

    // Pinned to something, so two readers that both found nothing cannot agree.
    expect($viaService['ingredients'])->not->toBe([])
        ->and(ServiceHarness::accessors($extension->read($asset))['ingredients'])->toBe($viaService['ingredients']);
})->with(spec045ComparableFixtures())
    ->skip(fn () => ! ExtC2paReader::isAvailable() ? 'ext-c2pa is not loaded' : $skipUnlessService())
    ->group('integration', 'SPEC-045');
