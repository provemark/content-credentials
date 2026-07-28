<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel;

/**
 * Timeout options applied when the provider constructs the default HTTP client
 * (SPEC-008). A client concern, not part of the signing contract — hence a
 * Laravel-layer value object, kept out of the Core SigningServiceConfig.
 */
final readonly class HttpClientOptions
{
    public function __construct(
        public float $timeout,
        public float $connectTimeout,
    ) {}

    /**
     * Guzzle request options (`GuzzleHttp\RequestOptions`).
     *
     * @return array{timeout: float, connect_timeout: float}
     */
    public function toArray(): array
    {
        return ['timeout' => $this->timeout, 'connect_timeout' => $this->connectTimeout];
    }
}
