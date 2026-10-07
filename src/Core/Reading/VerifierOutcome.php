<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading;

use Provemark\ContentCredentials\Core\Reading\Exception\ReadFailedException;
use Provemark\ContentCredentials\Core\Support\ServiceError;

/**
 * Maps a provemark/c2pa-verifier report onto the ReaderInterface contract
 * (SPEC-042 AC2, AC4, AC5).
 *
 * The verifier never throws on input: it answers every file with a report. The
 * other two readers throw for a file they cannot parse and return an empty
 * report for a file with no manifest, so three cases have to be told apart:
 *
 * - `has_manifest` false and no failure: no C2PA data. An empty report, NOT the
 *   verifier's `validation_state: "Invalid"`, which would reach the caller as
 *   `validationState() === Invalid` where both other readers answer null.
 * - `has_manifest` false with a failure: the file could not be read up to its
 *   store. ReadFailedException, carrying the explanation, bounded.
 * - `has_manifest` true: decoded by ManifestStoreParser, failures included —
 *   a broken signature is an answer, not a read failure.
 *
 * A seam rather than inline code, as TrustAnchorsGuard is for SPEC-032: no
 * message a real file produces reaches the bound, so AC5 is tested here with
 * the report handed in directly.
 *
 * @internal not part of the public API
 */
final class VerifierOutcome
{
    /**
     * @param  array<array-key, mixed>  $report  the shape of `VerificationReport::toArray()`
     *
     * @throws ReadFailedException when the file could not be read, or the report does not say
     */
    public static function toManifestReport(array $report): ManifestReport
    {
        $hasManifest = $report['has_manifest'] ?? null;

        if (! is_bool($hasManifest)) {
            // Failing closed: "no credentials" is the wrong default for a report
            // that does not say. It is the silent wrong answer.
            throw new ReadFailedException('Could not read the asset: the verifier report does not say whether a manifest was found.');
        }

        if ($hasManifest) {
            return ManifestStoreParser::fromArray($report);
        }

        $failures = self::failures($report);

        if ($failures === []) {
            return new ManifestReport(null, null, [], [], null);
        }

        // SPEC-040 AC5's rule: bounded where the string enters this package,
        // since the verifier quotes bytes from the asset. Making it safe to
        // print stays SafeOutput's job.
        throw new ReadFailedException('Could not read the asset: '.ServiceError::bound(implode('; ', $failures)));
    }

    /**
     * The explanations of every failure the report records.
     *
     * @param  array<array-key, mixed>  $report
     * @return list<string>
     */
    private static function failures(array $report): array
    {
        $entries = [];

        if (isset($report['validation_status']) && is_array($report['validation_status'])) {
            $entries = $report['validation_status'];
        }

        $results = $report['validation_results'] ?? null;
        $active = is_array($results) ? ($results['activeManifest'] ?? null) : null;

        if ($entries === [] && is_array($active) && isset($active['failure']) && is_array($active['failure'])) {
            $entries = $active['failure'];
        }

        $explanations = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $text = $entry['explanation'] ?? $entry['code'] ?? null;
            $explanations[] = is_string($text) && $text !== '' ? $text : 'unexplained failure';
        }

        return $explanations;
    }
}
