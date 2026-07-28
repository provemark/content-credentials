<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading;

use Provemark\ContentCredentials\Core\Reading\Exception\ReadFailedException;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadResponseException;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadTransportException;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Support\ResponseBody;
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
                $this->extractError($responseBody),
            ));
        }

        return $this->parse($responseBody);
    }

    private function parse(string $body): ManifestReport
    {
        try {
            $store = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ReadResponseException('Read service returned a non-JSON response.', previous: $e);
        }

        if (! is_array($store)) {
            throw new ReadResponseException('Read service response was not a manifest store object.');
        }

        $activeLabel = isset($store['active_manifest']) && is_string($store['active_manifest'])
            ? $store['active_manifest']
            : null;

        $manifests = isset($store['manifests']) && is_array($store['manifests']) ? $store['manifests'] : [];
        $active = $activeLabel !== null && isset($manifests[$activeLabel]) && is_array($manifests[$activeLabel])
            ? $manifests[$activeLabel]
            : null;

        $state = isset($store['validation_state']) && is_string($store['validation_state'])
            ? ValidationState::tryFrom($store['validation_state'])
            : null;

        if ($active === null) {
            return new ManifestReport(null, null, [], $this->validationCodes($store), $state);
        }

        return new ManifestReport(
            $activeLabel,
            $this->parseSigner($active),
            $this->parseAssertions($active),
            $this->validationCodes($store),
            $state,
            $this->parseHasTimestamp($active),
        );
    }

    /**
     * True iff the active manifest's `signature_info.time` is present and parses
     * as a date-time (SPEC-007 D1/D3). Untrusted input: a missing, empty,
     * non-string or unparseable value yields false, never an exception.
     *
     * @param  array<array-key, mixed>  $manifest
     */
    private function parseHasTimestamp(array $manifest): bool
    {
        $info = $manifest['signature_info'] ?? null;
        if (! is_array($info)) {
            return false;
        }

        $time = $info['time'] ?? null;
        if (! is_string($time) || $time === '') {
            return false;
        }

        try {
            new \DateTimeImmutable($time);
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     */
    private function parseSigner(array $manifest): ?SignerInfo
    {
        $info = $manifest['signature_info'] ?? null;
        if (! is_array($info)) {
            return null;
        }

        $issuer = $info['issuer'] ?? null;
        if (! is_string($issuer)) {
            return null;
        }

        $commonName = isset($info['common_name']) && is_string($info['common_name']) ? $info['common_name'] : null;
        $algorithm = isset($info['alg']) && is_string($info['alg']) ? $info['alg'] : null;

        return new SignerInfo($issuer, $commonName, $algorithm);
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     * @return list<array{label: string, data: array<array-key, mixed>}>
     */
    private function parseAssertions(array $manifest): array
    {
        $assertions = $manifest['assertions'] ?? null;
        if (! is_array($assertions)) {
            return [];
        }

        $out = [];
        foreach ($assertions as $assertion) {
            if (! is_array($assertion)) {
                continue;
            }

            $label = $assertion['label'] ?? null;
            if (! is_string($label)) {
                continue;
            }

            $data = $assertion['data'] ?? [];

            $out[] = ['label' => $label, 'data' => is_array($data) ? $data : []];
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $store
     * @return list<string>
     */
    private function validationCodes(array $store): array
    {
        $status = $store['validation_status'] ?? null;
        if (! is_array($status)) {
            return [];
        }

        $codes = [];
        foreach ($status as $entry) {
            if (is_array($entry) && isset($entry['code']) && is_string($entry['code'])) {
                $codes[] = $entry['code'];
            }
        }

        return $codes;
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
