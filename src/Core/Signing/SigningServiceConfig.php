<?php

declare(strict_types=1);

namespace ContentCredentials\Core\Signing;

/**
 * Configuration for the signing service client.
 *
 * The API key is a secret; it is sent as a Bearer token and must never be
 * logged or placed in exception messages (SPEC-002 AC7).
 */
final readonly class SigningServiceConfig
{
    public function __construct(
        public string $baseUrl,
        public string $apiKey,
    ) {}
}
