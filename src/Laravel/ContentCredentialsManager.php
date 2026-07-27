<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel;

use Provemark\ContentCredentials\Core\Manifest\Manifest;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Reading\ReaderInterface;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\SignedAsset;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;

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
