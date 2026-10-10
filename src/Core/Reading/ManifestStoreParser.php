<?php

declare(strict_types=1);

namespace Provemark\ContentCredentials\Core\Reading;

use Provemark\ContentCredentials\Core\Reading\Exception\ReadResponseException;

/**
 * Turns c2pa-rs manifest-store JSON into a typed ManifestReport.
 *
 * Extracted from SigningServiceReader unchanged (SPEC-019), because there is now
 * more than one way to obtain that JSON: over HTTP from the signing service, or
 * in-process from ext-c2pa. Both engines emit the same store shape —
 * `active_manifest`, `manifests`, `validation_status`, `validation_state` —
 * verified against ext-c2pa v0.1.0 on 2026-08-06.
 *
 * One parser, deliberately. A second one would be a second place for the
 * definition of "trusted" to drift, and SPEC-013 is the record of how expensive
 * that definition is to get wrong. It also makes SPEC-019 AC2 meaningful: when
 * the two readers disagree, the difference is in c2pa-rs, not in our decoding.
 *
 * Every field is treated as untrusted input: a missing, mistyped or malformed
 * value degrades to null/false/[], never to an exception.
 *
 * @internal not part of the public API; construct a reader instead.
 */
final class ManifestStoreParser
{
    /** SPEC-045 AC5: how deep the ingredient walk goes; the active manifest's own are depth 1. */
    private const MAX_INGREDIENT_DEPTH = 8;

    /** SPEC-045 AC5: how many ingredients one report holds, across the whole tree. */
    private const MAX_INGREDIENTS = 64;

    /**
     * SPEC-045 Amendment 1: the codes a writer may record about an ingredient
     * without making every ingredient untrusted. The C2PA 2.4 success and
     * informational codes as provemark/c2pa-verifier classifies them
     * (`StatusCode::isSuccess()` / `isInformational()`), plus
     * `signingCredential.untrusted`, which the reader re-evaluates against its
     * own anchors. Anything else — a failure, or a code this list does not
     * know — could have hidden a real failure from the reader's delta.
     */
    private const RECORDABLE_CODES = [
        'claimSignature.validated',
        'claimSignature.insideValidity',
        'assertion.hashedURI.match',
        'assertion.dataHash.match',
        'assertion.bmffHash.match',
        'signingCredential.trusted',
        'timeStamp.validated',
        'timeStamp.trusted',
        'ingredient.manifest.validated',
        'assertion.alternativeContentRepresentation.match',
        'signingCredential.ocsp.notRevoked',
        'assertion.dataHash.additionalExclusionsPresent',
        'ingredient.unknownProvenance',
        'ingredient.claimSignature.validated',
        'assertion.bmffHash.additionalExclusionsPresent',
        'timeStamp.malformed',
        'timeStamp.mismatch',
        'timeStamp.outsideValidity',
        'timeStamp.untrusted',
        'signingCredential.ocsp.skipped',
        'signingCredential.ocsp.unknown',
        'signingCredential.untrusted',
    ];

    /**
     * @throws ReadResponseException when the payload is not a manifest-store object
     */
    public static function fromJson(string $json): ManifestReport
    {
        try {
            $store = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ReadResponseException('Manifest store was not valid JSON.', previous: $e);
        }

        if (! is_array($store)) {
            throw new ReadResponseException('Manifest store payload was not an object.');
        }

        return self::fromArray($store);
    }

    /**
     * @param  array<array-key, mixed>  $store
     */
    public static function fromArray(array $store): ManifestReport
    {
        $activeLabel = isset($store['active_manifest']) && is_string($store['active_manifest'])
            ? $store['active_manifest']
            : null;

        $manifests = isset($store['manifests']) && is_array($store['manifests']) ? $store['manifests'] : [];
        $active = $activeLabel !== null && isset($manifests[$activeLabel]) && is_array($manifests[$activeLabel])
            ? $manifests[$activeLabel]
            : null;

        $state = isset($store['validation_state']) && is_string($store['validation_state'])
            ? ValidationState::tryFrom($store['validation_state'])
            : null;

        if ($active === null) {
            return new ManifestReport(null, null, [], self::validationCodes($store), $state);
        }

        $budget = self::MAX_INGREDIENTS;

        return new ManifestReport(
            $activeLabel,
            self::parseSigner($active),
            self::parseAssertions($active),
            self::validationCodes($store),
            $state,
            self::parseHasTimestamp($active),
            self::parseDeclaredSpecVersion($active),
            self::parseIngredients(
                $activeLabel,
                $active,
                $manifests,
                self::ingredientDeltas($store),
                // SPEC-045 Amendment 2: trust flows down from the report itself.
                $state === ValidationState::Trusted && self::recordsHideNothing($manifests),
                [$activeLabel],
                1,
                $budget,
            ),
        );
    }

    /**
     * The ingredients a manifest names, each with its own verdict (SPEC-045).
     *
     * Untrusted input like the rest: a malformed entry is skipped, a malformed
     * field degrades. The walk stops at a manifest already on the path (a cycle),
     * at MAX_INGREDIENT_DEPTH, and when $budget is spent; what lies beyond is
     * absent, not an error.
     *
     * @param  array<array-key, mixed>  $manifest
     * @param  array<array-key, mixed>  $manifests
     * @param  array<string, array{success: list<string>, failure: list<string>}|null>  $deltas  null: no usable evidence under that key
     * @param  bool  $aboveTrusted  whether everything above these ingredients is trusted and no record in the store can hide a failure (Amendments 1 and 2)
     * @param  list<string>  $path  labels from the active manifest down to $label
     * @return list<IngredientReport>
     */
    private static function parseIngredients(string $label, array $manifest, array $manifests, array $deltas, bool $aboveTrusted, array $path, int $depth, int &$budget): array
    {
        $entries = $manifest['ingredients'] ?? null;
        if (! is_array($entries) || $depth > self::MAX_INGREDIENT_DEPTH) {
            return [];
        }

        // Amendment 3: a label two entries share cannot say which one its delta
        // validated, so neither gets that delta.
        $labelCounts = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && isset($entry['label']) && is_string($entry['label'])) {
                $labelCounts[$entry['label']] = ($labelCounts[$entry['label']] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($entries as $entry) {
            if ($budget <= 0) {
                break;
            }
            if (! is_array($entry)) {
                continue;
            }
            $budget--;

            $relationship = isset($entry['relationship']) && is_string($entry['relationship']) ? $entry['relationship'] : null;
            $assertionLabel = isset($entry['label']) && is_string($entry['label']) ? $entry['label'] : null;
            $childLabel = isset($entry['active_manifest']) && is_string($entry['active_manifest']) ? $entry['active_manifest'] : null;
            $child = $childLabel !== null && isset($manifests[$childLabel]) && is_array($manifests[$childLabel])
                ? $manifests[$childLabel]
                : null;

            $delta = $assertionLabel === null || ($labelCounts[$assertionLabel] ?? 0) > 1
                ? null
                : $deltas[self::normalisedUri(sprintf('self#jumbf=/c2pa/%s/c2pa.assertions/%s', $label, $assertionLabel))] ?? null;

            $trusted = $aboveTrusted && $child !== null && self::ingredientTrusted($delta, self::recordedByWriter($entry));

            $children = $child === null || in_array($childLabel, $path, true)
                ? []
                : self::parseIngredients($childLabel, $child, $manifests, $deltas, $trusted, [...$path, $childLabel], $depth + 1, $budget);

            $out[] = new IngredientReport(
                $relationship,
                $child !== null,
                $child === null ? [] : (new ManifestReport($childLabel, null, self::parseAssertions($child), []))->digitalSourceTypes(),
                $trusted,
                $children,
            );
        }

        return $out;
    }

    /**
     * SPEC-045 AC3, in one place.
     *
     * @param  array{success: list<string>, failure: list<string>}|null  $delta  the reader's own validation of the ingredient
     * @param  array{success: list<string>, failure: list<string>}  $recorded  what the writer recorded about it
     */
    private static function ingredientTrusted(?array $delta, array $recorded): bool
    {
        if ($delta === null || ! in_array('signingCredential.trusted', $delta['success'], true) || $delta['failure'] !== []) {
            return false;
        }

        if (! in_array('claimSignature.validated', [...$delta['success'], ...$recorded['success']], true)) {
            return false;
        }

        // The writer's view of trust came from the writer's anchors; the reader
        // re-evaluated it against its own, which is why only this code is excused.
        return array_diff($recorded['failure'], ['signingCredential.untrusted']) === [];
    }

    /**
     * What the writer recorded about an ingredient: `validation_results` from
     * C2PA 2.x writers, or the older `validation_status` list. Every code in
     * the older list counts as a failure — it carries no success/failure split,
     * and reading a success there as a failure can only refuse an ingredient,
     * never accept one.
     *
     * @param  array<array-key, mixed>  $entry
     * @return array{success: list<string>, failure: list<string>}
     */
    private static function recordedByWriter(array $entry): array
    {
        $recorded = $entry['validation_results'] ?? null;
        $results = is_array($recorded) ? ($recorded['activeManifest'] ?? null) : null;
        if (is_array($results)) {
            return ['success' => self::codesIn($results['success'] ?? null), 'failure' => self::codesIn($results['failure'] ?? null)];
        }

        return ['success' => [], 'failure' => self::codesIn($entry['validation_status'] ?? null)];
    }

    /**
     * SPEC-045 Amendment 1: whether no writer anywhere in the store recorded a
     * code outside RECORDABLE_CODES.
     *
     * A reader drops a status the writer recorded, matched on code and url, in
     * any category and store-wide. So one recorded failure code — even filed
     * under `success`, even on another ingredient — can have hidden a real
     * failure from any ingredient's delta, and then none of them can be
     * trusted on that delta.
     *
     * @param  array<array-key, mixed>  $manifests
     */
    private static function recordsHideNothing(array $manifests): bool
    {
        foreach ($manifests as $manifest) {
            $entries = is_array($manifest) ? ($manifest['ingredients'] ?? null) : null;
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (is_array($entry) && ! self::recordHidesNothing($entry)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether every code a writer recorded in one ingredient entry, in any
     * category, is in RECORDABLE_CODES: `validation_status`, and
     * `validation_results` with its active manifest and its own nested
     * `ingredientDeltas`. Linear, and stops at the first other code
     * (Amendment 2: a record is file content, and can be large).
     *
     * @param  array<array-key, mixed>  $entry
     */
    private static function recordHidesNothing(array $entry): bool
    {
        $lists = [$entry['validation_status'] ?? null];

        $results = $entry['validation_results'] ?? null;
        if (is_array($results)) {
            $maps = [$results['activeManifest'] ?? null];
            foreach (is_array($results['ingredientDeltas'] ?? null) ? $results['ingredientDeltas'] : [] as $delta) {
                $maps[] = is_array($delta) ? ($delta['validationDeltas'] ?? null) : null;
            }
            foreach ($maps as $map) {
                if (is_array($map)) {
                    $lists[] = $map['success'] ?? null;
                    $lists[] = $map['informational'] ?? null;
                    $lists[] = $map['failure'] ?? null;
                }
            }
        }

        foreach ($lists as $list) {
            foreach (self::codesIn($list) as $code) {
                if (! in_array($code, self::RECORDABLE_CODES, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The reader's own validation of each ingredient, keyed by the URI of the
     * ingredient assertion it is about (`ingredientDeltas[].ingredientAssertionURI`).
     *
     * Two deltas under one key are no evidence (null), and a `failure` that is
     * present but cannot be read counts as a failure (Amendment 2).
     *
     * @param  array<array-key, mixed>  $store
     * @return array<string, array{success: list<string>, failure: list<string>}|null>
     */
    private static function ingredientDeltas(array $store): array
    {
        $results = $store['validation_results'] ?? null;
        $list = is_array($results) ? ($results['ingredientDeltas'] ?? null) : null;
        if (! is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $delta) {
            if (! is_array($delta) || ! isset($delta['ingredientAssertionURI']) || ! is_string($delta['ingredientAssertionURI'])) {
                continue;
            }
            // The key counts as seen before anything else is read, so a
            // malformed delta is still one of two under it; and it is compared
            // normalised (Amendment 3).
            $uri = self::normalisedUri($delta['ingredientAssertionURI']);
            if (array_key_exists($uri, $out)) {
                $out[$uri] = null;

                continue;
            }

            $codes = $delta['validationDeltas'] ?? null;
            if (! is_array($codes)) {
                $out[$uri] = null;

                continue;
            }

            // Present and not a readable list, null included: a failure.
            $failure = array_key_exists('failure', $codes) ? $codes['failure'] : [];
            $failureCodes = self::codesIn($failure);
            if (! is_array($failure) || count($failureCodes) !== count($failure)) {
                $failureCodes[] = '(unreadable failure entry)';
            }

            $out[$uri] = [
                'success' => self::codesIn($codes['success'] ?? null),
                'failure' => $failureCodes,
            ];
        }

        return $out;
    }

    /**
     * An ingredient assertion URI with the leading slash its relative form
     * leaves out: `self#jumbf=c2pa/…` and `self#jumbf=/c2pa/…` name the same
     * assertion (SPEC-045 Amendment 3).
     */
    private static function normalisedUri(string $uri): string
    {
        $prefix = 'self#jumbf=';

        return str_starts_with($uri, $prefix)
            ? $prefix.'/'.ltrim(substr($uri, strlen($prefix)), '/')
            : $uri;
    }

    /**
     * The `code` of each well-formed status entry in a list.
     *
     * @return list<string>
     */
    private static function codesIn(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $codes = [];
        foreach ($list as $entry) {
            if (is_array($entry) && isset($entry['code']) && is_string($entry['code'])) {
                $codes[] = $entry['code'];
            }
        }

        return $codes;
    }

    /**
     * The C2PA specification version the manifest's generator declared, if any
     * (SPEC-035 AC6).
     *
     * Untrusted input, like everything else here: a manifest we did not produce
     * may carry anything at all under this key, so a missing, non-string or
     * empty value yields null rather than an exception. The value is reported
     * verbatim and is deliberately **not** validated as SemVer — this reports
     * what a manifest claims, and silently dropping a malformed claim would hide
     * exactly the thing an operator inspecting a suspect asset wants to see.
     *
     * @param  array<array-key, mixed>  $manifest
     */
    private static function parseDeclaredSpecVersion(array $manifest): ?string
    {
        $info = $manifest['claim_generator_info'] ?? null;
        if (! is_array($info)) {
            return null;
        }

        foreach ($info as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $declared = $entry['specVersion'] ?? null;
            if (is_string($declared) && $declared !== '') {
                return $declared;
            }
        }

        return null;
    }

    /**
     * True iff the active manifest's `signature_info.time` is present and parses
     * as a date-time (SPEC-007 D1/D3). Untrusted input: a missing, empty,
     * non-string or unparseable value yields false, never an exception.
     *
     * @param  array<array-key, mixed>  $manifest
     */
    private static function parseHasTimestamp(array $manifest): bool
    {
        $info = $manifest['signature_info'] ?? null;
        if (! is_array($info)) {
            return false;
        }

        $time = $info['time'] ?? null;
        if (! is_string($time) || $time === '') {
            return false;
        }

        try {
            new \DateTimeImmutable($time);
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     */
    private static function parseSigner(array $manifest): ?SignerInfo
    {
        $info = $manifest['signature_info'] ?? null;
        if (! is_array($info)) {
            return null;
        }

        $issuer = $info['issuer'] ?? null;
        if (! is_string($issuer)) {
            return null;
        }

        $commonName = isset($info['common_name']) && is_string($info['common_name']) ? $info['common_name'] : null;
        $algorithm = isset($info['alg']) && is_string($info['alg']) ? $info['alg'] : null;

        return new SignerInfo($issuer, $commonName, $algorithm);
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     * @return list<array{label: string, data: array<array-key, mixed>}>
     */
    private static function parseAssertions(array $manifest): array
    {
        $assertions = $manifest['assertions'] ?? null;
        if (! is_array($assertions)) {
            return [];
        }

        $out = [];
        foreach ($assertions as $assertion) {
            if (! is_array($assertion)) {
                continue;
            }

            $label = $assertion['label'] ?? null;
            if (! is_string($label)) {
                continue;
            }

            $data = $assertion['data'] ?? [];

            $out[] = ['label' => $label, 'data' => is_array($data) ? $data : []];
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $store
     * @return list<string>
     */
    private static function validationCodes(array $store): array
    {
        $status = $store['validation_status'] ?? null;
        if (! is_array($status)) {
            return [];
        }

        $codes = [];
        foreach ($status as $entry) {
            if (is_array($entry) && isset($entry['code']) && is_string($entry['code'])) {
                $codes[] = $entry['code'];
            }
        }

        return $codes;
    }
}
