<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;
use Provemark\ContentCredentials\Core\Manifest\MediaType;

/**
 * SPEC-021 — the media types the engine already supports.
 *
 * PNG and JPEG were never a c2pa-rs limitation; they were two hand-written
 * allow-lists. This file pins the widened set on the client side. AC1, AC2,
 * AC4, AC6 and AC7 need a running service and live in
 * tests/Integration/MediaTypesTest.php; AC8 (extension inference) lives in
 * tests/Unit/Laravel/MediaTypeInferenceTest.php.
 *
 * @see specs/SPEC-021-additional-media-types.md
 */

// --- The declared set ------------------------------------------------------

it('declares exactly the media types SPEC-021 measured', function () {
    // Whole-set equality, in spec order. A new case added without a spec —
    // and without the service's SUPPORTED_MIME, which AC2 compares against a
    // running deployment — fails here first.
    expect(array_map(fn (MediaType $t): string => $t->value, MediaType::cases()))->toBe([
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/avif',
        'image/gif',
        'image/tiff',
        'audio/wav',
        'audio/mpeg',
        'video/mp4',
    ]);
})->group('SPEC-021');

it('resolves every declared type from its own mime string', function () {
    foreach (MediaType::cases() as $type) {
        expect(MediaType::fromMimeType($type->value))->toBe($type);
    }
})->group('SPEC-021');

// --- The audio/mp3 alias ---------------------------------------------------
// audio/mpeg is the registered type; audio/mp3 is what a good deal of software
// actually emits. Rejecting it would be pedantry with a support cost.

it('accepts audio/mp3 as an alias of audio/mpeg', function (string $mime) {
    expect(MediaType::fromMimeType($mime))->toBe(MediaType::Mp3);
})->with(['audio/mpeg', 'audio/mp3', 'AUDIO/MP3', '  audio/mp3 ; charset=binary'])
    ->group('SPEC-021');

it('reports the registered type, not the alias, as its value', function () {
    // The alias is an accepted input, never an emitted output: the service and
    // the manifest `format` must carry the registered type.
    expect(MediaType::Mp3->value)->toBe('audio/mpeg');
})->group('SPEC-021');

// --- AC3 (client half): an unsupported type is refused ---------------------

it('refuses a media type outside the set', function (string $mime) {
    expect(fn () => MediaType::fromMimeType($mime))
        ->toThrow(UnsupportedMediaTypeException::class);
})->with(['image/bmp', 'application/pdf', 'text/plain', 'image/svg+xml', 'video/webm'])
    ->group('SPEC-021');

it('names every supported type in the refusal', function () {
    try {
        MediaType::fromMimeType('image/bmp');
        throw new RuntimeException('expected UnsupportedMediaTypeException was not thrown');
    } catch (UnsupportedMediaTypeException $e) {
        // Derived from the enum, not restated: a message listing two types
        // while the enum holds nine is exactly the staleness this spec exists
        // to remove.
        expect($e->getMessage())->toContain('image/bmp');

        foreach (MediaType::cases() as $type) {
            expect($e->getMessage())->toContain($type->value);
        }
    }
})->group('SPEC-021');
