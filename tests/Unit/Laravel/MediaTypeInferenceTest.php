<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Laravel\Console\InfersMediaType;

/**
 * SPEC-021 AC8 — the artisan commands accept the same set, by extension.
 *
 * `InfersMediaType` (SPEC-006 D4) is the third hand-written list of what this
 * package supports, after the `MediaType` enum and the service's
 * `SUPPORTED_MIME`. Widening the enum without it would ship an enum that
 * accepts `image/webp` and a command that refuses `photo.webp`.
 *
 * @see specs/SPEC-021-additional-media-types.md
 */
function cc21Infer(string $path): MediaType
{
    $subject = new class
    {
        use InfersMediaType;

        public function of(string $path): MediaType
        {
            return $this->mediaTypeFromPath($path);
        }
    };

    return $subject->of($path);
}

/**
 * Every extension the map is expected to know, including the aliases.
 *
 * @return list<string>
 */
function cc21Extensions(): array
{
    return ['png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'tif', 'tiff', 'svg',
        'wav', 'mp3', 'flac', 'mp4', 'mov', 'avi'];
}

it('infers the declared type from a file extension', function (string $path, MediaType $expected) {
    expect(cc21Infer($path))->toBe($expected);
})->with([
    ['a.png', MediaType::Png],
    ['a.jpg', MediaType::Jpeg],
    ['a.jpeg', MediaType::Jpeg],
    ['a.JPEG', MediaType::Jpeg],
    ['a.webp', MediaType::Webp],
    ['a.avif', MediaType::Avif],
    ['a.gif', MediaType::Gif],
    ['a.tif', MediaType::Tiff],
    ['a.tiff', MediaType::Tiff],
    ['a.wav', MediaType::Wav],
    ['a.mp3', MediaType::Mp3],
    ['a.mp4', MediaType::Mp4],
    ['/some/dir.with.dots/photo.WEBP', MediaType::Webp],
])->group('SPEC-021');

it('reaches every declared media type from some extension', function () {
    // Derived from the enum rather than restating the map: this is what keeps
    // the third list from lagging the first. Add a case to MediaType with no
    // extension for it and this fails, naming the type.
    $reached = [];
    foreach (cc21Extensions() as $extension) {
        $reached[cc21Infer("a.{$extension}")->value] = true;
    }

    foreach (MediaType::cases() as $type) {
        expect($reached)->toHaveKey($type->value);
    }
})->group('SPEC-021');

it('refuses an unsupported extension and names what is supported', function () {
    try {
        cc21Infer('photo.bmp');
        throw new RuntimeException('expected UnsupportedMediaTypeException was not thrown');
    } catch (UnsupportedMediaTypeException $e) {
        expect($e)->toBeInstanceOf(UnsupportedMediaTypeException::class)
            ->and($e->getMessage())->toContain('.bmp');

        // The message must list the extensions that would work — all of them,
        // not the two it used to name.
        foreach (cc21Extensions() as $extension) {
            expect($e->getMessage())->toContain(".{$extension}");
        }
    }
})->group('SPEC-021');
