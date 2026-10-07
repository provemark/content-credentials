<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading;

use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadFailedException;
use Provemark\ContentCredentials\Core\Reading\Exception\TrustSettingsRejectedException;
use Provemark\ContentCredentials\Core\Reading\Exception\VerifierMissingException;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Support\ServiceError;

/**
 * Reads in pure PHP through provemark/c2pa-verifier (SPEC-042), for hosts that
 * can run neither the signing service nor ext-c2pa.
 *
 * The verifier is optional (ADR-0007): in `suggest`, never in `require`. Its
 * report is c2patool's JSON shape, so it is decoded by the same
 * ManifestStoreParser as the other two readers, after VerifierOutcome has
 * mapped the cases where the verifier answers differently from them.
 *
 * Measured against verifier v0.5.0 on 2026-10-07: eleven of the thirteen media
 * types agree with both other readers on every accessor, trust and timestamp
 * included.
 */
final class C2paVerifierReader implements ReaderInterface
{
    /**
     * The types verifier v0.5 does not read (SPEC-042 AC3). Read anyway, a
     * signed TIFF comes back "no manifest", so they are refused before the
     * verifier is called. Widening this needs a measurement and an amendment.
     */
    private const UNSUPPORTED = [MediaType::Tiff, MediaType::Svg];

    private readonly ?TrustSettings $settings;

    /**
     * @param  string|null  $trustSettingsJson  the contents of a settings file in c2patool's
     *                                          JSON shape; null or blank verifies without trust
     *
     * @throws VerifierMissingException when provemark/c2pa-verifier is not installed
     * @throws TrustSettingsRejectedException when the verifier refuses the settings
     */
    public function __construct(?string $trustSettingsJson = null)
    {
        if (! self::isAvailable()) {
            throw new VerifierMissingException(
                'The pure-PHP reader needs provemark/c2pa-verifier, which is not installed. '
                .'Install it with `composer require provemark/c2pa-verifier`, or select the '
                .'service or extension reader instead.'
            );
        }

        $this->settings = self::buildSettings($trustSettingsJson);
    }

    public static function isAvailable(): bool
    {
        return class_exists(Verifier::class);
    }

    public function read(Asset $asset): ManifestReport
    {
        if (in_array($asset->mediaType, self::UNSUPPORTED, true)) {
            throw new ReadFailedException(sprintf(
                'Media type %s is not supported by this reader: provemark/c2pa-verifier cannot read it. '
                .'Read it with the service or extension reader.',
                $asset->mediaType->value,
            ));
        }

        $stream = fopen('php://memory', 'w+b');

        if ($stream === false) {
            throw new ReadFailedException('Could not read the asset: no memory stream available.');
        }

        try {
            fwrite($stream, $asset->bytes);
            rewind($stream);

            // Through JSON rather than toArray(), so the parser sees exactly
            // what was measured: the report as the verifier serialises it.
            $json = (new Verifier)->verify($stream, $this->settings)->toJson();
            $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new ReadFailedException(
                'Could not read the asset: '.ServiceError::bound($e->getMessage()),
                previous: $e,
            );
        } finally {
            fclose($stream);
        }

        if (! is_array($report)) {
            throw new ReadFailedException('Could not read the asset: the verifier report was not an object.');
        }

        return VerifierOutcome::toManifestReport($report);
    }

    private static function buildSettings(?string $json): ?TrustSettings
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        try {
            return TrustSettings::fromJson($json);
        } catch (\Throwable $e) {
            throw new TrustSettingsRejectedException(
                'The trust settings were refused: '.ServiceError::bound($e->getMessage()),
                previous: $e,
            );
        }
    }
}
