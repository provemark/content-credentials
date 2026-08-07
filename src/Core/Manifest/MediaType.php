<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Manifest;

use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;

/**
 * Asset formats this package will sign and read (SPEC-001, widened by SPEC-021).
 *
 * PNG and JPEG were never a c2pa-rs limitation — they were the scope of the
 * spike. Every type below was measured signing, reading back `Valid` and
 * keeping the Article 50 marking (SPEC-021).
 *
 * `video/mp4` is accepted as a container, not as support for real video: the
 * 20 MB body limit (SPEC-017) and the ~7× memory multiplier bound it to small
 * files, because the transport is base64 in one HTTP body. See the README.
 *
 * This list has two counterparts that must not drift from it: `SUPPORTED_MIME`
 * in `service/server.js` (compared against a running deployment by SPEC-021
 * AC2) and the extension map in `Laravel\Console\InfersMediaType` (AC8).
 */
enum MediaType: string
{
    case Png = 'image/png';
    case Jpeg = 'image/jpeg';
    case Webp = 'image/webp';
    case Avif = 'image/avif';
    case Gif = 'image/gif';
    case Tiff = 'image/tiff';
    case Wav = 'audio/wav';
    case Mp3 = 'audio/mpeg';
    case Mp4 = 'video/mp4';

    /**
     * Accepted spellings that are not the registered type (SPEC-021).
     *
     * `audio/mpeg` is the registered type; `audio/mp3` is what a good deal of
     * software emits. An alias is an accepted input only — the value carried
     * into the manifest and to the service is always the registered type.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'audio/mp3' => 'audio/mpeg',
    ];

    /**
     * Resolve a MIME string to a supported media type.
     *
     * Per SPEC-001 D2, the input is trimmed, lowercased and stripped of any
     * `;`-parameters (e.g. `image/jpeg; charset=…`) before an exact match;
     * SPEC-021 then maps the accepted aliases onto their registered type.
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

        $normalized = self::ALIASES[$normalized] ?? $normalized;

        return self::tryFrom($normalized) ?? throw new UnsupportedMediaTypeException(sprintf(
            'Unsupported media type "%s". Supported types: %s.',
            trim($mime),
            // Derived from the enum, never restated: a message naming two types
            // while the enum holds nine is exactly the staleness SPEC-021 exists
            // to remove.
            implode(', ', array_map(static fn (self $type): string => $type->value, self::cases())),
        ));
    }
}
