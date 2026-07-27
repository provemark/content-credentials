<?php

declare(strict_types=1);

namespace ContentCredentials\Core\Manifest;

/**
 * The tool that produced the content, recorded on the c2pa.created action.
 *
 * Intentionally a plain value object: it does not validate its name. Whether a
 * software agent is present and non-blank is enforced by ManifestBuilder::build()
 * (SPEC-001 AC4/D3), so that the error surfaces at build time.
 */
final readonly class SoftwareAgent
{
    public function __construct(
        public string $name,
        public ?string $version = null,
    ) {}

    /**
     * @return array{name: string, version?: string}
     */
    public function toArray(): array
    {
        if ($this->version === null) {
            return ['name' => $this->name];
        }

        return ['name' => $this->name, 'version' => $this->version];
    }
}
