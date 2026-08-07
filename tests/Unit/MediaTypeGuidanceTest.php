<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\MediaType;

/**
 * SPEC-021 AC5 — the size limit is documented per format, not just as a number.
 *
 * `video/mp4` ships because it works. Shipping it without saying that the 20 MB
 * body limit and the ~7× memory multiplier bound it to small files would be the
 * most misleading thing in this spec: the first person to try a real video
 * would learn that this library "supports MP4", and then that it does not, in
 * two steps, from an error about bytes.
 *
 * Matched as phrases against whitespace-normalised text: the README is
 * hard-wrapped, so a phrase can carry a newline (NOTES Step 21), and a
 * substring check on a short word is not a check (NOTES Step 20).
 *
 * Unit-level: reads a repository file, needs no running service.
 *
 * @see specs/SPEC-021-additional-media-types.md
 */
function cc21Readme(): string
{
    // Moved with its text by SPEC-027.
    $path = dirname(__DIR__, 2).'/docs/marking.md';
    $raw = file_get_contents($path);

    if (! is_string($raw)) {
        throw new RuntimeException("cannot read {$path}");
    }

    // Collapse every whitespace run to one space so a hard-wrapped phrase still
    // matches, and lowercase so the assertions are about wording, not casing.
    return strtolower((string) preg_replace('/\s+/', ' ', $raw));
}

it('lists every supported media type', function () {
    $readme = cc21Readme();

    foreach (MediaType::cases() as $type) {
        expect($readme)->toContain($type->value);
    }
})->group('SPEC-021');

it('says the size limit applies to every format', function () {
    $readme = cc21Readme();

    // The three terms an operator needs to reason about any format: the limit,
    // the multiplier, and the fact that neither is per-format.
    expect($readme)->toContain('max_body_size')
        ->and($readme)->toContain('7×')
        ->and($readme)->toContain('applies to every media type');
})->group('SPEC-021');

it('qualifies video rather than claiming it', function () {
    $readme = cc21Readme();

    // Both halves must be present: that mp4 is accepted as a container, and
    // that it is bounded to small files. Either alone misleads.
    expect($readme)->toContain('video/mp4')
        ->and($readme)->toContain('bounded to small files');
})->group('SPEC-021');

it('names the transport as the reason video is bounded', function () {
    // Not "video is unsupported" and not an unexplained limit: the barrier is
    // base64 in one HTTP body, which is a separate architectural project
    // (SPEC-021 out of scope). Someone reading this should know what would
    // have to change, not just that something is missing.
    expect(cc21Readme())->toContain('base64 in one http body');
})->group('SPEC-021');
