<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel\Jobs;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;
use Provemark\ContentCredentials\Laravel\Events\AssetSigned;

/**
 * Queued signing of a local file: reads the source, builds the AI-generated
 * manifest, signs it via the service, writes the signed file, and dispatches an
 * AssetSigned event. Bounded retries with backoff, because signing is a network
 * call (SPEC-006).
 */
final class SignAssetJob implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(
        private string $sourcePath,
        private string $destinationPath,
        private MediaType $mediaType,
        private string $softwareAgent,
        private ?string $softwareAgentVersion = null,
        private ?string $claimGenerator = null,
        private ?string $claimGeneratorVersion = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(SignerInterface $signer, ?Dispatcher $events = null): void
    {
        $bytes = file_get_contents($this->sourcePath);
        if ($bytes === false) {
            throw new \RuntimeException("Cannot read source file: {$this->sourcePath}");
        }

        $builder = ManifestBuilder::forAiGeneratedImage($this->mediaType)
            ->withSoftwareAgent($this->softwareAgent, $this->softwareAgentVersion);

        if ($this->claimGenerator !== null) {
            $builder = $builder->withClaimGenerator($this->claimGenerator, $this->claimGeneratorVersion);
        }

        $signed = $signer->sign(new Asset($bytes, $this->mediaType), $builder->build());

        // Only write once signing succeeded — a failure leaves no partial file.
        file_put_contents($this->destinationPath, $signed->bytes);

        $events?->dispatch(new AssetSigned($this->destinationPath));
    }
}
