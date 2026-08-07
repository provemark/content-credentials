<?php

declare(strict_types=1);

/**
 * SPEC-028 AC10 — the documentation distinguishes the two obligations.
 *
 * Article 50(2) covers content that is generated OR manipulated, and someone who
 * reads only "AI-generated" will conclude their inpainting feature is out of
 * scope. The extra argument is also not discoverable: nothing about
 * `forAiManipulated()` suggests that signing it needs a second asset until the
 * exception arrives.
 *
 * Every phrase below was confirmed absent from `git show origin/main:` before
 * the change (NOTES Step 31), so these assertions have been red.
 *
 * @see specs/SPEC-028-manipulated-content-ingredients.md
 */
function cc28Doc(string $file): string
{
    $raw = file_get_contents(dirname(__DIR__, 2).'/'.$file);

    if (! is_string($raw)) {
        throw new RuntimeException("cannot read {$file}");
    }

    // Normalised twice over, and both are the same lesson from NOTES Step 21:
    // a phrase assertion fails on formatting the author never thought about.
    // Whitespace, because the prose is hard-wrapped; asterisks, because
    // emphasis lands mid-phrase — "generated *or manipulated*" is the sentence
    // the criterion is about, and the markers are not part of it.
    $collapsed = (string) preg_replace('/\s+/', ' ', $raw);

    return strtolower(str_replace('*', '', $collapsed));
}

it('says Article 50 covers manipulation, not only generation', function () {
    foreach (['README.md', 'docs/marking.md'] as $file) {
        expect(cc28Doc($file))->toContain('generated or manipulated');
    }
})->group('SPEC-028');

it('shows the manipulated call and names the constructor', function () {
    $doc = cc28Doc('docs/marking.md');

    expect($doc)->toContain('foraimanipulated')
        ->and($doc)->toContain('compositewithtrainedalgorithmicmedia');
})->group('SPEC-028');

it('says the original asset itself must be supplied, and why', function () {
    foreach (['README.md', 'docs/marking.md'] as $file) {
        $doc = cc28Doc($file);

        // The "why" is the load-bearing half: a reader who thinks a filename or
        // a digest would do will design an API around one and discover
        // otherwise late.
        expect($doc)->toContain('the original asset')
            ->and($doc)->toContain('hash');
    }
})->group('SPEC-028');

it('names both refusals so neither is discovered from a stack trace', function () {
    $doc = cc28Doc('docs/marking.md');

    expect($doc)->toContain('missingparentassetexception')
        ->and($doc)->toContain('unexpectedparentassetexception');
})->group('SPEC-028');

it('publishes the generational growth as a number, not an adjective', function () {
    // Someone sizing a storage bucket needs the figure. "Grows with each edit"
    // is not something you can multiply.
    $doc = cc28Doc('docs/marking.md');

    expect($doc)->toContain('90 kb per generation')
        ->and($doc)->toContain('linearly');
})->group('SPEC-028');

it('records the measured memory cost where a container is sized', function () {
    $doc = cc28Doc('docs/service.md');

    expect($doc)->toContain('4.6×')
        ->and($doc)->toContain('244 mib')
        // The conclusion, not just the number: this changes no ceiling.
        ->and($doc)->toContain('needs no new limit');
})->group('SPEC-028');
