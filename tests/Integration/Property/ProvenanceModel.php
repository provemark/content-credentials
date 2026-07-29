<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Tests\Integration\Property;

use Provemark\ContentCredentials\Core\Manifest\MediaType;

/**
 * Shadow model of an asset's provenance chain.
 *
 * The real state lives in the asset bytes and in c2pa-rs; this is the simple,
 * obviously-correct account of what should be true of it. Signing an already
 * signed asset appends to the chain: a new active manifest on top, earlier ones
 * demoted to ingredients.
 *
 * The model deliberately tracks only what ManifestReport can actually observe.
 * It does not model ingredients, because the report exposes no view of them —
 * modelling what you cannot check produces tests that lie.
 */
final readonly class ProvenanceModel
{
    public function __construct(
        public MediaType $mediaType,
        public int $signCount = 0,
    ) {}

    public static function unsigned(MediaType $mediaType): self
    {
        return new self($mediaType);
    }

    public function afterSign(): self
    {
        return new self($this->mediaType, $this->signCount + 1);
    }

    /** An asset carries a manifest exactly once it has been signed at least once. */
    public function expectsManifest(): bool
    {
        return $this->signCount > 0;
    }

    /**
     * Every signing in this suite applies an AI-generated manifest, so once
     * signed the Article 50 marking must be present — and must STAY present
     * however many further signings happen. This is the invariant the whole
     * library exists to uphold.
     */
    public function expectsAiMarking(): bool
    {
        return $this->signCount > 0;
    }
}
