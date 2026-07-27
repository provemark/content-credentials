<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Laravel\Console;

use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;
use Provemark\ContentCredentials\Core\Manifest\MediaType;

trait InfersMediaType
{
    /**
     * Map a file extension to a supported MediaType (SPEC-006 D4).
     *
     * @throws UnsupportedMediaTypeException
     */
    private function mediaTypeFromPath(string $path): MediaType
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => MediaType::Png,
            'jpg', 'jpeg' => MediaType::Jpeg,
            default => throw new UnsupportedMediaTypeException(sprintf(
                'Unsupported file extension ".%s"; supported: .png, .jpg, .jpeg.',
                $extension,
            )),
        };
    }
}
