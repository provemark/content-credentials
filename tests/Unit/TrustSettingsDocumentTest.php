<?php

declare(strict_types=1);

/**
 * SPEC-014 AC8 — reading stays offline.
 *
 * A read must not make an outbound request, so no third party can delay or fail
 * it. The mechanism is the trust-settings document: OCSP fetching and
 * timestamp-trust verification are the options that would turn a read into a
 * network call, and both are out of scope for SPEC-014.
 *
 * Asserting "no packets left the process" needs egress control this suite does
 * not have, so these pin the cause rather than the effect: the committed
 * document must not enable those options, and must carry the trust material
 * that SPEC-014 AC5 requires the service to insist on. That makes an accidental
 * `ocsp_fetch: true` a failing test rather than a silent latency and
 * availability change.
 *
 * Unit-level: reads a repository file, needs no running service.
 */

/**
 * The committed trust-settings document.
 *
 * @return array<array-key, mixed>
 */
function trustSettingsDocument(): array
{
    $path = dirname(__DIR__, 2).'/certs/c2pa-trust.settings.json';
    $raw = file_get_contents($path);

    if (! is_string($raw)) {
        throw new RuntimeException("cannot read {$path}");
    }

    $decoded = json_decode($raw, true);

    if (! is_array($decoded)) {
        throw new RuntimeException("{$path} does not decode to a JSON object");
    }

    return $decoded;
}

/**
 * One top-level block of the document, or an empty array when absent or not an
 * object — absence and malformedness are the same thing to these assertions.
 *
 * @param  array<array-key, mixed>  $document
 * @return array<array-key, mixed>
 */
function trustSettingsSection(array $document, string $key): array
{
    $section = $document[$key] ?? null;

    return is_array($section) ? $section : [];
}

it('does not enable any option that would make a read hit the network', function () {
    $verify = trustSettingsSection(trustSettingsDocument(), 'verify');

    foreach (['ocsp_fetch', 'verify_timestamp_trust', 'remote_manifest_fetch'] as $option) {
        expect($verify[$option] ?? false)->toBeFalse(
            "{$option} is enabled in certs/c2pa-trust.settings.json — SPEC-014 keeps reads offline"
        );
    }
})->group('SPEC-014');

it('carries trust material that would actually verify', function () {
    $document = trustSettingsDocument();
    $verify = trustSettingsSection($document, 'verify');
    $trust = trustSettingsSection($document, 'trust');

    expect($verify['verify_trust'] ?? false)->toBeTrue();

    $anchors = $trust['trust_anchors'] ?? null;
    $allowed = $trust['allowed_list'] ?? null;

    $hasAnchors = is_string($anchors) && $anchors !== '';
    $hasAllowed = is_string($allowed) && $allowed !== '';

    // AC5's condition, asserted on the document the repo ships: verify_trust
    // alone is a silent no-op without anchors or an allowed list to verify
    // against (NOTES.md Step 11).
    expect($hasAnchors || $hasAllowed)
        ->toBeTrue('neither trust_anchors nor allowed_list carries material');

    // Contents, not paths — a path throws "could not parse configuration"
    // (NOTES.md Step 11).
    if ($hasAnchors) {
        expect($anchors)->toContain('-----BEGIN CERTIFICATE-----');
    }
})->group('SPEC-014');
