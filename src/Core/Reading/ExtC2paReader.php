<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading;

use Automattic\VIP\C2PA\Reader as ExtReader;
use Automattic\VIP\C2PA\Settings as ExtSettings;
use Provemark\ContentCredentials\Core\Reading\Exception\ExtensionMissingException;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadFailedException;
use Provemark\ContentCredentials\Core\Signing\Asset;

/**
 * Reads C2PA credentials in-process, through the `ericmann/ext-c2pa` extension
 * (SPEC-019). No signing service, no HTTP, no network.
 *
 * Verification needs no private key, no certificate and no service — it is a
 * function of the asset bytes plus, optionally, a trust list. It needed a
 * service until now only because reading and signing shared one transport. That
 * matters beyond convenience: most PHP hosting cannot run a second process, so
 * the service requirement put verification out of reach of the deployments most
 * likely to encounter someone else's credential.
 *
 * **Signing is deliberately unaffected.** The extension can also sign, and this
 * class does not expose that: it would move the private key into the web
 * process, which is the trade ADR-0003 rejected. That is a separate decision,
 * not something to inherit from a reader.
 *
 * Two properties to keep in view, both documented in SPEC-019:
 *
 * - The extension is **v0.1.0** and is an Automattic VIP product rather than
 *   neutral infrastructure. This adapter is the containment.
 * - It carries **c2pa-rs 0.89.0**; the signing service carries **0.90.4**. The
 *   equivalence test (SPEC-019 AC2) is what would surface a divergence between
 *   the two engines before a user does.
 *
 * Decoding is shared with SigningServiceReader via ManifestStoreParser, so both
 * readers answer from one definition of trusted.
 *
 * ```php
 * $reader = ExtC2paReader::isAvailable()
 *     ? new ExtC2paReader($anchorsPem)
 *     : new SigningServiceReader($client, $factory, $factory, $config);
 * ```
 */
final class ExtC2paReader implements ReaderInterface
{
    private const EXTENSION = 'c2pa';

    /**
     * @param  string|null  $trustAnchorsPem  PEM **contents** of the trust anchors
     *                                        to verify against — not a path. Null
     *                                        keeps the extension's default, under
     *                                        which a signature can be valid but
     *                                        never trusted.
     *
     * @throws ExtensionMissingException when ext-c2pa is not loaded
     */
    private readonly ?ExtSettings $settings;

    public function __construct(private readonly ?string $trustAnchorsPem = null)
    {
        if (! self::isAvailable()) {
            throw new ExtensionMissingException(
                'The ext-c2pa extension is required for in-process reading and is not loaded. '
                .'Install it with `pie install ericmann/ext-c2pa`, or use SigningServiceReader '
                .'to read through the signing service instead.'
            );
        }

        // Built once. It was rebuilt on every read() — a fresh Settings object,
        // the PEM re-parsed, the post-condition re-checked — for an answer that
        // cannot change, since $trustAnchorsPem is readonly. Building it here
        // also moves the fail-closed check to wiring time, so a misconfigured
        // trust setup surfaces when the reader is constructed rather than on
        // whichever request happens to read first.
        $this->settings = $this->buildSettings();
    }

    public static function isAvailable(): bool
    {
        return extension_loaded(self::EXTENSION);
    }

    public function read(Asset $asset): ManifestReport
    {
        try {
            $reader = ExtReader::fromBytes(
                $asset->bytes,
                $asset->mediaType->value,
                $this->settings,
            );
        } catch (\Throwable $e) {
            // The extension raises C2paException for anything it cannot parse.
            // Mapped to the same type SigningServiceReader raises, so a caller
            // can swap readers without touching its error handling — otherwise
            // ReaderInterface is a shape rather than a contract.
            throw new ReadFailedException('Could not read the asset: '.$e->getMessage(), previous: $e);
        }

        // An asset with no C2PA data is an empty report, not an error
        // (SPEC-003 D2). Verified 2026-08-06: the extension returns a Reader
        // whose hasManifest() is false here — it does NOT return null, which is
        // the shape that produced the SPEC-010 crash on the service side.
        if (! $reader->hasManifest()) {
            return new ManifestReport(null, null, [], [], null);
        }

        return ManifestStoreParser::fromJson($reader->json());
    }

    private function buildSettings(): ?ExtSettings
    {
        if ($this->trustAnchorsPem === null || trim($this->trustAnchorsPem) === '') {
            return null;
        }

        $settings = new ExtSettings;
        $settings->withTrustAnchors($this->trustAnchorsPem);

        // Configured is not the same as effective (SPEC-032 AC3/AC4). This
        // project has three records of trust configuration that silently
        // verified nothing (NOTES Steps 11, 14, 21); this is the one surface
        // left where the two were not distinguished.
        TrustAnchorsGuard::ensureApplied($settings->hasTrustAnchors());

        return $settings;
    }
}
