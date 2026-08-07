<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Manifest;

/**
 * IPTC DigitalSourceType values (SPEC-001, widened by SPEC-026).
 *
 * **This enum is the vocabulary; `ManifestBuilder` is the policy.** Not every
 * case here can be emitted: the three editing terms describe an operation on an
 * asset that already existed, which C2PA records as `c2pa.opened` + an ingredient
 * + `c2pa.edited`. This package cannot build that, so the builder refuses them —
 * and it can only refuse what it can be handed, which is why they are declared.
 *
 * Two kinds of term are deliberately absent:
 *
 * - **Authenticity claims** — `digitalCapture`, `computationalCapture`,
 *   `digitalCreation`, the film and print terms. A PHP application receives
 *   bytes; whatever it asserts about a physical origin it is repeating, and a
 *   C2PA assertion is signed, which turns hearsay into attestation (SPEC-026).
 * - **Retired terms** — `minorHumanEdits` and `digitalArt` (retired 2024-09-17),
 *   `softwareImage` (2022-06-14). Older examples on the web still use them.
 *
 * ⚠️ The URIs below are transcribed from `cv.iptc.org/newscodes/digitalsourcetype/`
 * and from nowhere else. C2PA's own Implementation Guidance misspells one of them
 * as `compositedWithTrainedAlgorithmicMedia`, which IPTC has never registered.
 */
enum DigitalSourceType: string
{
    // --- Emittable: how an asset came into being ---------------------------

    /** Created algorithmically by an AI model trained on captured content. */
    case TrainedAlgorithmicMedia = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

    /** A mix of several elements, at least one of which is generative AI. */
    case CompositeSynthetic = 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeSynthetic';

    /** Purely algorithmic, not based on any sampled training data. */
    case AlgorithmicMedia = 'http://cv.iptc.org/newscodes/digitalsourcetype/algorithmicMedia';

    // --- Declared, refused by the builder: operations on an existing asset --

    /** Augmentation or enhancement USING a generative AI model (inpainting). */
    case CompositeWithTrainedAlgorithmicMedia = 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia';

    /** Modification by algorithm without changing the main content. */
    case AlgorithmicallyEnhanced = 'http://cv.iptc.org/newscodes/digitalsourcetype/algorithmicallyEnhanced';

    /** Augmentation or enhancement by humans using non-generative tools. */
    case HumanEdits = 'http://cv.iptc.org/newscodes/digitalsourcetype/humanEdits';

    /**
     * Whether this term describes an operation on an asset that already existed.
     *
     * Such a manifest needs `c2pa.opened` pointing at an ingredient with a
     * `parentOf` relationship, then `c2pa.edited` carrying the source type — not
     * the single `c2pa.created` action this package builds.
     */
    public function requiresIngredient(): bool
    {
        return match ($this) {
            self::CompositeWithTrainedAlgorithmicMedia,
            self::AlgorithmicallyEnhanced,
            self::HumanEdits => true,
            default => false,
        };
    }

    /**
     * Whether a generative AI model was involved.
     *
     * False for {@see self::AlgorithmicMedia}, and that is the point of the
     * term: synthetic, but no model and no training data.
     */
    public function involvesGenerativeAi(): bool
    {
        return match ($this) {
            self::TrainedAlgorithmicMedia,
            self::CompositeSynthetic,
            self::CompositeWithTrainedAlgorithmicMedia => true,
            default => false,
        };
    }
}
