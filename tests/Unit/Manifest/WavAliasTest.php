<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\Exception\UnsupportedMediaTypeException;
use Provemark\ContentCredentials\Core\Manifest\MediaType;

/**
 * SPEC-041 — audio/x-wav, the spelling every local tool actually emits.
 *
 * The three aliases before this one covered spellings some software emits.
 * This one covers the spelling PHP itself returns from its own finfo database,
 * and the one Drupal core's MIME guesser maps every .wav to — so a caller
 * handing over the type its framework detected could not sign or read a WAV at
 * all (NOTES Step 61).
 *
 * @see specs/SPEC-041-the-audio-x-wav-alias.md
 */

// --- AC1: the alias resolves ------------------------------------------------

it('resolves audio/x-wav to the same case audio/wav resolves to', function () {
    expect(MediaType::fromMimeType('audio/x-wav'))
        ->toBe(MediaType::Wav)
        ->and(MediaType::fromMimeType('audio/x-wav'))
        ->toBe(MediaType::fromMimeType('audio/wav'));
})->group('SPEC-041');

// --- AC2: and normalises like every other accepted spelling -----------------
// The alias is applied after trimming, lowercasing and parameter stripping. A
// table consulted before that would accept the bare spelling and reject the
// three shapes a real Content-Type header arrives in.

it('accepts audio/x-wav however it is spelled', function (string $mime) {
    expect(MediaType::fromMimeType($mime))->toBe(MediaType::Wav);
})->with([
    'audio/x-wav',
    'AUDIO/X-WAV',
    '  audio/x-wav ',
    'audio/x-wav; charset=binary',
    '  AUDIO/X-WAV ; charset=binary',
])->group('SPEC-041');

// --- AC3: an input spelling, never an output --------------------------------
// An alias leaking into the enum's value would put an unregistered type into a
// manifest and onto the wire, which is worse than the defect this fixes.

it('keeps audio/x-wav out of the values it reports', function () {
    expect(MediaType::Wav->value)->toBe('audio/wav');

    foreach (MediaType::cases() as $case) {
        expect($case->value)->not->toBe('audio/x-wav');
    }
})->group('SPEC-041');

it('still refuses audio/x-wav from tryFrom, which takes values and not spellings', function () {
    // The enum's own tryFrom() knows nothing about aliases, and should not:
    // normalisation is fromMimeType()'s job, and blurring that would make the
    // backed value ambiguous.
    expect(MediaType::tryFrom('audio/x-wav'))->toBeNull();
})->group('SPEC-041');

// --- AC4: near misses stay refused ------------------------------------------
// The alias table is an exact map, not a prefix or a fuzzy match. Widening it
// by accident is how a list of supported types stops meaning anything.

it('refuses spellings that only look like the alias', function (string $mime) {
    expect(fn () => MediaType::fromMimeType($mime))
        ->toThrow(UnsupportedMediaTypeException::class);
})->with([
    'audio/x-wave',
    'audio/wave',
    'audio/vnd.wave',
    'audiox-wav',
    'x-wav',
    'audio/x-wav-',
])->group('SPEC-041');
