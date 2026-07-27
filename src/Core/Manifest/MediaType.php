<?php

declare(strict_types=1);

namespace ContentCredentials\Core\Manifest;

use ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;

/** Supported asset formats for v1 (PNG and JPEG only — SPEC-001). */
enum MediaType: string
{
    case Png = 'image/png';
    case Jpeg = 'image/jpeg';

    /**
     * Resolve a MIME string to a supported media type.
     *
     * Per SPEC-001 D2, the input is trimmed, lowercased and stripped of any
     * `;`-parameters (e.g. `image/jpeg; charset=…`) before an exact match.
     *
     * @throws UnsupportedMediaTypeException when the type is not supported
     */
    public static function fromMimeType(string $mime): self
    {
        $normalized = strtolower(trim($mime));

        $semicolon = strpos($normalized, ';');
        if ($semicolon !== false) {
            $normalized = rtrim(substr($normalized, 0, $semicolon));
        }

        return self::tryFrom($normalized) ?? throw new UnsupportedMediaTypeException(sprintf(
            'Unsupported media type "%s". Supported types: %s, %s.',
            trim($mime),
            self::Png->value,
            self::Jpeg->value,
        ));
    }
}
