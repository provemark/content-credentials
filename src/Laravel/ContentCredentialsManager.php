<?php

declare(strict_types=1);

namespace ContentCredentials\Laravel;

use ContentCredentials\Core\Manifest\Manifest;
use ContentCredentials\Core\Reading\ManifestReport;
use ContentCredentials\Core\Reading\ReaderInterface;
use ContentCredentials\Core\Signing\Asset;
use ContentCredentials\Core\Signing\SignedAsset;
use ContentCredentials\Core\Signing\SignerInterface;

/**
 * Thin application service behind the ContentCredentials facade. Proxies to the
 * Core signer/reader bound in the container.
 */
final readonly class ContentCredentialsManager
{
    public function __construct(
        private SignerInterface $signer,
        private ReaderInterface $reader,
    ) {}

    public function sign(Asset $asset, Manifest $manifest): SignedAsset
    {
        return $this->signer->sign($asset, $manifest);
    }

    public function read(Asset $asset): ManifestReport
    {
        return $this->reader->read($asset);
    }
}
