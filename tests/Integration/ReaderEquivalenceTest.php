<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\ExtC2paReader;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-019 AC1/AC2/AC3/AC4/AC6 — the in-process reader, and the equivalence
 * that keeps it honest.
 *
 * AC2 is why this file exists. The extension carries **c2pa-rs 0.89.0** and the
 * service carries **0.90.4** (verified 2026-08-06), so the same asset is read by
 * two different versions of the same engine. Without a test that compares them,
 * a divergence — in what counts as trusted, in how a validation state is named,
 * in whether our Article 50 marking is recognised — would reach a user before it
 * reached us.
 *
 * Note what this suite does NOT need: the signing service, for everything except
 * AC2. That is the point of the spec. Only the comparison needs both.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 *
 * @see specs/SPEC-019-ext-c2pa-reader.md
 */
$skipUnlessExtension = fn () => ! extension_loaded('c2pa')
    ? 'ext-c2pa not installed — install it with `pie install ericmann/ext-c2pa`'
    : false;

$skipUnlessBoth = fn () => match (true) {
    ! extension_loaded('c2pa') => 'ext-c2pa not installed — the equivalence check needs both readers',
    ! ServiceHarness::reachable() => 'signing service not reachable — the equivalence check needs both readers',
    default => false,
};

/** The repository's committed trust anchors, as PEM contents. */
function spec019AnchorsPem(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/certs/trust_anchors.pem');
}

/**
 * A signed asset carrying our Article 50 marking.
 *
 * Produced through the service, because signing is not what this spec changes —
 * the point is that READING it afterwards needs nothing.
 */
function spec019SignedAsset(): string
{
    [$signer] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Png)
        ->withSoftwareAgent('SPEC-019 equivalence')
        ->build();

    return $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest)->bytes;
}

/**
 * Every public accessor, as a comparable map.
 *
 * @return array<string, mixed>
 */
function spec019Accessors(ManifestReport $report): array
{
    return [
        'hasManifest' => $report->hasManifest(),
        'isSignatureValid' => $report->isSignatureValid(),
        'isTrusted' => $report->isTrusted(),
        'validationState' => $report->validationState()?->value,
        'isAiGenerated' => $report->isAiGenerated(),
        'isVerifiedAiGenerated' => $report->isVerifiedAiGenerated(),
        'hasTimestamp' => $report->hasTimestamp(),
        'digitalSourceTypes' => $report->digitalSourceTypes(),
    ];
}

// --- AC1: a signed asset reads without any service ---------------------------

it('reads a signed asset in-process, with no service involved', function () {
    // The asset is prepared once, through the service; reading it is what this
    // criterion is about. In a real deployment the bytes arrive from storage or
    // an upload and no service exists at all.
    $signed = spec019SignedAsset();

    $report = (new ExtC2paReader)->read(new Asset($signed, MediaType::Png));

    expect($report->hasManifest())->toBeTrue()
        ->and($report->isSignatureValid())->toBeTrue()
        ->and($report->isAiGenerated())->toBeTrue()
        ->and($report->digitalSourceTypes())->toContain(
            'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
        );
})->group('SPEC-019', 'integration')
    ->skip($skipUnlessExtension)
    ->skip(fn () => ! ServiceHarness::reachable() ? 'need the service once, to produce a signed asset' : false);

// --- AC3: no C2PA data is an empty report, not an error ----------------------

it('returns an empty report for an asset with no C2PA data', function () {
    // This is the exact shape of the SPEC-010 bug on the service side: the
    // underlying library answered null and an unguarded call crashed. A second
    // reader is a second chance to reintroduce it.
    $report = (new ExtC2paReader)->read(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png));

    expect($report->hasManifest())->toBeFalse()
        ->and($report->isSignatureValid())->toBeFalse()
        ->and($report->isAiGenerated())->toBeFalse()
        ->and($report->isTrusted())->toBeFalse();
})->group('SPEC-019', 'integration')->skip($skipUnlessExtension);

// --- AC4: trust verification works and discriminates -------------------------

it('reports trusted when the anchors cover the signing certificate', function () {
    $signed = spec019SignedAsset();

    $report = (new ExtC2paReader(spec019AnchorsPem()))->read(new Asset($signed, MediaType::Png));

    expect($report->isTrusted())->toBeTrue()
        ->and($report->validationState()?->value)->toBe('Trusted');
})->group('SPEC-019', 'integration')
    ->skip($skipUnlessExtension)
    ->skip(fn () => ! ServiceHarness::reachable() ? 'need the service once, to produce a signed asset' : false);

it('reports untrusted, with a reason, when the anchors do not cover it', function () {
    // The half that proves verification discriminates rather than rubber-stamps
    // (SPEC-014 AC2). A foreign CA, generated here, covers nothing.
    $foreign = shell_exec(
        'openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -days 1 -nodes '
        .'-subj "/CN=SPEC-019 Foreign CA" -keyout /dev/null 2>/dev/null',
    );

    if (! is_string($foreign) || ! str_contains($foreign, 'CERTIFICATE')) {
        $this->markTestSkipped('openssl not available to generate foreign anchors');
    }

    $signed = spec019SignedAsset();
    $report = (new ExtC2paReader($foreign))->read(new Asset($signed, MediaType::Png));

    expect($report->isTrusted())->toBeFalse()
        // Valid signature, untrusted certificate — the distinction this whole
        // project rests on.
        ->and($report->isSignatureValid())->toBeTrue()
        // And it must SAY so. NOTES Step 11: absent trust material verifies
        // nothing silently, and silence is indistinguishable from a real refusal.
        ->and($report->validationStatusCodes())->toContain('signingCredential.untrusted');
})->group('SPEC-019', 'integration')
    ->skip($skipUnlessExtension)
    ->skip(fn () => ! ServiceHarness::reachable() ? 'need the service once, to produce a signed asset' : false);

// --- AC6: malformed input is refused, not crashed ----------------------------

it('throws the same exception type as the service reader on malformed input', function (string $bytes, MediaType $type) {
    // A caller must be able to swap readers without changing error handling —
    // otherwise the interface is a shape, not a contract.
    expect(fn () => (new ExtC2paReader)->read(new Asset($bytes, $type)))
        ->toThrow(\Provemark\ContentCredentials\Core\Reading\Exception\ReadFailedException::class);
})->with([
    'not an image at all' => ['definitely not a png', MediaType::Png],
    'truncated png header' => ["\x89PNG\r\n\x1a\n", MediaType::Png],
    'png bytes declared as jpeg' => ["\x89PNG\r\n\x1a\nrest", MediaType::Jpeg],
])->group('SPEC-019', 'integration')->skip($skipUnlessExtension);

// --- AC2: the two readers agree ----------------------------------------------

it('agrees with the signing-service reader on every public accessor', function () {
    $signed = spec019SignedAsset();
    $asset = new Asset($signed, MediaType::Png);

    [, $serviceReader] = ServiceHarness::signerAndReader();

    $viaExtension = spec019Accessors((new ExtC2paReader)->read($asset));
    $viaService = spec019Accessors($serviceReader->read($asset));

    // Named per accessor rather than as one array comparison, so a divergence
    // says WHICH answer differs and what both engines said. c2pa-rs 0.89.0 in
    // the extension against 0.90.4 in the service is the reason to expect one.
    foreach ($viaExtension as $accessor => $value) {
        expect($value)->toBe(
            $viaService[$accessor],
            sprintf(
                'readers disagree on %s(): extension (c2pa-rs 0.89) says %s, service (0.90.4) says %s',
                $accessor,
                json_encode($value),
                json_encode($viaService[$accessor]),
            ),
        );
    }
})->group('SPEC-019', 'integration')->skip($skipUnlessBoth);

it('agrees on an unsigned asset too', function () {
    // The empty case is where SPEC-010 bit, and where two engines are most
    // likely to differ in what "nothing here" looks like.
    $asset = new Asset(ServiceHarness::fixtureBytes(), MediaType::Png);

    [, $serviceReader] = ServiceHarness::signerAndReader();

    expect(spec019Accessors((new ExtC2paReader)->read($asset)))
        ->toBe(spec019Accessors($serviceReader->read($asset)));
})->group('SPEC-019', 'integration')->skip($skipUnlessBoth);