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

        return new ManifestReport(
            $activeLabel,
            self::parseSigner($active),
            self::parseAssertions($active),
            self::validationCodes($store),
            $state,
            self::parseHasTimestamp($active),
            self::parseDeclaredSpecVersion($active),
        );
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
