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

    /** Above this, the copy of the asset spills to disk (AC10). */
    private const IN_MEMORY_BYTES = 2 * 1024 * 1024;

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

    /**
     * Whether read() reads this media type at all (SPEC-044). False means it
     * refuses before reading, with ReadFailedException.
     *
     * Static and free of the verifier's classes, so a caller can ask while
     * deciding whether to use this reader, before the package is installed.
     */
    public static function supports(MediaType $type): bool
    {
        return ! in_array($type, self::UNSUPPORTED, true);
    }

    public function read(Asset $asset): ManifestReport
    {
        if (! self::supports($asset->mediaType)) {
            throw new ReadFailedException(sprintf(
                'Media type %s is not supported by this reader: provemark/c2pa-verifier cannot read it. '
                .'Read it with the service or extension reader.',
                $asset->mediaType->value,
            ));
        }

        // php://temp, not php://memory (SPEC-042 AC10): above 2 MiB the copy
        // spills to the system temp directory instead of doubling the asset in
        // memory. Measured on 32 MiB: 33.4 MB over baseline with php://memory,
        // 1.4 MB with this.
        $stream = fopen('php://temp/maxmemory:'.self::IN_MEMORY_BYTES, 'w+b');

        if ($stream === false) {
            throw new ReadFailedException('Could not read the asset: no temporary stream available.');
        }

        try {
            // Checked, because without a writable temp directory fwrite()
            // returns 0 past the in-memory limit and only warns; the verifier
            // would then read an empty stream.
            if (@fwrite($stream, $asset->bytes) !== strlen($asset->bytes)) {
                throw new ReadFailedException(
                    'Could not read the asset: it could not be copied to a temporary stream. '
                    .'Check that the system temp directory ('.sys_get_temp_dir().') is writable.'
                );
            }

            rewind($stream);

            // Through JSON rather than toArray(), so the parser sees exactly
            // what was measured: the report as the verifier serialises it.
            $json = (new Verifier)->verify($stream, $this->settings)->toJson();
            $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (ReadFailedException $e) {
            throw $e;
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
