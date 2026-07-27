<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading;

use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * Reads the C2PA manifest from an asset and reports what it carries.
 *
 * v1 ships one adapter, SigningServiceReader (PSR-18 client for service/).
 */
interface ReaderInterface
{
    /**
     * @throws ContentCredentialsException on any read failure
     */
    public function read(Asset $asset): ManifestReport;
}
