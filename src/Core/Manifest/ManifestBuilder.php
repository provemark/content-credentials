<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Manifest;

use Provemark\ContentCredentials\Core\Manifest\Exception\InvalidSoftwareAgentException;
use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedSourceTypeException;

/**
 * Fluent, immutable builder for a claim-v2 manifest that marks an asset as
 * AI-generated (SPEC-001; entry point renamed by SPEC-022).
 *
 * Every with* method returns a NEW instance; the receiver is never mutated.
 * The produced manifest carries exactly one `c2pa.actions.v2` assertion whose
 * first (and only) action is `c2pa.created` with the trainedAlgorithmicMedia
 * digitalSourceType — satisfying both the Article 50 marking and claim-v2
 * well-formedness (docs/c2pa-primer.md §1–2).
 *
 * "Asset", not "image": SPEC-021 widened MediaType to nine types including
 * audio and video, and the manifest is identical for all of them.
 */
final class ManifestBuilder
{
    private function __construct(
        private MediaType $mediaType,
        private DigitalSourceType $sourceType = DigitalSourceType::TrainedAlgorithmicMedia,
        private ?SoftwareAgent $softwareAgent = null,
        private ?string $claimGeneratorName = null,
        private ?string $claimGeneratorVersion = null,
    ) {}

    /**
     * Start a manifest marking an asset of any supported media type as
     * AI-generated (SPEC-001, SPEC-022).
     */
    public static function forAiGenerated(MediaType $type): self
    {
        return self::forSourceType(DigitalSourceType::TrainedAlgorithmicMedia, $type);
    }

    /**
     * A mix of several elements, at least one of which is generative AI
     * (SPEC-026).
     */
    public static function forSynthetic(MediaType $type): self
    {
        return self::forSourceType(DigitalSourceType::CompositeSynthetic, $type);
    }

    /**
     * Purely algorithmic, not based on sampled training data — procedural
     * output rather than generative (SPEC-026).
     */
    public static function forAlgorithmic(MediaType $type): self
    {
        return self::forSourceType(DigitalSourceType::AlgorithmicMedia, $type);
    }

    /**
     * The general form under the named constructors (SPEC-026).
     *
     * @throws UnsupportedSourceTypeException when the source type describes an
     *                                        operation on an existing asset
     */
    public static function forSourceType(DigitalSourceType $source, MediaType $type): self
    {
        if ($source->requiresIngredient()) {
            throw new UnsupportedSourceTypeException(sprintf(
                'The digitalSourceType "%s" describes an operation on an asset that already existed, '
                .'which C2PA records as a c2pa.opened action pointing at an ingredient with a parentOf '
                .'relationship, followed by c2pa.edited. This package builds a single c2pa.created action '
                .'and has no ingredient support, so it cannot express that. Emitting it as c2pa.created '
                .'would be a well-formed manifest making a false claim.',
                $source->value,
            ));
        }

        return new self($type, $source);
    }

    /**
     * @deprecated since 0.8.0 — use {@see forAiGenerated()} instead. The name
     *             predates SPEC-021, which added audio and video media types,
     *             so it now reads as a contradiction for MediaType::Mp4 and
     *             friends.
     *
     * This alias is **kept indefinitely** and there is no removal planned: it
     * costs three lines, and deleting it would break working code for a purely
     * cosmetic gain (SPEC-022, settled at approval). It raises no runtime
     * deprecation for the same reason — see SPEC-022 AC4.
     */
    public static function forAiGeneratedImage(MediaType $type): self
    {
        return self::forAiGenerated($type);
    }

    public function withSoftwareAgent(string $name, ?string $version = null): self
    {
        return new self(
            $this->mediaType,
            $this->sourceType,
            new SoftwareAgent($name, $version),
            $this->claimGeneratorName,
            $this->claimGeneratorVersion,
        );
    }

    public function withClaimGenerator(string $name, ?string $version = null): self
    {
        return new self(
            $this->mediaType,
            $this->sourceType,
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
                    'digitalSourceType' => $this->sourceType->value,
                    'softwareAgent' => $agent->toArray(),
                ]],
            ],
        ]];

        return new Manifest($this->mediaType, $assertions, $this->claimGeneratorInfo());
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
