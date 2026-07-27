<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Signing;

use Provemark\ContentCredentials\Core\Manifest\MediaType;

/**
 * The signed asset returned by the service. `bytes` are the exact signed file
 * bytes to persist — never re-encode them (the manifest binds a hash over the
 * asset; any mutation invalidates it, primer §6).
 */
final readonly class SignedAsset
{
    public function __construct(
        public string $bytes,
        public MediaType $mediaType,
    ) {}
}
