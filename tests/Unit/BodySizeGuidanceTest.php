<?php

declare(strict_types=1);

/**
 * SPEC-017 AC5 — the documented multiplier matches measurement.
 *
 * SPEC-015 and the v0.5.1 changelog say a signing request holds "roughly four
 * copies" of the asset. Measured 2026-08-06 at the concurrency cap, against a
 * 17.6 MiB idle baseline, it is about 7× — 12.1× at 1 MB, 8.7× at 4.1 MB, 6.9×
 * at 11.4 MB, the ratio falling as fixed overhead amortises.
 *
 * Neither of those two is edited: SPEC-015 is `approved` and therefore frozen
 * outside its Traceability section, and a published changelog entry is a record
 * of what was released, not a document to revise. The correction lives in
 * SPEC-017 and, for anyone actually sizing a container, in the README.
 *
 * These assert what the README must SAY rather than what it must not. An
 * earlier version checked that the README did not contain "roughly four
 * copies" — which it never did, so it passed while testing nothing.
 *
 * Unit-level: reads a repository file, needs no running service.
 */
function servicePageText(): string
{
    // Moved with its text by SPEC-027: sizing lives on the service page now.
    $path = dirname(__DIR__, 2).'/docs/service.md';
    $raw = file_get_contents($path);

    if (! is_string($raw)) {
        throw new RuntimeException("cannot read {$path}");
    }

    return $raw;
}

it('states the measured memory multiplier', function () {
    // The number an operator multiplies by. Phrasing is free; the figure is not.
    expect(servicePageText())->toContain('7×');
})->group('SPEC-017');

it('gives the relationship needed to size a container', function () {
    $readme = strtolower(servicePageText());

    // Peak ≈ concurrency × multiplier × largest asset. A reader needs all three
    // terms named, or the multiplier alone tells them nothing actionable.
    //
    // Matched as phrases, not fragments: an earlier version looked for 'peak'
    // and passed because 'speaks plain HTTP' contains it. A substring check on
    // a short word is not a check.
    expect($readme)->toContain('max_body_size')
        ->and($readme)->toContain('peak memory')
        ->and($readme)->toContain('concurrency cap');
})->group('SPEC-017');
