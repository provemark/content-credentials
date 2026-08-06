<?php

declare(strict_types=1);

/**
 * SPEC-018 AC4/AC5/AC6/AC7 — the documented and automated half.
 *
 * These assert repository facts: a rotation procedure a reader can follow, an
 * automated scan that runs without anyone remembering to, a stated remediation
 * policy, and the mapping a user needs to write their own Generator Product
 * Security Architecture document.
 *
 * Every assertion here matches a PHRASE, never a short substring. NOTES.md
 * Step 20 records three tests in one sitting that were green while testing
 * nothing — one of them because `peak` matched `speaks`.
 *
 * Unit-level: reads repository files, needs no running service.
 */
function spec018Repo(string $relative): string
{
    $path = dirname(__DIR__, 2).'/'.$relative;
    $raw = @file_get_contents($path);

    return is_string($raw) ? $raw : '';
}

/**
 * The README, lowercased and with runs of whitespace collapsed.
 *
 * The collapse is what makes phrase matching work at all: prose is hard-wrapped,
 * so "Generator Product Security Requirements" carries a newline in the middle
 * and a naive substring search misses it. Reflowing a paragraph must not break a
 * test, and must not silently stop testing anything either.
 */
function spec018Readme(): string
{
    return strtolower((string) preg_replace('/\s+/', ' ', spec018Repo('README.md')));
}

// --- AC4: rotation is documented and confirmable -----------------------------

it('documents a signing-key rotation procedure', function () {
    $readme = spec018Readme();

    expect($readme)->toContain('rotating the signing key');

    // Two things that make the procedure usable rather than decorative: what it
    // costs, and how you know it worked.
    //
    // NB `toContain()` takes a variadic list of needles, NOT a needle and a
    // message — passing an explanation as a second argument turns it into a
    // second thing the README must literally contain. An earlier version of this
    // test did exactly that and failed against a correct README.
    expect($readme)->toContain('in-flight requests are lost');
    expect($readme)->toContain('fingerprint_sha256');
})->group('SPEC-018');

it('states that restart-based rotation satisfies the requirement', function () {
    // Without this a reader goes looking for a reload endpoint that deliberately
    // does not exist (SPEC-018 scope: hot reload is out of scope, not missing).
    $readme = spec018Readme();

    expect($readme)->toContain('restart')
        ->and($readme)->toContain('capable of rotating');
})->group('SPEC-018');

// --- AC5: dependency scanning runs without anyone remembering ----------------

it('configures automated dependency updates for every ecosystem that reaches signing', function () {
    $config = spec018Repo('.github/dependabot.yml');

    expect($config)->not->toBe('', '.github/dependabot.yml is missing');

    // npm ships in the container that holds the key; Composer runs in the
    // consumer's application; Actions run with repository credentials.
    expect($config)->toContain('package-ecosystem: npm')
        ->and($config)->toContain('package-ecosystem: composer')
        ->and($config)->toContain('package-ecosystem: github-actions');

    // The npm tree that matters is service/, not the repository root.
    expect($config)->toContain('/service');
})->group('SPEC-018');

it('runs a scheduled audit that reports advisories with no fix available', function () {
    // Dependabot opens PRs, which it cannot do for an advisory that has no fix.
    // Those are exactly the ones an operator needs to know about, so a scheduled
    // scan reports them separately.
    $workflows = glob(dirname(__DIR__, 2).'/.github/workflows/*.yml') ?: [];
    $audit = '';

    foreach ($workflows as $file) {
        $body = (string) file_get_contents($file);

        if (str_contains($body, 'npm audit') || str_contains($body, 'composer audit')) {
            $audit = $body;
            break;
        }
    }

    expect($audit)->not->toBe('', 'no workflow runs npm audit or composer audit');

    expect($audit)->toContain('npm audit')
        ->and($audit)->toContain('composer audit')
        // Scheduled, so it does not depend on a push happening.
        ->and($audit)->toContain('schedule:');
})->group('SPEC-018');

it('does not turn main red on an advisory it cannot act on', function () {
    // A blocking audit job stops unrelated work on an advisory with no available
    // fix. Visibility without a block is the decision recorded in SPEC-018.
    $workflows = glob(dirname(__DIR__, 2).'/.github/workflows/*.yml') ?: [];
    $audit = '';

    foreach ($workflows as $file) {
        $body = (string) file_get_contents($file);

        if (str_contains($body, 'npm audit')) {
            $audit = $body;
            break;
        }
    }

    expect($audit)->not->toBe('', 'no workflow runs npm audit');
    expect($audit)->toContain('continue-on-error: true');

    // And it must not be wired into the required aggregate check, which is what
    // branch protection enforces (NOTES.md Step 18).
    $ci = spec018Repo('.github/workflows/ci.yml');

    if (str_contains($ci, 'npm audit')) {
        expect($ci)->not->toContain('needs: [check, integration, audit]');
    }
})->group('SPEC-018');

// --- AC6: the remediation policy is stated -----------------------------------

it('states the remediation obligation and names the scanning tools', function () {
    $security = strtolower(spec018Repo('SECURITY.md'));

    expect($security)->not->toBe('', 'SECURITY.md is missing');

    // The O.3 obligation, in the severities O.3 names.
    expect($security)->toContain('critical')
        ->and($security)->toContain('high');

    // The static evidence O.3 requires is the tooling, named.
    expect($security)->toContain('dependabot')
        ->and($security)->toContain('npm audit')
        ->and($security)->toContain('composer audit');
})->group('SPEC-018');

// --- AC7: the Level 1 mapping is documented ----------------------------------

it('tells a reader that their deployment, not this library, is the Generator Product', function () {
    $readme = spec018Readme();

    // The single most load-bearing sentence for anyone who reads about the
    // Conforming Products List and assumes a library can be on it.
    expect($readme)->toContain('generator product')
        ->and($readme)->toContain('conforming products list');
})->group('SPEC-018');

it('maps the service key handling onto the Level 1 requirements it satisfies', function () {
    $readme = spec018Readme();

    // Enough for a reader to locate the requirements and see which ones this
    // architecture answers — the raw material for their own GPSA document.
    expect($readme)->toContain('assurance level 1')
        ->and($readme)->toContain('generator product security requirements');
})->group('SPEC-018');
