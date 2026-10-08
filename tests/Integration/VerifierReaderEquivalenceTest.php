<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\C2paVerifierReader;
use Provemark\ContentCredentials\Core\Reading\ExtC2paReader;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-042 AC1/AC9 — the pure-PHP reader agrees with the service, type by type.
 *
 * The comparison is engine against engine: c2pa-rs 0.90.22 in the service
 * against provemark/c2pa-verifier, which shares no code with it. That only
 * means something under equal configuration, so the verifier gets the same
 * trust settings file the service runs with — none in the `defaults` profile,
 * `certs/c2pa-trust.settings.json` in `hardened` (the lesson SPEC-019 learned
 * on 2026-08-08, recorded in ReaderEquivalenceTest.php).
 *
 * Unlike the SPEC-019 comparison this one runs in BLOCKING profiles: the
 * verifier arrives through require-dev, not through a download. The CI guard
 * that asserts it ran greps for this file's test names — rename them there too.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 *
 * @see specs/SPEC-042-pure-php-reader-via-c2pa-verifier.md
 */
$skipUnlessService = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable — the comparison needs it'
    : false;

/** The verifier reader, configured as the running service is. */
function spec042MatchingVerifierReader(): C2paVerifierReader
{
    return ServiceHarness::trustVerificationActive() === true
        ? new C2paVerifierReader((string) file_get_contents(ServiceHarness::trustSettingsPath()))
        : new C2paVerifierReader;
}

function spec042SignedAsset(MediaType $type): Asset
{
    [$signer] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiGenerated($type)
        ->withSoftwareAgent('SPEC-042 equivalence')
        ->build();

    $signed = $signer->sign(new Asset(ServiceHarness::mediaFixture($type), $type), $manifest)->bytes;

    return new Asset($signed, $type);
}

/**
 * The eleven types measured readable by both on 2026-10-07.
 *
 * @return array<string, array{MediaType}>
 */
function spec042ComparableTypes(): array
{
    return [
        'PNG' => [MediaType::Png],
        'JPEG' => [MediaType::Jpeg],
        'WebP' => [MediaType::Webp],
        'AVIF' => [MediaType::Avif],
        'GIF' => [MediaType::Gif],
        'WAV' => [MediaType::Wav],
        'MP3' => [MediaType::Mp3],
        'FLAC' => [MediaType::Flac],
        'MP4' => [MediaType::Mp4],
        'MOV' => [MediaType::Mov],
        'AVI' => [MediaType::Avi],
    ];
}

// --- AC1: three readers, one answer ------------------------------------------

it('agrees with the service reader through the verifier', function (MediaType $type) {
    $asset = spec042SignedAsset($type);
    [, $serviceReader] = ServiceHarness::signerAndReader();

    $viaVerifier = ServiceHarness::accessors(spec042MatchingVerifierReader()->read($asset));
    $viaService = ServiceHarness::accessors($serviceReader->read($asset));

    foreach ($viaService as $accessor => $value) {
        expect($viaVerifier[$accessor])->toBe(
            $value,
            sprintf(
                'readers disagree on %s() for %s: verifier says %s, service (c2pa-rs) says %s',
                $accessor,
                $type->value,
                json_encode($viaVerifier[$accessor]),
                json_encode($value),
            ),
        );
    }
})->with(spec042ComparableTypes())->group('SPEC-042', 'integration')->skip($skipUnlessService);

it('finds the marking it was given, through the verifier', function (MediaType $type) {
    // Pinned, because two readers that both found nothing would agree. Under
    // trust (the `hardened` profile) the verdict is pinned too.
    $report = spec042MatchingVerifierReader()->read(spec042SignedAsset($type));

    expect($report->isAiGenerated())->toBeTrue()
        ->and($report->digitalSourceTypes())->toBe([
            'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
        ])
        ->and(array_map(fn ($agent) => $agent->name, $report->softwareAgents()))
        ->toBe(['SPEC-042 equivalence'])
        // Pinned to what the service says it does, not to `true`: CI runs the
        // service without a TSA, and a first version that assumed one went red
        // in three profiles on the PR that introduced it.
        ->and($report->hasTimestamp())->toBe(ServiceHarness::health()['timestamping'] ?? null);

    if (ServiceHarness::trustVerificationActive() === true) {
        expect($report->isVerifiedAiGenerated())->toBeTrue()
            ->and($report->validationState()?->value)->toBe('Trusted');
    } else {
        expect($report->validationState()?->value)->toBe('Valid');
    }
})->with(spec042ComparableTypes())->group('SPEC-042', 'integration')->skip($skipUnlessService);

it('agrees with the service reader on an unsigned asset', function () {
    // The case the verifier answered differently before the mapping (AC2).
    $asset = new Asset(ServiceHarness::fixtureBytes(), MediaType::Png);
    [, $serviceReader] = ServiceHarness::signerAndReader();

    expect(ServiceHarness::accessors(spec042MatchingVerifierReader()->read($asset)))
        ->toBe(ServiceHarness::accessors($serviceReader->read($asset)));
})->group('SPEC-042', 'integration')->skip($skipUnlessService);

it('agrees with the in-process reader through the verifier', function (MediaType $type) {
    // The third pair, where the extension is installed (the `ext-c2pa` profile
    // and some developer machines). Both get the same trust anchors, but not
    // the same configuration: the verifier also reads the settings file's
    // `trust_config` EKU list, and ExtC2paReader takes PEM only, so the
    // extension applies its own default EKU policy. An agreement here is
    // therefore agreement under the EKUs of these test certificates, not proof
    // that the two EKU policies are equal; a disagreement may be either.
    // SPEC-019 learned on 2026-08-08 what an unequal configuration reports.
    $settings = (string) file_get_contents(ServiceHarness::trustSettingsPath());
    $anchors = (string) file_get_contents(dirname(__DIR__, 2).'/certs/trust_anchors.pem');
    $asset = spec042SignedAsset($type);

    expect(ServiceHarness::accessors((new C2paVerifierReader($settings))->read($asset)))
        ->toBe(ServiceHarness::accessors((new ExtC2paReader($anchors))->read($asset)));
})->with(spec042ComparableTypes())
    ->group('SPEC-042', 'integration')
    ->skip($skipUnlessService)
    ->skip(fn () => ! extension_loaded('c2pa') ? 'ext-c2pa not installed' : false);
