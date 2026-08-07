<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Signing;

use Provemark\ContentCredentials\Core\Manifest\Manifest;
use Provemark\ContentCredentials\Core\Signing\Exception\AssetTooLargeException;
use Provemark\ContentCredentials\Core\Signing\Exception\MediaTypeMismatchException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningFailedException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningResponseException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningTransportException;
use Provemark\ContentCredentials\Core\Support\ResponseBody;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 client for the service/ `/v1/sign` contract (primer §3).
 *
 * Maps a SPEC-001 Manifest + asset bytes onto the service's field set
 * (content/mime_type/creator_name/extra_assertions) — the service rebuilds
 * claim_generator_info and format server-side — and returns the signed bytes.
 * The concrete HTTP client and PSR-17 factories are injected (see ADR-0001).
 */
final class SigningServiceSigner implements SignerInterface
{
    /** How much of a service error message is carried into an exception (SPEC-025 AC4). */
    private const MAX_ERROR_CHARS = 256;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly SigningServiceConfig $config,
    ) {}

    public function sign(Asset $asset, Manifest $manifest): SignedAsset
    {
        // AC6: a mismatch is a programming error — fail before any HTTP call.
        if ($asset->mediaType !== $manifest->mediaType()) {
            throw new MediaTypeMismatchException(sprintf(
                'Asset media type "%s" does not match manifest media type "%s".',
                $asset->mediaType->value,
                $manifest->mediaType()->value,
            ));
        }

        // SPEC-025 AC2: before encoding, not after. The base64 copy and the
        // JSON body together cost roughly 3.7x the asset, so a client that
        // discovers the limit from the service's 413 has already paid for it —
        // or died trying, which is the case this exists for.
        $size = strlen($asset->bytes);
        if ($size > $this->config->maxRequestBytes) {
            throw new AssetTooLargeException(sprintf(
                'Asset is %d bytes, which exceeds the configured limit of %d bytes for a signing request. '
                .'The signing service accepts a body of MAX_BODY_SIZE; raise both if you sign larger assets.',
                $size,
                $this->config->maxRequestBytes,
            ));
        }

        $body = json_encode($this->buildPayload($asset, $manifest), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $request = $this->requestFactory
            ->createRequest('POST', $this->endpoint())
            ->withHeader('Authorization', 'Bearer '.$this->config->apiKey)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new SigningTransportException('Signing request could not be sent: '.$e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();

        // SPEC-009 #5: bound the response before buffering/decoding it.
        $responseBody = ResponseBody::readBounded($response->getBody(), $this->config->maxResponseBytes);
        if ($responseBody === null) {
            throw new SigningResponseException('Signing service response exceeded the maximum allowed size.');
        }

        if ($status < 200 || $status >= 300) {
            throw new SigningFailedException(sprintf(
                'Signing service returned HTTP %d: %s',
                $status,
                $this->extractError($responseBody),
            ));
        }

        return new SignedAsset($this->decodeSignedContent($responseBody), $asset->mediaType);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Asset $asset, Manifest $manifest): array
    {
        $payload = [
            'content' => base64_encode($asset->bytes),
            'mime_type' => $asset->mediaType->value,
            'extra_assertions' => $manifest->assertions(),
        ];

        // D1: send the claim-generator name as creator_name when present; the
        // service composes its own claim_generator_info from it.
        $creatorName = $this->creatorName($manifest);
        if ($creatorName !== null) {
            $payload['creator_name'] = $creatorName;
        }

        return $payload;
    }

    private function creatorName(Manifest $manifest): ?string
    {
        $claimGeneratorInfo = $manifest->toArray()['claim_generator_info'] ?? [];
        $first = $claimGeneratorInfo[0] ?? null;

        return $first['name'] ?? null;
    }

    private function endpoint(): string
    {
        return rtrim($this->config->baseUrl, '/').'/v1/sign';
    }

    private function decodeSignedContent(string $body): string
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SigningResponseException('Signing service returned a non-JSON response.', previous: $e);
        }

        if (! is_array($decoded) || ! isset($decoded['signed_content']) || ! is_string($decoded['signed_content'])) {
            throw new SigningResponseException('Signing service response is missing a "signed_content" string.');
        }

        $bytes = base64_decode($decoded['signed_content'], true);
        if ($bytes === false) {
            throw new SigningResponseException('Signing service returned invalid base64 in "signed_content".');
        }

        return $bytes;
    }

    /**
     * The service's own error text, capped (SPEC-025 AC4).
     *
     * Whatever answers on that URL controls this string, and it ends up in an
     * application's logs through the exception message. The service caps every
     * caller-supplied string it records for the same reason; this reciprocates.
     */
    private function extractError(string $body): string
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'unknown error';
        }

        if (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])) {
            $error = $decoded['error'];

            return strlen($error) > self::MAX_ERROR_CHARS
                ? substr($error, 0, self::MAX_ERROR_CHARS).'… (truncated)'
                : $error;
        }

        return 'unknown error';
    }
}
