<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Manifest;

/**
 * Immutable claim-v2 manifest definition. Core emits arrays only; serialization
 * to the wire (and any HTTP transport to the signing service) belongs to the
 * Signing layer (SPEC-001 D4).
 *
 * @phpstan-type AssertionShape array{label: string, data: array<string, mixed>}
 * @phpstan-type ClaimGeneratorShape array{name: string, version?: string}
 */
final readonly class Manifest
{
    /**
     * @param  list<AssertionShape>  $assertions
     * @param  list<ClaimGeneratorShape>  $claimGeneratorInfo
     */
    public function __construct(
        private MediaType $mediaType,
        private array $assertions,
        private array $claimGeneratorInfo = [],
    ) {}

    /** The asset format this manifest is built for (SPEC-001 amendment via SPEC-002). */
    public function mediaType(): MediaType
    {
        return $this->mediaType;
    }

    /**
     * The JSON-ready manifest definition. `claim_generator_info` is omitted
     * entirely when unset (absent, not null).
     *
     * @return array{
     *     claim_generator_info?: list<ClaimGeneratorShape>,
     *     format: string,
     *     assertions: list<AssertionShape>
     * }
     */
    public function toArray(): array
    {
        if ($this->claimGeneratorInfo === []) {
            return [
                'format' => $this->mediaType->value,
                'assertions' => $this->assertions,
            ];
        }

        return [
            'claim_generator_info' => $this->claimGeneratorInfo,
            'format' => $this->mediaType->value,
            'assertions' => $this->assertions,
        ];
    }

    /**
     * Just the assertions array — maps to the service `/v1/sign`
     * `extra_assertions` field in the Signing layer.
     *
     * @return list<AssertionShape>
     */
    public function assertions(): array
    {
        return $this->assertions;
    }
}
