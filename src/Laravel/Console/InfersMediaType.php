<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel\Console;

use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;
use Provemark\ContentCredentials\Core\Manifest\MediaType;

trait InfersMediaType
{
    /**
     * Extensions the commands accept, in the order MediaType declares them
     * (SPEC-006 D4, widened by SPEC-021 AC8).
     *
     * This is the third list of what this package supports, after the
     * `MediaType` enum and the service's `SUPPORTED_MIME`. Every enum case must
     * be reachable from at least one extension here; the SPEC-021 test derives
     * that expectation from the enum rather than restating this map.
     */
    private const EXTENSIONS = [
        'png' => MediaType::Png,
        'jpg' => MediaType::Jpeg,
        'jpeg' => MediaType::Jpeg,
        'webp' => MediaType::Webp,
        'avif' => MediaType::Avif,
        'gif' => MediaType::Gif,
        'tif' => MediaType::Tiff,
        'tiff' => MediaType::Tiff,
        'wav' => MediaType::Wav,
        'mp3' => MediaType::Mp3,
        'mp4' => MediaType::Mp4,
    ];

    /**
     * Map a file extension to a supported MediaType (SPEC-006 D4).
     *
     * @throws UnsupportedMediaTypeException
     */
    private function mediaTypeFromPath(string $path): MediaType
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::EXTENSIONS[$extension] ?? throw new UnsupportedMediaTypeException(sprintf(
            'Unsupported file extension ".%s"; supported: %s.',
            $extension,
            implode(', ', array_map(
                static fn (string $known): string => ".{$known}",
                array_keys(self::EXTENSIONS),
            )),
        ));
    }
}
