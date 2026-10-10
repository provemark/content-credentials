<?php

declare(strict_types=1);

/**
 * SPEC-042, ADR-0007 addendum (2026-10-08): the verifier range this package
 * was measured against is enforced on hosts, not only advised.
 *
 * `require-dev` is what CI tests against; `conflict` is what a host's Composer
 * refuses. If the two drift, a host can install a minor nobody measured, and
 * VerifierOutcome maps one version's report shape.
 *
 * @see docs/adr/ADR-0007-the-verifier-as-an-optional-reader.md
 */

/**
 * One section of composer.json as package => string, absent entries dropped.
 *
 * @return array<string, string>
 */
function spec042ComposerSection(string $section): array
{
    $json = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
    $entries = is_array($json) && is_array($json[$section] ?? null) ? $json[$section] : [];

    $out = [];

    foreach ($entries as $package => $value) {
        if (is_string($package) && is_string($value)) {
            $out[$package] = $value;
        }
    }

    return $out;
}

it('refuses every verifier version outside the tested range', function () {
    $tested = spec042ComposerSection('require-dev')['provemark/c2pa-verifier'] ?? '';

    // The tested constraint is a caret on a 0.x minor, optionally with a patch
    // floor (Amendment 5): ^0.Y allows 0.Y.*, ^0.Y.Z allows 0.Y.Z and later 0.Y.
    expect(preg_match('/^\^0\.(\d+)(?:\.(\d+))?$/', $tested, $m))->toBe(1, "require-dev constraint is {$tested}");
    $minor = (int) ($m[1] ?? -1);
    $floor = isset($m[2]) ? sprintf('0.%d.%d', $minor, (int) $m[2]) : sprintf('0.%d', $minor);

    expect(spec042ComposerSection('conflict')['provemark/c2pa-verifier'] ?? null)
        ->toBe(sprintf('<%s || >=0.%d', $floor, $minor + 1));
})->group('SPEC-042');

it('tests against the verifier that reads data boxes', function () {
    // SPEC-042 Amendment 5 / SPEC-045 Amendment 4: 0.6.1 is the floor.
    expect(spec042ComposerSection('require-dev')['provemark/c2pa-verifier'] ?? null)->toBe('^0.6.1')
        ->and(spec042ComposerSection('conflict')['provemark/c2pa-verifier'] ?? null)->toBe('<0.6.1 || >=0.7');
})->group('SPEC-042');

it('keeps the verifier out of require', function () {
    // ADR-0007: optional. A hard requirement would add ext-mbstring to every
    // install.
    expect(spec042ComposerSection('require'))->not->toHaveKey('provemark/c2pa-verifier')
        ->and(spec042ComposerSection('suggest'))->toHaveKey('provemark/c2pa-verifier');
})->group('SPEC-042');
