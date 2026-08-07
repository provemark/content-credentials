<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Signing;

use Provemark\ContentCredentials\Core\Manifest\Manifest;
use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * Signs an asset with a manifest, returning the signed asset bytes.
 *
 * v1 ships one adapter, SigningServiceSigner (PSR-18 client for service/).
 */
interface SignerInterface
{
    /**
     * @param  Asset|null  $parent  The original asset, for a manifest that marks
     *                              manipulation rather than creation (SPEC-028).
     *                              Mandatory-by-manifest: required exactly when
     *                              {@see Manifest::requiresParentAsset()} is
     *                              true, and refused otherwise.
     *
     * @throws ContentCredentialsException on any signing failure
     */
    public function sign(Asset $asset, Manifest $manifest, ?Asset $parent = null): SignedAsset;
}
