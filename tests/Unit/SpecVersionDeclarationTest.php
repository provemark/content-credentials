<?php

declare(strict_types=1);

/**
 * SPEC-035 — the service-side half of declaring a C2PA specification version.
 *
 * These read `service/server.js` rather than call a running service, because
 * both criteria here are about what the service does **before** it can answer
 * anything:
 *
 * - **AC3** is about a path that must not exist. A running service cannot
 *   demonstrate the absence of a way in; the source can.
 * - **AC4** is about refusing to start. There is nothing to send a request to,
 *   so the alternative would be a CI profile whose service never comes up and
 *   which could therefore run no other test — one profile for one assertion.
 *
 * Source greps are the shape most prone to passing while testing nothing (see
 * the five documented cases in this repository), so each one below asserts that
 * a specific string is **present**, and the accompanying integration tests cover
 * the behaviour itself.
 *
 * @see specs/SPEC-035-declaring-a-spec-version.md
 */
function spec035ServiceSource(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/service/server.js');
}

// --- AC3: the declaration is ours, and a caller cannot reach it --------------

it('builds claim_generator_info from fixed keys rather than from caller input', function () {
    $source = spec035ServiceSource();

    // The audit found AC3 already true by construction: the object is a literal
    // and only `name` comes from the caller. This pins that, because the way to
    // break it is a refactor that spreads the request body in — which would look
    // tidier and would hand a caller the declaration.
    expect($source)->toContain('claim_generator_info: [')
        ->and($source)->toContain('specVersion: SPEC_VERSION');

    // No spread of caller-controlled data into the generator info.
    $block = substr($source, (int) strpos($source, 'claim_generator_info: ['), 200);
    expect($block)->not->toContain('...req.body')
        ->and($block)->not->toContain('...body');
})->group('SPEC-035');

// --- AC4: a malformed declared version stops the service at startup ----------

it('validates the declared version as SemVer, not against a list of known versions', function () {
    $source = spec035ServiceSource();

    // Shape, not membership: a list would need editing every time C2PA
    // publishes, and would reject a valid declaration for being unfamiliar.
    expect($source)->toContain('function assertSemVerSpecVersion');
})->group('SPEC-035');

it('refuses to start on a declared version that is not SemVer', function () {
    $source = spec035ServiceSource();

    // The guard is worth nothing if the startup path never calls it — the same
    // reasoning as the four greps that prove the ext-c2pa CI leg actually ran
    // its tests. `"2.3"` is the case that matters: it is the shape a reader
    // reaches for first, and it is exactly what 2.3 §10.2.2 forbids.
    expect($source)->toContain('assertSemVerSpecVersion(SPEC_VERSION)');
})->group('SPEC-035');
// --- AC7: the engine the audit was made against is pinned -------------------

it('still runs on the engine version the specification audit was made against', function () {
    $package = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/service/package.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $dependencies = is_array($package) ? ($package['dependencies'] ?? null) : null;
    $pinned = is_array($dependencies) ? ($dependencies['@contentauth/c2pa-node'] ?? null) : null;

    // A failure here is a PROMPT, not a defect. The SPEC-035/036 audit
    // established that 2.4.0 is the highest version we can declare truthfully,
    // and it did so against this engine. A newer one can change what we emit —
    // including the created_assertions placement 2.4 §18.15.2 requires — so the
    // declaration has to be re-audited before the bump lands, and this is what
    // says so. Re-audited on 2026-09-03 for 0.9.1 -> 0.9.3 (c2pa-rs 0.90.15 ->
    // 0.90.16): specVersion still emitted as `2.4.0`, the actions assertion
    // still lands in created_assertions and still reads back `created: true`.
    //
    // Reads a committed file rather than a running service on purpose: a check
    // conditioned on a profile or a local binary would report `skipped`
    // everywhere and never go red, which is the failure this criterion replaced.
    expect($pinned)->toBe('0.9.3', 'engine bumped — re-run the SPEC-035 audit before declaring 2.4.0 still true');
})->group('SPEC-035');
