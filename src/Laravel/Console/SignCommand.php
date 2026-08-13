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
use Symfony\Component\Console\Formatter\OutputFormatter;

final class SignCommand extends Command
{
    use InfersMediaType;

    /** @var string */
    protected $signature = '';

    /** @var string */
    protected $description = '';

    public function __construct()
    {
        // Built here rather than declared, so the accepted formats come from
        // InfersMediaType::EXTENSIONS instead of a second list that goes stale
        // (SPEC-032 AC1). "Asset", not "image": thirteen media types, three of
        // them video and three audio, since SPEC-021 and SPEC-023.
        $this->signature = sprintf(
            'content-credentials:sign
        {input : Path to the source asset (%s)}
        {output : Path to write the signed asset}
        {--agent= : Software agent name (required)}
        {--agent-version= : Software agent version}
        {--claim-generator= : Claim generator name}
        {--claim-generator-version= : Claim generator version}',
            $this->supportedExtensions(),
        );

        $this->description = 'Sign an asset as AI-generated (EU AI Act Art. 50) via the signing service.';

        parent::__construct();
    }

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
            $this->error(OutputFormatter::escape($e->getMessage()));

            return self::FAILURE;
        }

        if (! is_file($input)) {
            $this->error('Input file not found: '.OutputFormatter::escape($input));

            return self::FAILURE;
        }

        $agent = $this->stringOption('agent');
        if ($agent === null) {
            $this->error('The --agent option is required.');

            return self::FAILURE;
        }

        $bytes = file_get_contents($input);
        if ($bytes === false) {
            $this->error('Cannot read input file: '.OutputFormatter::escape($input));

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
            $this->error('Signing failed: '.OutputFormatter::escape($e->getMessage()));

            return self::FAILURE;
        }

        if (! AtomicWrite::toPath($output, $signed->bytes)) {
            $this->error('Cannot write signed image to: '.OutputFormatter::escape($output));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Signed %s -> %s (%d bytes)',
            OutputFormatter::escape($input),
            OutputFormatter::escape($output),
            strlen($signed->bytes),
        ));

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
