<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;
use Provemark\ContentCredentials\Core\Manifest\MediaType;

/**
 * SPEC-023 — the four remaining formats that actually work.
 *
 * SPEC-021 left six formats out as unmeasured. Four of them — SVG, MOV, AVI and
 * FLAC — were measured working in both engines (NOTES Step 27); PDF, WEBM, DNG
 * and JPEG XL stay out, each for its own reason.
 *
 * AC1, AC3 and the service half of AC6 need a running service and live in
 * tests/Integration/RemainingMediaTypesTest.php.
 *
 * @see specs/SPEC-023-measured-remaining-media-types.md
 */

// --- AC2: the enum is the source, and it holds all thirteen ----------------

it('declares all thirteen measured media types', function () {
    // Whole-set equality, grouped image / audio / video. SPEC-021's own test
    // now asserts only that its nine are still present, because this is the
    // list that is exhaustive.
    expect(array_map(fn (MediaType $t): string => $t->value, MediaType::cases()))->toBe([
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/avif',
        'image/gif',
        'image/tiff',
        'image/svg+xml',
        'audio/wav',
        'audio/mpeg',
        'audio/flac',
        'video/mp4',
        'video/quicktime',
        'video/x-msvideo',
    ]);
})->group('SPEC-023');

it('resolves each added type from its own mime string', function (string $mime, MediaType $expected) {
    expect(MediaType::fromMimeType($mime))->toBe($expected);
})->with([
    ['image/svg+xml', MediaType::Svg],
    ['video/quicktime', MediaType::Mov],
    ['video/x-msvideo', MediaType::Avi],
    ['audio/flac', MediaType::Flac],
])->group('SPEC-023');

// --- The two settled aliases ------------------------------------------------
// audio/x-flac predates registration and is still widespread; video/avi sits
// beside the de-facto video/x-msvideo. Nothing else: the cost of an alias is
// not the line of code but the claim that we accept a spelling we have never
// seen in the wild.

it('accepts the two settled alias spellings', function (string $alias, MediaType $expected) {
    expect(MediaType::fromMimeType($alias))->toBe($expected);
})->with([
    ['audio/x-flac', MediaType::Flac],
    ['AUDIO/X-FLAC', MediaType::Flac],
    ['video/avi', MediaType::Avi],
    ['  video/avi ; codecs=xvid', MediaType::Avi],
])->group('SPEC-023');

it('refuses the spellings that were deliberately left out', function (string $mime) {
    // Settled before approval: only audio/x-flac and video/avi. If one of these
    // is ever added, it should be because someone met it, not because the list
    // drifted.
    expect(fn () => MediaType::fromMimeType($mime))
        ->toThrow(UnsupportedMediaTypeException::class);
})->with(['video/msvideo', 'image/svg', 'audio/vnd.wave', 'audio/flac+ogg'])
    ->group('SPEC-023');

it('reports the registered type, not the alias, as its value', function () {
    expect(MediaType::Flac->value)->toBe('audio/flac')
        ->and(MediaType::Avi->value)->toBe('video/x-msvideo');
})->group('SPEC-023');

// --- AC6 (client half): what stays out, stays out ---------------------------

it('still refuses the formats measured as unsupported', function (string $mime) {
    // application/pdf and video/webm are the interesting ones: refused by US,
    // for reasons that are upstream rather than arbitrary. PDF is read-only in
    // c2pa-rs, WEBM has no handler at all (NOTES Step 27).
    expect(fn () => MediaType::fromMimeType($mime))
        ->toThrow(UnsupportedMediaTypeException::class);
})->with(['application/pdf', 'video/webm', 'image/x-adobe-dng', 'image/jxl', 'image/bmp'])
    ->group('SPEC-023');

it('names all thirteen supported types when refusing', function () {
    try {
        MediaType::fromMimeType('video/webm');
        throw new RuntimeException('expected UnsupportedMediaTypeException was not thrown');
    } catch (UnsupportedMediaTypeException $e) {
        expect($e->getMessage())->toContain('video/webm');

        foreach (MediaType::cases() as $type) {
            expect($e->getMessage())->toContain($type->value);
        }
    }
})->group('SPEC-023');
