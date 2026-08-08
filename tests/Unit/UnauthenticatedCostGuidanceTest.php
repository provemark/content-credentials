<?php

declare(strict_types=1);

/**
 * SPEC-030 AC7 — what an unauthenticated request costs, published rather than
 * asserted as an adjective.
 *
 * SPEC-017 and SPEC-024 both set their defaults from a measurement and published
 * the figure where somebody sizing a container would look. This does the same
 * for the pre-authentication path, and for the same reason: "cheap" is not a
 * number an operator can plan with.
 *
 * Unit-level: reads a repository file, needs no running service.
 */
function spec030ServicePageText(): string
{
    $path = dirname(__DIR__, 2).'/docs/service.md';
    $raw = file_get_contents($path);

    if (! is_string($raw)) {
        throw new RuntimeException("cannot read {$path}");
    }

    // Collapsed, because the prose is hard-wrapped: a phrase that spans a line
    // break is simply absent from the raw text, which is how a documentation
    // check passes while testing nothing (NOTES Step 21).
    return (string) preg_replace('/\s+/', ' ', $raw);
}

it('says which side of authentication the limits are on', function () {
    $text = strtolower(spec030ServicePageText());

    // The sentence "Limits are on by default" read as though the whole service
    // were bounded. Every limit it lists is a post-authentication limit.
    //
    // The phrase is 'before the body is parsed' and not 'before authentication'.
    // The first version asserted the latter, and the prose written to satisfy it
    // said "the bearer check itself runs before authentication" — which is
    // nonsense, because the bearer check IS the authentication. A substring a
    // sentence can satisfy while meaning nothing is not a check on the sentence.
    expect($text)->toContain('before the body is parsed');
})->group('SPEC-030');

it('publishes what an unauthenticated request costs', function () {
    $text = strtolower(spec030ServicePageText());

    // A figure, not an adjective. Phrasing is free; a measured number is not.
    expect($text)->toContain('unauthenticated');
    expect(preg_match('/unauthenticated[^.]{0,200}?\d/', $text))->toBe(
        1,
        'the unauthenticated path is described without a measured figure',
    );
})->group('SPEC-030');

it('documents the failed-authentication budget and its counter', function () {
    $text = spec030ServicePageText();

    expect($text)->toContain('AUTH_FAIL_LIMIT')
        ->and($text)->toContain('auth_failures');
})->group('SPEC-030');
