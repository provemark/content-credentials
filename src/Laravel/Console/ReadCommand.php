<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel\Console;

use Illuminate\Console\Command;
use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;
use Provemark\ContentCredentials\Core\Reading\ReaderInterface;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Laravel\ReaderFactory;

final class ReadCommand extends Command
{
    use InfersMediaType;

    /** @var string */
    protected $signature = '';

    /** @var string */
    protected $description = '';

    public function __construct()
    {
        // Derived from InfersMediaType::EXTENSIONS rather than restated
        // (SPEC-032 AC1); "asset", not "image", since SPEC-021 and SPEC-023.
        $this->signature = sprintf(
            'content-credentials:read {file : Path to the signed asset (%s)}',
            $this->supportedExtensions(),
        );

        $this->description = 'Read and report the C2PA credential of an asset.';

        parent::__construct();
    }

    public function handle(ReaderInterface $reader, ReaderFactory $factory): int
    {
        $file = $this->argument('file');
        if (! is_string($file)) {
            $this->error('file path is required.');

            return self::FAILURE;
        }

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        try {
            $mediaType = $this->mediaTypeFromPath($file);
        } catch (UnsupportedMediaTypeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $bytes = file_get_contents($file);
        if ($bytes === false) {
            $this->error("Cannot read file: {$file}");

            return self::FAILURE;
        }

        $report = $reader->read(new Asset($bytes, $mediaType));
        $signer = $report->signer();

        // SPEC-020 AC6: two c2pa-rs versions are in play — 0.89.0 in the
        // extension, 0.90.5 in the service — so which engine produced this report
        // has to be visible where someone is already standing when they wonder.
        $this->line('reader             : '.$factory->mode());
        $this->line('hasManifest        : '.($report->hasManifest() ? 'true' : 'false'));
        $this->line('isAiGenerated      : '.($report->isAiGenerated() ? 'true' : 'false'));
        $this->line('digitalSourceTypes : '.(implode(', ', $report->digitalSourceTypes()) ?: '(none)'));
        $this->line('signer             : '.($signer !== null
            ? $signer->issuer.($signer->commonName !== null ? ' / CN='.$signer->commonName : '')
            : '(none)'));
        $state = $report->validationState();
        $this->line('validationState    : '.($state !== null ? $state->value : '(none)'));
        $this->line('isSignatureValid   : '.($report->isSignatureValid() ? 'true' : 'false'));
        $this->line('isTrusted          : '.($report->isTrusted() ? 'true' : 'false'));
        // The last of the four questions asked about somebody else's asset:
        // who signed it, is the signature intact, is the signer trusted, and is
        // the *time* provable. For our own output SPEC-007 already answers this
        // by failing closed — a service with a TSA configured either timestamps
        // or refuses to sign — but that guarantee does not travel with a file
        // received from elsewhere, which is exactly what this command is for.
        $this->line('hasTimestamp       : '.($report->hasTimestamp() ? 'true' : 'false'));

        return self::SUCCESS;
    }
}
