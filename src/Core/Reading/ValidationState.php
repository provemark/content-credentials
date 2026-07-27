<?php

declare(strict_types=1);

namespace ContentCredentials\Core\Reading;

/**
 * The c2pa-rs top-level validation verdict for a manifest, as returned in the
 * `validation_state` field of `/v1/read`. Ordered Invalid < Valid < Trusted:
 * `Valid` means the claim signature and asset-integrity checks passed but the
 * signing certificate is not on a trust list; `Trusted` additionally means it is.
 */
enum ValidationState: string
{
    case Invalid = 'Invalid';
    case Valid = 'Valid';
    case Trusted = 'Trusted';
}
