<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Signing;

use Provemark\ContentCredentials\Core\Manifest\Manifest;
use Provemark\ContentCredentials\Core\Signing\Exception\MediaTypeMismatchException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningFailedException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningResponseException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningTransportException;
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
        $responseBody = (string) $response->getBody();

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

    private function extractError(string $body): string
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'unknown error';
        }

        if (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }

        return 'unknown error';
    }
}
