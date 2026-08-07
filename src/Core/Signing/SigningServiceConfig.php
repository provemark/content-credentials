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
    /** Hosts for which plain HTTP never leaves the machine. */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /**
     * @param  int  $maxResponseBytes  Reject a service response larger than this
     *                                 before reading it into memory (SPEC-009 #5).
     *                                 Default 32 MiB: the service caps a body at
     *                                 20 MB (SPEC-017), which carries a ~15 MiB
     *                                 asset, so the largest legitimate response is
     *                                 ~20 MiB plus JSON overhead. Raise it with
     *                                 `MAX_BODY_SIZE` if you raise that.
     * @param  int  $maxRequestBytes  Refuse an asset larger than this before
     *                                encoding it (SPEC-025 AC2). Default 15 MiB —
     *                                what fits in the service's 20 MB body once
     *                                base64 inflates it by a third. The service
     *                                enforces its own limit regardless, so drift
     *                                between the two costs a worse error message,
     *                                never a wrong outcome.
     * @param  bool  $requireSecureTransport  Turn an exposed transport into a
     *                                        failure rather than a warning
     *                                        (SPEC-025 AC3).
     */
    public function __construct(
        public string $baseUrl,
        public string $apiKey,
        public int $maxResponseBytes = 33_554_432,
        public int $maxRequestBytes = 15_728_640,
        public bool $requireSecureTransport = false,
    ) {}

    /**
     * Whether requests carry the bearer token in clear across a network.
     *
     * Loopback over `http` is the documented deployment and is not reported: the
     * service publishes on 127.0.0.1 by design, and nothing leaves the machine.
     * Everything else over `http` is, including a private hostname between two
     * containers — this cannot tell a private network from the public internet,
     * so it reports the fact and leaves the severity to the caller (SPEC-025 AC3).
     */
    public function usesInsecureTransport(): bool
    {
        $scheme = strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME));

        if ($scheme !== 'http') {
            return false;
        }

        $host = strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST));

        return ! in_array($host, self::LOOPBACK_HOSTS, true);
    }
}
