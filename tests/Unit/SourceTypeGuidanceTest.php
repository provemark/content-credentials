<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;

/**
 * SPEC-026 AC6 — the documentation says what each term claims.
 *
 * Choosing the wrong source type is a false statement about an asset, not a
 * style error, and the two terms most likely to be confused differ by one word
 * in their names. So the docs have to carry IPTC's definitions rather than the
 * names alone.
 *
 * @see specs/SPEC-026-digital-source-types.md
 */
function cc26Doc(string $file): string
{
    $raw = file_get_contents(dirname(__DIR__, 2).'/'.$file);

    if (! is_string($raw)) {
        throw new RuntimeException("cannot read {$file}");
    }

    return strtolower((string) preg_replace('/\s+/', ' ', $raw));
}

it('lists every emittable source type in the README', function () {
    $readme = cc26Doc('README.md');

    foreach ([
        DigitalSourceType::TrainedAlgorithmicMedia,
        DigitalSourceType::CompositeSynthetic,
        DigitalSourceType::AlgorithmicMedia,
    ] as $type) {
        expect($readme)->toContain(strtolower(basename($type->value)));
    }
})->group('SPEC-026');

it('states the difference between the two composite terms', function () {
    // The one people will get wrong: compositeWithTrainedAlgorithmicMedia is an
    // EDIT, compositeSynthetic is a new asset made of parts. Their names give no
    // hint of that, so the definition has to be on the page.
    $readme = cc26Doc('README.md');

    expect($readme)->toContain('compositewithtrainedalgorithmicmedia')
        ->and($readme)->toContain('already existed');
})->group('SPEC-026');

it('explains why the editing terms cannot be built', function () {
    $readme = cc26Doc('README.md');

    expect($readme)->toContain('ingredient')
        ->and($readme)->toContain('c2pa.opened');
})->group('SPEC-026');

it('documents what the two reading predicates mean', function (string $file) {
    $text = cc26Doc($file);

    expect($text)->toContain('isaigenerated()')
        ->and($text)->toContain('involvesgenerativeai()');
})->with(['README.md', 'docs/c2pa-primer.md'])->group('SPEC-026');

it('warns that the guidance misspells the IPTC term', function () {
    // The primer is where someone implementing from a C2PA document will look.
    expect(cc26Doc('docs/c2pa-primer.md'))->toContain('compositedwithtrainedalgorithmicmedia');
})->group('SPEC-026');
