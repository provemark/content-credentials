<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Signing;

use Provemark\ContentCredentials\Core\Manifest\Manifest;
use Provemark\ContentCredentials\Core\Signing\Exception\AssetTooLargeException;
use Provemark\ContentCredentials\Core\Signing\Exception\MediaTypeMismatchException;
use Provemark\ContentCredentials\Core\Signing\Exception\MissingParentAssetException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningFailedException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningResponseException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningTransportException;
use Provemark\ContentCredentials\Core\Signing\Exception\UnexpectedParentAssetException;
use Provemark\ContentCredentials\Core\Support\ResponseBody;
use Provemark\ContentCredentials\Core\Support\ServiceError;
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

    public function sign(Asset $asset, Manifest $manifest, ?Asset $parent = null): SignedAsset
    {
        // AC6: a mismatch is a programming error — fail before any HTTP call.
        // The PARENT's media type is deliberately not compared: editing a PNG
        // and saving as JPEG is ordinary, and c2pa-rs reads the format from the
        // bytes anyway (SPEC-028 OQ3).
        if ($asset->mediaType !== $manifest->mediaType()) {
            throw new MediaTypeMismatchException(sprintf(
                'Asset media type "%s" does not match manifest media type "%s".',
                $asset->mediaType->value,
                $manifest->mediaType()->value,
            ));
        }

        // SPEC-028 AC3/AC4: mandatory-by-manifest, checked before anything is
        // encoded. c2pa-rs enforces neither direction — it signs an edit intent
        // with no ingredient, and it signs a c2pa.created action sitting next to
        // a parentOf ingredient, reporting Valid for both.
        if ($manifest->requiresParentAsset() && $parent === null) {
            throw new MissingParentAssetException(sprintf(
                'This manifest marks the asset as "%s", which C2PA records as an operation on an asset '
                .'that already existed: a c2pa.opened action pointing at a parentOf ingredient, then '
                .'c2pa.edited. Signing it requires the original asset, whose bytes the ingredient hash '
                .'covers. Pass it as the third argument to sign().',
                $this->sourceTypeOf($manifest) ?? 'manipulated',
            ));
        }

        if (! $manifest->requiresParentAsset() && $parent !== null) {
            throw new UnexpectedParentAssetException(
                'A parent asset was supplied for a manifest that marks creation rather than manipulation, '
                .'so there is nothing for it to be the parent of. It would be silently dropped from the '
                .'signed manifest; refusing instead, because the resulting manifest would read as valid '
                .'while omitting the lineage you meant to record.'
            );
        }

        // SPEC-025 AC2: before encoding, not after. The base64 copy and the
        // JSON body together cost roughly 3.7x the asset, so a client that
        // discovers the limit from the service's 413 has already paid for it —
        // or died trying, which is the case this exists for.
        //
        // SPEC-028 OQ5: ONE budget for the pair, not one each. A per-asset limit
        // would pass two assets that each fit and leave the service to 413 the
        // request they add up to — a client-side guard whose whole purpose is to
        // fail before the server does, failing after it.
        $size = strlen($asset->bytes) + ($parent === null ? 0 : strlen($parent->bytes));
        if ($size > $this->config->maxRequestBytes) {
            throw new AssetTooLargeException(sprintf(
                '%s %d bytes, which exceeds the configured limit of %d bytes for a signing request. '
                .'The signing service accepts a body of MAX_BODY_SIZE; raise both if you sign larger assets.',
                $parent === null ? 'Asset is' : 'The asset and its parent together are',
                $size,
                $this->config->maxRequestBytes,
            ));
        }

        $body = json_encode($this->buildPayload($asset, $manifest, $parent), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

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
                ServiceError::fromBody($responseBody),
            ));
        }

        return new SignedAsset($this->decodeSignedContent($responseBody), $asset->mediaType);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Asset $asset, Manifest $manifest, ?Asset $parent): array
    {
        $payload = [
            'content' => base64_encode($asset->bytes),
            'mime_type' => $asset->mediaType->value,
            'extra_assertions' => $manifest->assertions(),
        ];

        // Absent rather than null when there is no parent: every request that
        // exists today stays byte-identical on the wire (SPEC-028 AC1).
        if ($parent !== null) {
            $payload['parent'] = [
                'content' => base64_encode($parent->bytes),
                'mime_type' => $parent->mediaType->value,
            ];
        }

        // D1: send the claim-generator name as creator_name when present; the
        // service composes its own claim_generator_info from it.
        $creatorName = $this->creatorName($manifest);
        if ($creatorName !== null) {
            $payload['creator_name'] = $creatorName;
        }

        return $payload;
    }

    /**
     * The digitalSourceType this manifest claims, for the error message.
     *
     * Read off the manifest rather than kept as a field, because the Signing
     * layer receives a Manifest and never the builder that produced it.
     */
    private function sourceTypeOf(Manifest $manifest): ?string
    {
        $assertions = $manifest->assertions();
        $actions = $assertions[0]['data']['actions'] ?? null;

        if (! is_array($actions) || ! isset($actions[0]) || ! is_array($actions[0])) {
            return null;
        }

        $value = $actions[0]['digitalSourceType'] ?? null;

        return is_string($value) ? $value : null;
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
}
