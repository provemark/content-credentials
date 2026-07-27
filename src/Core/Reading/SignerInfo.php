<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading;

/** Who signed the active manifest, from the c2pa-rs `signature_info`. */
final readonly class SignerInfo
{
    public function __construct(
        public string $issuer,
        public ?string $commonName = null,
        public ?string $algorithm = null,
    ) {}
}
