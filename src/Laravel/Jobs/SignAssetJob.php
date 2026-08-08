<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel\Jobs;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\Exception\AssetTooLargeException;
use Provemark\ContentCredentials\Core\Signing\Exception\MediaTypeMismatchException;
use Provemark\ContentCredentials\Core\Signing\Exception\MissingParentAssetException;
use Provemark\ContentCredentials\Core\Signing\Exception\UnexpectedParentAssetException;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;
use Provemark\ContentCredentials\Laravel\Events\AssetSigned;
use Provemark\ContentCredentials\Laravel\Support\AtomicWrite;

/**
 * Queued signing of a local file: reads the source, builds the AI-generated
 * manifest, signs it via the service, writes the signed file, and dispatches an
 * AssetSigned event. Bounded retries with backoff, because signing is a network
 * call (SPEC-006).
 */
final class SignAssetJob implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Failures that cannot succeed on a second attempt (SPEC-032 AC5).
     *
     * Every one of these is a property of the request, not of the moment: too
     * large stays too large, a media-type mismatch is a programming error, and a
     * manifest that needs a parent will still need one in five minutes. Retrying
     * them sleeps up to six minutes per asset to fail identically three times.
     *
     * Everything else — transport failures, 429, 5xx — is still retried with the
     * backoff below. That is the criterion's other half, and it has its own test:
     * a job that retried nothing would satisfy this list just as well.
     *
     * @var list<class-string<\Throwable>>
     */
    private const NOT_RETRYABLE = [
        AssetTooLargeException::class,
        MediaTypeMismatchException::class,
        MissingParentAssetException::class,
        UnexpectedParentAssetException::class,
    ];

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

        $builder = ManifestBuilder::forAiGenerated($this->mediaType)
            ->withSoftwareAgent($this->softwareAgent, $this->softwareAgentVersion);

        if ($this->claimGenerator !== null) {
            $builder = $builder->withClaimGenerator($this->claimGenerator, $this->claimGeneratorVersion);
        }

        try {
            $signed = $signer->sign(new Asset($bytes, $this->mediaType), $builder->build());
        } catch (\Throwable $e) {
            if (! in_array($e::class, self::NOT_RETRYABLE, true)) {
                throw $e;
            }

            // Outside a queue there is nothing to mark failed, and swallowing
            // the exception there would turn a visible error into silence.
            if ($this->job === null) {
                throw $e;
            }

            $this->fail($e);

            return;
        }

        // Only write once signing succeeded — a failure leaves no partial file.
        // A failed write must surface, not silently "succeed" (no AssetSigned).
        if (! AtomicWrite::toPath($this->destinationPath, $signed->bytes)) {
            throw new \RuntimeException("Cannot write signed file: {$this->destinationPath}");
        }

        $events?->dispatch(new AssetSigned($this->destinationPath));
    }
}
