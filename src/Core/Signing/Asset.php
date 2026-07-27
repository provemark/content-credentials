<?php

declare(strict_types=1);

namespace ContentCredentials\Core\Signing;

use ContentCredentials\Core\Manifest\MediaType;

/** The raw asset bytes to be signed, with their media type. */
final readonly class Asset
{
    public function __construct(
        public string $bytes,
        public MediaType $mediaType,
    ) {}
}
