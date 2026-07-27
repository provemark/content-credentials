<?php

declare(strict_types=1);

namespace ContentCredentials\Core\Manifest;

use ContentCredentials\Core\Manifest\Exception\InvalidSoftwareAgentException;

/**
 * Fluent, immutable builder for a claim-v2 manifest that marks an image as
 * AI-generated (SPEC-001).
 *
 * Every with* method returns a NEW instance; the receiver is never mutated.
 * The produced manifest carries exactly one `c2pa.actions.v2` assertion whose
 * first (and only) action is `c2pa.created` with the trainedAlgorithmicMedia
 * digitalSourceType — satisfying both the Article 50 marking and claim-v2
 * well-formedness (docs/c2pa-primer.md §1–2).
 */
final class ManifestBuilder
{
    private function __construct(
        private MediaType $mediaType,
        private ?SoftwareAgent $softwareAgent = null,
        private ?string $claimGeneratorName = null,
        private ?string $claimGeneratorVersion = null,
    ) {}

    public static function forAiGeneratedImage(MediaType $type): self
    {
        return new self($type);
    }

    public function withSoftwareAgent(string $name, ?string $version = null): self
    {
        return new self(
            $this->mediaType,
            new SoftwareAgent($name, $version),
            $this->claimGeneratorName,
            $this->claimGeneratorVersion,
        );
    }

    public function withClaimGenerator(string $name, ?string $version = null): self
    {
        return new self(
            $this->mediaType,
            $this->softwareAgent,
            $name,
            $version,
        );
    }

    /**
     * @throws InvalidSoftwareAgentException when no software agent is set, or its
     *                                       name is empty/whitespace (AC4/D3)
     */
    public function build(): Manifest
    {
        $agent = $this->softwareAgent;

        if ($agent === null) {
            throw new InvalidSoftwareAgentException(
                'A software agent is required to build an AI-generated manifest; call withSoftwareAgent() first.'
            );
        }

        if (trim($agent->name) === '') {
            throw new InvalidSoftwareAgentException('The software-agent name must not be empty.');
        }

        $assertions = [[
            'label' => 'c2pa.actions.v2',
            'data' => [
                'actions' => [[
                    'action' => 'c2pa.created',
                    'digitalSourceType' => DigitalSourceType::TrainedAlgorithmicMedia->value,
                    'softwareAgent' => $agent->toArray(),
                ]],
            ],
        ]];

        return new Manifest($this->mediaType->value, $assertions, $this->claimGeneratorInfo());
    }

    /**
     * @return list<array{name: string, version?: string}>
     */
    private function claimGeneratorInfo(): array
    {
        if ($this->claimGeneratorName === null) {
            return [];
        }

        if ($this->claimGeneratorVersion === null) {
            return [['name' => $this->claimGeneratorName]];
        }

        return [['name' => $this->claimGeneratorName, 'version' => $this->claimGeneratorVersion]];
    }
}
