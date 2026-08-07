<?php

declare(strict_types=1);

/**
 * SPEC-023 AC4 + AC5 — the two caveats that are specific to these formats.
 *
 * Not "post-sign mutation invalidates", which the primer already says and which
 * is too general to save anybody. These are the measured failure modes:
 * SVGO deletes a signed SVG's manifest silently, and lossless audio runs into
 * the body limit that the README currently calls comfortable.
 *
 * Matched as phrases against whitespace-normalised text — the README is
 * hard-wrapped, so a phrase can carry a newline (NOTES Step 21).
 *
 * @see specs/SPEC-023-measured-remaining-media-types.md
 */
function cc23Readme(): string
{
    // Moved with its text by SPEC-027.
    $path = dirname(__DIR__, 2).'/docs/marking.md';
    $raw = file_get_contents($path);

    if (! is_string($raw)) {
        throw new RuntimeException("cannot read {$path}");
    }

    return strtolower((string) preg_replace('/\s+/', ' ', $raw));
}

// --- AC4: the measured fate of a signed SVG --------------------------------

it('names the tool that silently removes an SVG manifest', function () {
    // SVGO by name, not "an optimiser": someone searching their build config
    // needs the string they will find there.
    expect(cc23Readme())->toContain('svgo');
})->group('SPEC-023');

it('says the SVG failure is silent, not an error', function () {
    $readme = cc23Readme();

    // The distinction that matters. A re-encoded JPEG fails loudly on
    // verification; a bundled SVG simply is not signed any more, and nothing
    // anywhere says so.
    expect($readme)->toContain('silently')
        ->and($readme)->toContain('never signed');
})->group('SPEC-023');

it('gives the rule that follows from it', function () {
    // Actionable, not just alarming: sign the deliverable, not the build input.
    expect(cc23Readme())->toContain('not as a build asset');
})->group('SPEC-023');

it('covers the second SVG failure mode as well', function () {
    // Re-serialising the XML renames the namespace prefix and c2pa-rs then
    // refuses to parse the file. Different symptom, same cause class, and it
    // catches tools that do not run SVGO at all.
    expect(cc23Readme())->toContain('re-serialis');
})->group('SPEC-023');

// --- AC5: the size limit, in FLAC terms ------------------------------------

it('qualifies the short-audio claim for lossless formats', function () {
    $readme = cc23Readme();

    // The README says the limit comfortably covers "images and short audio".
    // FLAC is the first supported format whose ordinary files run into it, so
    // that sentence needs the exception attached to it.
    expect($readme)->toContain('lossless')
        ->and($readme)->toContain('max_body_size');
})->group('SPEC-023');

it('keeps the lossless caveat beside the short-audio claim', function () {
    $readme = cc23Readme();

    // Guard against the caveat being written somewhere far away from the claim
    // it qualifies: the word must appear near enough to be read together.
    $claim = strpos($readme, 'short audio');
    $caveat = strpos($readme, 'lossless');

    expect($claim)->not->toBeFalse('README no longer contains the "short audio" claim — update this test')
        ->and($caveat)->not->toBeFalse();

    expect(abs((int) $caveat - (int) $claim))->toBeLessThan(600);
})->group('SPEC-023');

// --- AC4/AC5 both: the new types are actually listed ------------------------

it('lists the four added media types', function () {
    $readme = cc23Readme();

    foreach (['image/svg+xml', 'video/quicktime', 'video/x-msvideo', 'audio/flac'] as $mime) {
        expect($readme)->toContain($mime);
    }
})->group('SPEC-023');
