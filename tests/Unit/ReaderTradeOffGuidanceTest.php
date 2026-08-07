<?php

declare(strict_types=1);

/**
 * SPEC-025 AC6 — the extension reader's process boundary is documented.
 *
 * ADR-0003 keeps the signing key out of the web process. `ExtC2paReader` moves
 * parsing of untrusted assets in the opposite direction, and until now the docs
 * framed the choice purely as operating cost. SPEC-020's `auto` mode makes it
 * near-default for anyone who installs the extension, so the trade has to be
 * stated where the choice is made.
 *
 * @see specs/SPEC-025-client-side-bounds.md
 */
function cc25Doc(string $file): string
{
    $raw = file_get_contents(dirname(__DIR__, 2).'/'.$file);

    if (! is_string($raw)) {
        throw new RuntimeException("cannot read {$file}");
    }

    return strtolower((string) preg_replace('/\s+/', ' ', $raw));
}

it('says where the extension reader parses untrusted input', function (string $file) {
    $text = cc25Doc($file);

    expect($text)->toContain('untrusted')
        ->and($text)->toContain('application process');
})->with(['docs/readers.md', 'docs/c2pa-primer.md'])->group('SPEC-025');

it('ties the trade-off back to the decision it mirrors', function () {
    // Not a free operational win: it is the same boundary ADR-0003 draws for the
    // key, drawn the other way. A reader who knows that can decide; one who is
    // told only "no second process" cannot.
    expect(cc25Doc('docs/readers.md'))->toContain('adr-0003')
        ->and(cc25Doc('docs/readers.md'))->toContain('separate, disposable process');
})->group('SPEC-025');
