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
    private const UNTRUSTED_CODE = 'signingCredential.untrusted';

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

    /** True unless the reader reported a signingCredential.untrusted code (D3). */
    public function isTrusted(): bool
    {
        return ! in_array(self::UNTRUSTED_CODE, $this->validationStatusCodes, true);
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

    /** True if the active manifest carries the trainedAlgorithmicMedia marking. */
    public function isAiGenerated(): bool
    {
        return in_array(
            DigitalSourceType::TrainedAlgorithmicMedia->value,
            $this->digitalSourceTypes(),
            true,
        );
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
