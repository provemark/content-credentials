<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading;

use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;

/**
 * Immutable view over the active manifest of a c2pa-rs manifest store, answering
 * the reading questions of SPEC-003. Absence of C2PA data is represented by an
 * empty report (no active manifest), not an error.
 *
 * @phpstan-type AssertionShape array{label: string, data: array<array-key, mixed>}
 */
final readonly class ManifestReport
{
    /**
     * @param  list<AssertionShape>  $assertions
     * @param  list<string>  $validationStatusCodes
     */
    public function __construct(
        private ?string $activeManifestLabel,
        private ?SignerInfo $signer,
        private array $assertions,
        private array $validationStatusCodes,
        private ?ValidationState $validationState = null,
        private bool $hasTimestamp = false,
    ) {}

    public function hasManifest(): bool
    {
        return $this->activeManifestLabel !== null;
    }

    public function activeManifestLabel(): ?string
    {
        return $this->activeManifestLabel;
    }

    public function signer(): ?SignerInfo
    {
        return $this->signer;
    }

    /**
     * @return list<AssertionShape>
     */
    public function assertions(): array
    {
        return $this->assertions;
    }

    /**
     * @return list<string>
     */
    public function validationStatusCodes(): array
    {
        return $this->validationStatusCodes;
    }

    /**
     * True only when the reader positively established trust: the c2pa-rs
     * `validation_state` is `Trusted`, meaning integrity passed AND the signing
     * certificate chained to a configured trust list (SPEC-013).
     *
     * **Absence of evidence is not trust.** A report with no manifest, an
     * absent or unrecognised state, or any credential failure yields false —
     * the previous definition ("true unless `signingCredential.untrusted` was
     * reported") answered true for all of those, including an asset carrying no
     * C2PA data at all and a revoked certificate.
     *
     * Trust depends on configuration this library cannot see: the signing
     * service only reports `Trusted` when it is started with trust settings
     * (`CONTENTAUTH_TRUST_SETTINGS`, SPEC-014). Without them this is false **by
     * design, not by failure**, and {@see isSignatureValid()} is the meaningful
     * verdict. `bin/verify.sh` verifies trust authoritatively with c2patool.
     */
    public function isTrusted(): bool
    {
        return $this->validationState === ValidationState::Trusted;
    }

    /** The c2pa-rs `validation_state` verdict, or null if absent/unrecognised (SPEC-005). */
    public function validationState(): ?ValidationState
    {
        return $this->validationState;
    }

    /**
     * True iff the C2PA validation state is Valid or Trusted — i.e. the claim
     * signature and asset-integrity checks passed (trust aside). A missing or
     * unrecognised state yields false; this never asserts validity it cannot
     * confirm (SPEC-005 D2). Distinct from {@see isTrusted()}.
     */
    public function isSignatureValid(): bool
    {
        return in_array($this->validationState, [ValidationState::Valid, ValidationState::Trusted], true);
    }

    /**
     * True when the active manifest's signature carries an RFC 3161 trusted
     * timestamp (a present, structurally valid `signature_info.time`). Trust of
     * the timestamp authority's own certificate is a separate concern (SPEC-007
     * D3). Distinct from signature validity and signer trust.
     */
    public function hasTimestamp(): bool
    {
        return $this->hasTimestamp;
    }

    /**
     * True if the active manifest carries the trainedAlgorithmicMedia marking.
     *
     * This reports what the manifest **claims**, not a verdict on it: the
     * marking is read straight out of the assertions whatever the validation
     * outcome was, so a tampered or unverifiable manifest still answers true.
     * Before acting on it, gate on `isSignatureValid()` — or use
     * {@see isVerifiedAiGenerated()}, which does that for you (SPEC-013).
     */
    public function isAiGenerated(): bool
    {
        return in_array(
            DigitalSourceType::TrainedAlgorithmicMedia->value,
            $this->digitalSourceTypes(),
            true,
        );
    }

    /**
     * True when any generative AI model was involved (SPEC-026 AC7).
     *
     * Wider than {@see isAiGenerated()}, which means exactly
     * `trainedAlgorithmicMedia` and stays that way — it gates Article 50
     * decisions in code already written against it, and silently changing what
     * it answers is the failure SPEC-013 exists to remember.
     *
     * False for `algorithmicMedia`, and that is the point of that term:
     * synthetic, but no model and no training data.
     */
    public function involvesGenerativeAi(): bool
    {
        foreach ($this->digitalSourceTypes() as $value) {
            if (DigitalSourceType::tryFrom($value)?->involvesGenerativeAi() === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the asset is marked as AI-generated **and** that marking came
     * from a manifest whose signature checked out — the check to reach for when
     * a decision hangs on the Article 50 marking (SPEC-013 AC6).
     *
     * Deliberately does NOT require {@see isTrusted()}. Trust depends on
     * deployment configuration this library cannot see, so including it would
     * make this false in every deployment that has not configured trust
     * anchors, and callers would learn to avoid it. Where trust matters, add
     * `&& $report->isTrusted()` explicitly.
     */
    public function isVerifiedAiGenerated(): bool
    {
        return $this->isSignatureValid() && $this->isAiGenerated();
    }

    /**
     * Distinct digitalSourceType URIs across the active manifest's actions
     * assertions (any label starting `c2pa.actions`, per D5).
     *
     * @return list<string>
     */
    public function digitalSourceTypes(): array
    {
        $types = [];

        foreach ($this->assertions as $assertion) {
            if (! str_starts_with($assertion['label'], 'c2pa.actions')) {
                continue;
            }

            $actions = $assertion['data']['actions'] ?? null;
            if (! is_array($actions)) {
                continue;
            }

            foreach ($actions as $action) {
                if (! is_array($action)) {
                    continue;
                }

                $digitalSourceType = $action['digitalSourceType'] ?? null;
                if (is_string($digitalSourceType) && ! in_array($digitalSourceType, $types, true)) {
                    $types[] = $digitalSourceType;
                }
            }
        }

        return $types;
    }
}
