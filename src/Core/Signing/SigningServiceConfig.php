<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Signing;

/**
 * Configuration for the signing service client.
 *
 * The API key is a secret; it is sent as a Bearer token and must never be
 * logged or placed in exception messages (SPEC-002 AC7).
 */
final readonly class SigningServiceConfig
{
    /**
     * @param  int  $maxResponseBytes  Reject a service response larger than this
     *                                 before reading it into memory (SPEC-009 #5).
     *                                 Default 96 MiB — headroom over the service's
     *                                 50 MB request cap and base64 inflation.
     */
    public function __construct(
        public string $baseUrl,
        public string $apiKey,
        public int $maxResponseBytes = 100_663_296,
    ) {}
}
