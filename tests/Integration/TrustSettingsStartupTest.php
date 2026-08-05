<?php

declare(strict_types=1);

/**
 * SPEC-014 AC4/AC5 — the service refuses to start on trust settings it cannot
 * use, instead of starting with verification silently disabled.
 *
 * These drive the entrypoint rather than the HTTP API, because the behaviour
 * under test *is* startup. Each case starts a second `node server.js` inside the
 * already-running container under `timeout`, pointed at a path that exists in
 * the image, so nothing is mounted, built, or left behind:
 *
 *   /nowhere/trust.json   missing                                     → AC4
 *   /app/server.js        not a JSON document                         → AC4
 *   /app/package.json     parses, but carries no verify/trust block   → AC5
 *
 * Two details that make the result trustworthy:
 *
 * - `PORT` is overridden. Without it the second process would hit EADDRINUSE
 *   against the live service and exit non-zero for the *wrong* reason, which
 *   would make these tests pass without the feature existing.
 * - Exit **124** is `timeout` killing a process that was still running, i.e.
 *   the service started serving with unusable settings. That is the failure
 *   these criteria forbid, so 124 is a test failure, not a timeout error.
 *
 * AC5 is the one that matters most. Verified 2026-08-05 (NOTES.md Step 11):
 * `{verify: {verify_trust: true}, trust: {}}` produces no error and no
 * verification — a read returns `Valid` + `signingCredential.untrusted`,
 * byte-identical to configuring nothing. So a settings document that merely
 * parses proves nothing, and an operator who believes trust is on cannot tell
 * from the outside that it is not.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 */
/** The running signing-service container id, or null when it is not up. */
function spec014ServiceContainer(): ?string
{
    foreach (['docker compose', 'docker-compose'] as $binary) {
        $raw = shell_exec($binary.' ps -q service 2>/dev/null');
        $id = is_string($raw) ? trim($raw) : '';

        if ($id !== '') {
            return $id;
        }
    }

    return null;
}

$skipUnlessContainer = fn () => spec014ServiceContainer() === null
    ? 'signing-service container not running — start it with docker compose up -d'
    : false;

/**
 * Start the service once with an overridden trust-settings path.
 *
 * @return array{exit: int, output: string}
 */
$startWith = function (string $settingsPath, int $deadlineSeconds = 5): array {
    $container = spec014ServiceContainer();

    if ($container === null) {
        return ['exit' => -1, 'output' => 'signing-service container not running'];
    }

    $inner = sprintf(
        'cd /app && PORT=3999 CONTENTAUTH_TRUST_SETTINGS=%s timeout %d node server.js 2>&1; echo "EXIT=$?"',
        escapeshellarg($settingsPath),
        $deadlineSeconds,
    );

    $raw = shell_exec(sprintf(
        'docker exec %s sh -c %s 2>&1',
        escapeshellarg($container),
        escapeshellarg($inner),
    ));

    $output = is_string($raw) ? $raw : '';
    preg_match('/EXIT=(\d+)/', $output, $matches);

    return ['exit' => (int) ($matches[1] ?? -1), 'output' => $output];
};

/**
 * Pest-readable explanation of how the process ended.
 *
 * @param  array{exit: int, output: string}  $result
 */
function spec014Describe(array $result): string
{
    return $result['exit'] === 124
        ? 'the service kept running and had to be killed — it started serving with unusable trust settings'
        : sprintf('exit %d; output: %s', $result['exit'], trim($result['output']));
}

// --- AC4: an unusable settings path stops the service -----------------------

it('refuses to start when the trust settings path does not exist', function () use ($startWith) {
    $result = $startWith('/nowhere/trust.json');

    expect($result['exit'])->not->toBe(124, spec014Describe($result))
        ->and($result['exit'])->not->toBe(0, spec014Describe($result))
        ->and($result['output'])->toContain('/nowhere/trust.json');
})->group('SPEC-014', 'integration')->skip($skipUnlessContainer);

it('refuses to start when the trust settings file is not a settings document', function () use ($startWith) {
    $result = $startWith('/app/server.js');

    expect($result['exit'])->not->toBe(124, spec014Describe($result))
        ->and($result['exit'])->not->toBe(0, spec014Describe($result));
})->group('SPEC-014', 'integration')->skip($skipUnlessContainer);

// --- AC5: settings that parse but would verify nothing ----------------------

it('refuses to start on settings that parse but could never verify trust', function () use ($startWith) {
    // package.json is valid JSON and carries neither a verify nor a trust block,
    // so it is the "parses, verifies nothing" case in its purest form.
    $result = $startWith('/app/package.json');

    expect($result['exit'])->not->toBe(124, spec014Describe($result))
        ->and($result['exit'])->not->toBe(0, spec014Describe($result));
})->group('SPEC-014', 'integration')->skip($skipUnlessContainer);
