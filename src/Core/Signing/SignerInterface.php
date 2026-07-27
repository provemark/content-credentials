<?php

declare(strict_types=1);

namespace ContentCredentials\Core\Signing;

use ContentCredentials\Core\Manifest\Manifest;
use ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * Signs an asset with a manifest, returning the signed asset bytes.
 *
 * v1 ships one adapter, SigningServiceSigner (PSR-18 client for service/).
 */
interface SignerInterface
{
    /**
     * @throws ContentCredentialsException on any signing failure
     */
    public function sign(Asset $asset, Manifest $manifest): SignedAsset;
}
