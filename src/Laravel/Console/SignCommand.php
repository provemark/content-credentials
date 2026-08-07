<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel\Console;

use Illuminate\Console\Command;
use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;
use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;
use Provemark\ContentCredentials\Laravel\Support\AtomicWrite;

final class SignCommand extends Command
{
    use InfersMediaType;

    protected $signature = 'content-credentials:sign
        {input : Path to the source image (.png/.jpg/.jpeg)}
        {output : Path to write the signed image}
        {--agent= : Software agent name (required)}
        {--agent-version= : Software agent version}
        {--claim-generator= : Claim generator name}
        {--claim-generator-version= : Claim generator version}';

    protected $description = 'Sign an image as AI-generated (EU AI Act Art. 50) via the signing service.';

    public function handle(SignerInterface $signer): int
    {
        $input = $this->argument('input');
        $output = $this->argument('output');
        if (! is_string($input) || ! is_string($output)) {
            $this->error('input and output paths are required.');

            return self::FAILURE;
        }

        try {
            $mediaType = $this->mediaTypeFromPath($input);
        } catch (UnsupportedMediaTypeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! is_file($input)) {
            $this->error("Input file not found: {$input}");

            return self::FAILURE;
        }

        $agent = $this->stringOption('agent');
        if ($agent === null) {
            $this->error('The --agent option is required.');

            return self::FAILURE;
        }

        $bytes = file_get_contents($input);
        if ($bytes === false) {
            $this->error("Cannot read input file: {$input}");

            return self::FAILURE;
        }

        $builder = ManifestBuilder::forAiGenerated($mediaType)
            ->withSoftwareAgent($agent, $this->stringOption('agent-version'));

        $claimGenerator = $this->stringOption('claim-generator');
        if ($claimGenerator !== null) {
            $builder = $builder->withClaimGenerator($claimGenerator, $this->stringOption('claim-generator-version'));
        }

        try {
            $signed = $signer->sign(new Asset($bytes, $mediaType), $builder->build());
        } catch (ContentCredentialsException $e) {
            $this->error('Signing failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! AtomicWrite::toPath($output, $signed->bytes)) {
            $this->error("Cannot write signed image to: {$output}");

            return self::FAILURE;
        }

        $this->info(sprintf('Signed %s -> %s (%d bytes)', $input, $output, strlen($signed->bytes)));

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
