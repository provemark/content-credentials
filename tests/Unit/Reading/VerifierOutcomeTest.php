<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Reading\Exception\ReadFailedException;
use Provemark\ContentCredentials\Core\Reading\VerifierOutcome;

/**
 * SPEC-042 AC5 (and the AC2/AC4 rule underneath) — the mapping from the
 * verifier's report to the ReaderInterface contract.
 *
 * A seam rather than only end-to-end tests, for the same reason as
 * TrustAnchorsGuard (SPEC-032): the case AC5 guards cannot be produced with a
 * real file. Measured 2026-10-07, the verifier's longest failure explanation
 * for an unreadable file is about 150 characters of hex, well inside the
 * 256-character bound, and none carried invalid UTF-8. So the bound would
 * otherwise assert a length never at risk — SPEC-040 hit the same wall.
 *
 * The input is the shape of `VerificationReport::toArray()`, cut to the keys
 * the mapping reads.
 *
 * @see specs/SPEC-042-pure-php-reader-via-c2pa-verifier.md
 */

/** @return array<string, mixed> */
function spec042Failure(string $explanation): array
{
    return [
        'has_manifest' => false,
        'validation_state' => 'Invalid',
        'validation_status' => [
            ['code' => 'general.error', 'url' => 'self#jumbf=/c2pa', 'explanation' => $explanation],
        ],
    ];
}

/** @param array<string, mixed> $report */
function spec042MappingMessage(array $report): string
{
    try {
        VerifierOutcome::toManifestReport($report);
    } catch (ReadFailedException $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('the mapping did not throw');
}

it('bounds a long explanation where it enters this package', function () {
    $message = spec042MappingMessage(spec042Failure(str_repeat('x', 5000)));

    expect($message)->toStartWith('Could not read the asset: ')
        ->and($message)->toEndWith('… (truncated)')
        ->and(strlen($message))->toBeLessThan(400);
})->group('SPEC-042');

it('bounds an explanation that is not valid UTF-8 without discarding it', function () {
    // The branch SPEC-040 made bound() for: the /u pattern fails on invalid
    // UTF-8, and a fallback that dropped the message would leave an operator
    // with nothing.
    $message = spec042MappingMessage(spec042Failure("\xC3\x28".str_repeat('y', 5000)));

    expect($message)->toContain('yyyy')
        ->and($message)->toEndWith('… (truncated)')
        ->and(strlen($message))->toBeLessThan(400);
})->group('SPEC-042');

it('keeps a short explanation verbatim', function () {
    expect(spec042MappingMessage(spec042Failure('unsupported file type: the file starts with 00 01')))
        ->toBe('Could not read the asset: unsupported file type: the file starts with 00 01');
})->group('SPEC-042');

it('maps no manifest and no failure to an empty report', function () {
    // The unsigned-asset shape, verbatim from the verifier on fixture.png.
    $report = VerifierOutcome::toManifestReport([
        'active_manifest' => null,
        'manifests' => [],
        'validation_results' => ['activeManifest' => ['success' => [], 'informational' => [], 'failure' => []]],
        'validation_state' => 'Invalid',
        'format' => 'png',
        'has_manifest' => false,
    ]);

    expect($report->hasManifest())->toBeFalse()
        ->and($report->validationState())->toBeNull();
})->group('SPEC-042');

it('treats a report with no has_manifest key as a failure, not as empty', function () {
    // Failing closed: if a future verifier renames the key, "no credentials"
    // is the wrong default — it is the silent wrong answer.
    expect(fn () => VerifierOutcome::toManifestReport(['validation_state' => 'Invalid']))
        ->toThrow(ReadFailedException::class);
})->group('SPEC-042');
