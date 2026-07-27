<?php

declare(strict_types=1);

namespace ContentCredentials\Laravel\Console;

use ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;
use ContentCredentials\Core\Reading\ReaderInterface;
use ContentCredentials\Core\Signing\Asset;
use Illuminate\Console\Command;

final class ReadCommand extends Command
{
    use InfersMediaType;

    protected $signature = 'content-credentials:read {file : Path to the signed image (.png/.jpg/.jpeg)}';

    protected $description = 'Read and report the C2PA credential of an image.';

    public function handle(ReaderInterface $reader): int
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

        return self::SUCCESS;
    }
}
