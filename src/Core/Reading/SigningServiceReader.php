<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading;

use Provemark\ContentCredentials\Core\Reading\Exception\ReadFailedException;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadResponseException;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadTransportException;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Support\ResponseBody;
use Provemark\ContentCredentials\Core\Support\ServiceError;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 client for the service/ `/v1/read` endpoint (primer §3). Parses the
 * c2pa-rs manifest store JSON into a typed ManifestReport. Reuses SPEC-002's
 * Asset and SigningServiceConfig (same service and auth; SPEC-003 D1).
 */
final class SigningServiceReader implements ReaderInterface
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly SigningServiceConfig $config,
    ) {}

    public function read(Asset $asset): ManifestReport
    {
        $body = json_encode([
            'content' => base64_encode($asset->bytes),
            'mime_type' => $asset->mediaType->value,
        ], JSON_THROW_ON_ERROR);

        $request = $this->requestFactory
            ->createRequest('POST', rtrim($this->config->baseUrl, '/').'/v1/read')
            ->withHeader('Authorization', 'Bearer '.$this->config->apiKey)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ReadTransportException('Read request could not be sent: '.$e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();

        // SPEC-009 #5: bound the response before buffering/parsing it.
        $responseBody = ResponseBody::readBounded($response->getBody(), $this->config->maxResponseBytes);
        if ($responseBody === null) {
            throw new ReadResponseException('Read service response exceeded the maximum allowed size.');
        }

        if ($status < 200 || $status >= 300) {
            throw new ReadFailedException(sprintf(
                'Read service returned HTTP %d: %s',
                $status,
                ServiceError::fromBody($responseBody),
            ));
        }

        return $this->parse($responseBody);
    }

    /**
     * Decoding is delegated to the shared ManifestStoreParser (SPEC-019), which
     * this method's body became. There is now a second way to obtain the same
     * c2pa-rs store JSON — in-process, via ext-c2pa — and two decoders would be
     * two places for the definition of "trusted" to drift.
     */
    private function parse(string $body): ManifestReport
    {
        return ManifestStoreParser::fromJson($body);
    }
}
