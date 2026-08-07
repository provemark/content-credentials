<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Manifest;

/**
 * IPTC DigitalSourceType values (SPEC-001, widened by SPEC-026).
 *
 * **This enum is the vocabulary; `ManifestBuilder` is the policy.** The three
 * editing terms describe an operation on an asset that already existed, which
 * C2PA records as `c2pa.opened` + an ingredient + `c2pa.edited`. SPEC-026
 * declared them and refused them, because this package could not build that
 * structure; SPEC-028 builds it, so they are emittable — on `c2pa.edited`, and
 * only when the caller supplies the original asset.
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

    // --- Operations on an existing asset: need a parentOf ingredient --------

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
     * `parentOf` relationship, then `c2pa.edited` carrying the source type.
     *
     * Since SPEC-028 this no longer gates the builder — it decides which action
     * verb is emitted, and becomes {@see Manifest::requiresParentAsset()}, which
     * the Signing layer turns into a hard precondition.
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
