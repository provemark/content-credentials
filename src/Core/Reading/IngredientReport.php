<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading;

/**
 * One ingredient of a manifest, with a verdict of its own (SPEC-045).
 *
 * Built by ManifestStoreParser from the store every reader returns. The store
 * verdict ({@see ManifestReport::isTrusted()}) does not cover an ingredient:
 * only the active manifest's signer can make it `Trusted`, and what the writer
 * recorded about an ingredient is dropped from it. So an ingredient carries its
 * own, computed by the rule in {@see isTrusted()}.
 *
 * Reports what a manifest claims about where the asset came from. What a
 * caller does with that — which relationship counts, how far down — is the
 * caller's policy, not this library's.
 */
final readonly class IngredientReport
{
    /**
     * @param  list<string>  $digitalSourceTypes
     * @param  list<IngredientReport>  $ingredients
     */
    public function __construct(
        private ?string $relationship,
        private bool $hasManifest,
        private array $digitalSourceTypes,
        private bool $trusted,
        private array $ingredients,
    ) {}

    /** `parentOf`, `componentOf`, `inputTo` … verbatim; null when absent or not a string. */
    public function relationship(): ?string
    {
        return $this->relationship;
    }

    /** Whether the ingredient's manifest is in the store. */
    public function hasManifest(): bool
    {
        return $this->hasManifest;
    }

    /**
     * Distinct digitalSourceType URIs across the ingredient manifest's actions,
     * the same way {@see ManifestReport::digitalSourceTypes()} reads the active
     * manifest. Empty without a manifest.
     *
     * @return list<string>
     */
    public function digitalSourceTypes(): array
    {
        return $this->digitalSourceTypes;
    }

    /**
     * True only on the reader's own evidence (SPEC-045 AC3): its validation of
     * this ingredient says `signingCredential.trusted` and holds no failure, the
     * claim signature is `claimSignature.validated` there or in what the writer
     * recorded, and the writer recorded no failure besides
     * `signingCredential.untrusted`, which the reader re-evaluated against its
     * own anchors.
     *
     * `signingCredential.trusted` alone is not enough: c2pa-rs's `CIE-sig-CA.jpg`
     * has an ingredient whose signature does not verify, recorded by the writer,
     * and the reader's delta for it still says trusted.
     *
     * **Absence of evidence is not trust**: no manifest, no delta, or a reader
     * run without anchors yields false.
     */
    public function isTrusted(): bool
    {
        return $this->trusted;
    }

    /**
     * This ingredient's own ingredients, bounded against hostile stores
     * (SPEC-045 AC5): a cycle, depth 8, or 64 ingredients in the whole report.
     *
     * @return list<IngredientReport>
     */
    public function ingredients(): array
    {
        return $this->ingredients;
    }
}
