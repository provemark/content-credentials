<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Tests\Unit\Property;

use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;
use Provemark\ContentCredentials\Core\Manifest\MediaType;

/**
 * The shadow model for ManifestBuilder's accumulated state.
 *
 * Deliberately dumb: two nullable slots and last-write-wins. Being obviously
 * correct by inspection is the whole point — it is the oracle the real builder
 * is checked against after every command in a generated sequence.
 *
 * @phpstan-type Entry array{name: string, version: string|null}
 */
final readonly class BuilderModel
{
    /**
     * @param  Entry|null  $softwareAgent
     * @param  Entry|null  $claimGenerator
     */
    private function __construct(
        public MediaType $mediaType,
        public ?array $softwareAgent = null,
        public ?array $claimGenerator = null,
    ) {}

    public static function initial(MediaType $mediaType): self
    {
        return new self($mediaType);
    }

    public function withSoftwareAgent(string $name, ?string $version): self
    {
        return new self($this->mediaType, ['name' => $name, 'version' => $version], $this->claimGenerator);
    }

    public function withClaimGenerator(string $name, ?string $version): self
    {
        return new self($this->mediaType, $this->softwareAgent, ['name' => $name, 'version' => $version]);
    }

    /** Mirrors ManifestBuilder::build()'s contract: an agent is required and must be non-blank. */
    public function canBuild(): bool
    {
        return $this->softwareAgent !== null && trim($this->softwareAgent['name']) !== '';
    }

    /**
     * What Manifest::toArray() must return in this state.
     *
     * @return array<string, mixed>
     */
    public function expectedToArray(): array
    {
        $manifest = [
            'format' => $this->mediaType->value,
            'assertions' => [[
                'label' => 'c2pa.actions.v2',
                'data' => ['actions' => [[
                    'action' => 'c2pa.created',
                    'digitalSourceType' => DigitalSourceType::TrainedAlgorithmicMedia->value,
                    'softwareAgent' => self::entryToArray($this->softwareAgent ?? []),
                ]]],
            ]],
        ];

        if ($this->claimGenerator === null) {
            return $manifest;
        }

        // claim_generator_info comes first in the real builder's output.
        return ['claim_generator_info' => [self::entryToArray($this->claimGenerator)]] + $manifest;
    }

    /**
     * @param  Entry|array{}  $entry
     * @return array{name: string, version?: string}
     */
    private static function entryToArray(array $entry): array
    {
        $name = $entry['name'] ?? '';
        $version = $entry['version'] ?? null;

        return $version === null ? ['name' => $name] : ['name' => $name, 'version' => $version];
    }
}
