<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading;

use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * Reads the C2PA manifest from an asset and reports what it carries.
 *
 * Two adapters: SigningServiceReader (PSR-18 client for service/, the default)
 * and ExtC2paReader (in-process, via ericmann/ext-c2pa, opt-in). Both return
 * the same ManifestReport; see docs/readers.md for the trade-off.
 */
interface ReaderInterface
{
    /**
     * @throws ContentCredentialsException on any read failure
     */
    public function read(Asset $asset): ManifestReport;
}
