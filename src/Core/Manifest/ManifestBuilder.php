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
 * The produced manifest carries exactly one `c2pa.actions.v2` assertion with one
 * action: `c2pa.created` for the source types that describe how an asset came
 * into being, or `c2pa.edited` for those that describe an operation on one that
 * already existed (SPEC-028). The created form satisfies both the Article 50
 * marking and claim-v2 well-formedness (docs/c2pa-primer.md §1–2); the edited
 * form is completed by the signing service, which adds the `c2pa.opened` action
 * and the ingredient it references.
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
     * Content manipulated with a generative AI model — inpainting, outpainting,
     * AI retouching. The Article 50(2) editing case (SPEC-028).
     *
     * Signing this manifest REQUIRES the original asset; see
     * {@see Manifest::requiresParentAsset()}.
     */
    public static function forAiManipulated(MediaType $type): self
    {
        return self::forSourceType(DigitalSourceType::CompositeWithTrainedAlgorithmicMedia, $type);
    }

    /**
     * The general form under the named constructors (SPEC-026).
     *
     * SPEC-026 refused the three editing source types here, because this package
     * could not build the ingredient structure they need. SPEC-028 builds it, so
     * the refusal is gone and this method no longer throws.
     * {@see UnsupportedSourceTypeException} is kept as public API that nothing
     * raises — deleting it would break code that catches it, for no gain.
     */
    public static function forSourceType(DigitalSourceType $source, MediaType $type): self
    {
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

        $needsParent = $this->sourceType->requiresIngredient();

        // SPEC-028: an editing source type rides on `c2pa.edited`, never on
        // `c2pa.created` — the latter would claim the asset was created by an
        // operation that by definition acts on one that already existed, and
        // c2pa-rs would sign that without complaint (measured; NOTES Step 35).
        //
        // We emit no `c2pa.opened` action of our own. That action carries a hash
        // over the ingredient assertion the signing service builds, so supplying
        // it from here produces `assertion.action.ingredientMismatch` and an
        // Invalid manifest. c2pa-rs inserts it into this same assertion.
        $assertions = [[
            'label' => 'c2pa.actions.v2',
            'data' => [
                'actions' => [[
                    'action' => $needsParent ? 'c2pa.edited' : 'c2pa.created',
                    'digitalSourceType' => $this->sourceType->value,
                    'softwareAgent' => $agent->toArray(),
                ]],
            ],
        ]];

        return new Manifest($this->mediaType, $assertions, $this->claimGeneratorInfo(), $needsParent);
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
