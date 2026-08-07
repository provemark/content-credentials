<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;

/**
 * SPEC-022 — a builder entry point that says what it does.
 *
 * `forAiGeneratedImage(MediaType::Mp4)` is the call SPEC-021 created. The name
 * is kept working forever (settled at approval); `forAiGenerated()` is the
 * canonical one.
 *
 * Nothing about the manifest changes — AC3 is here to prove that rather than
 * assume it, because a rename that silently altered the Article 50 marking is
 * the only real risk in this spec.
 *
 * @see specs/SPEC-022-builder-entry-point-name.md
 */

/**
 * The single AI-generated actions assertion, as SPEC-001 AC1 fixes it.
 *
 * @return array{label: string, data: array{actions: list<array{action: string, digitalSourceType: string, softwareAgent: array{name: string, version: string}}>}}
 */
function spec022AiAssertion(string $agent, string $version): array
{
    return [
        'label' => 'c2pa.actions.v2',
        'data' => [
            'actions' => [[
                'action' => 'c2pa.created',
                'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
                'softwareAgent' => ['name' => $agent, 'version' => $version],
            ]],
        ],
    ];
}

// --- AC1: the two names produce the same manifest, for every media type -----
// Derived from MediaType::cases(), not two hand-picked examples: a pair would
// pass while a third case diverged, and the enum is nine long and growing.

it('produces the same manifest under either name, for every media type', function () {
    foreach (MediaType::cases() as $type) {
        $canonical = ManifestBuilder::forAiGenerated($type)
            ->withSoftwareAgent('ACME GenAI', '3.1.0')
            ->withClaimGenerator('Provemark', '1.0.0')
            ->build()
            ->toArray();

        $legacy = ManifestBuilder::forAiGeneratedImage($type)
            ->withSoftwareAgent('ACME GenAI', '3.1.0')
            ->withClaimGenerator('Provemark', '1.0.0')
            ->build()
            ->toArray();

        expect($canonical)->toBe($legacy)
            // …and the format really is the one that was asked for, so an
            // equality that held because both were wrong would still fail.
            ->and($canonical['format'])->toBe($type->value);
    }
})->group('SPEC-022');

// --- AC2: the old name still works (back-compatibility) ---------------------
// This test deliberately calls the OLD name. The rest of the suite migrated to
// the new one; a suite that no longer exercises the alias cannot detect it
// breaking.

it('still accepts code written against the previous name', function () {
    $manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Png)
        ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
        ->build();

    expect($manifest->mediaType())->toBe(MediaType::Png)
        ->and($manifest->toArray())->toBe([
            'format' => 'image/png',
            'assertions' => [spec022AiAssertion('ACME GenAI Image Model', '3.1.0')],
        ]);
})->group('SPEC-022');

it('returns a builder from the old name, so the fluent chain is unbroken', function () {
    // The alias must return the same type, not merely something that works for
    // one chain: user code may hold it in a variable and branch.
    expect(ManifestBuilder::forAiGeneratedImage(MediaType::Jpeg))
        ->toBeInstanceOf(ManifestBuilder::class);
})->group('SPEC-022');

// --- AC3: the manifest is unchanged -----------------------------------------

it('emits exactly the manifest SPEC-001 fixes', function () {
    // Whole-array equality against the shape SPEC-001 AC1 pins: one assertion,
    // one c2pa.created action, the full IPTC URI, the softwareAgent, and no
    // other key. An added key fails this too.
    $arr = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
        ->build()
        ->toArray();

    expect($arr)->toBe([
        'format' => 'image/png',
        'assertions' => [spec022AiAssertion('ACME GenAI Image Model', '3.1.0')],
    ]);
})->group('SPEC-022');

// --- AC4: no runtime deprecation is emitted (error path) --------------------

it('raises no runtime deprecation from the old name', function () {
    // An alias we intend to keep must not shout. Applications that promote
    // notices to exceptions — and PHPUnit does exactly that for deprecations by
    // default — would break on a purely cosmetic change.
    //
    // The handler converts anything raised into a failure rather than trusting
    // the ambient error configuration, which a test run may already have
    // relaxed.
    $raised = [];

    set_error_handler(static function (int $errno, string $message) use (&$raised): bool {
        $raised[] = "{$errno}: {$message}";

        return true;
    });

    try {
        ManifestBuilder::forAiGeneratedImage(MediaType::Mp4)
            ->withSoftwareAgent('ACME GenAI')
            ->build();
    } finally {
        restore_error_handler();
    }

    expect($raised)->toBe([]);
})->group('SPEC-022');

it('sees the handler fire when something does raise, so the check is real', function () {
    // The control case. Without it, AC4 above would pass just as happily
    // against a handler that was never installed — green while testing nothing,
    // which this repository has documented six times (NOTES Steps 18, 20, 21, 23).
    $raised = [];

    set_error_handler(static function (int $errno, string $message) use (&$raised): bool {
        $raised[] = "{$errno}: {$message}";

        return true;
    });

    try {
        trigger_error('control', E_USER_DEPRECATED);
    } finally {
        restore_error_handler();
    }

    expect($raised)->toHaveCount(1)
        ->and($raised[0])->toContain('control');
})->group('SPEC-022');

// --- AC5: the old name is marked, and no longer shown -----------------------

it('marks the old name as superseded and names its replacement', function () {
    $method = new ReflectionMethod(ManifestBuilder::class, 'forAiGeneratedImage');
    $doc = (string) $method->getDocComment();

    expect($doc)->toContain('@deprecated')
        ->and($doc)->toContain('forAiGenerated(')
        // Settled at approval: the alias stays. The docblock must say that,
        // because a bare @deprecated reads as "will be removed" and would send
        // people migrating under a deadline that does not exist.
        ->and(strtolower($doc))->toContain('kept indefinitely');
})->group('SPEC-022');

it('shows only the canonical name in the documentation and examples', function () {
    $root = dirname(__DIR__, 3);

    $files = [
        $root.'/README.md',
        $root.'/bin/e2e.php',
        $root.'/docs/c2pa-primer.md',
    ];

    foreach ($files as $file) {
        $text = (string) file_get_contents($file);

        expect($text)->not->toContain('forAiGeneratedImage(');
    }

    // And the new name is actually shown, so "no old name" cannot be satisfied
    // by an example that stopped mentioning the builder at all.
    expect((string) file_get_contents($root.'/README.md'))->toContain('forAiGenerated(');
})->group('SPEC-022');

it('uses the canonical name where the package calls itself', function () {
    // The Laravel job and command are examples too: they are the code a user
    // reads when they want to know how this is meant to be called.
    $root = dirname(__DIR__, 3);

    foreach (['/src/Laravel/Jobs/SignAssetJob.php', '/src/Laravel/Console/SignCommand.php'] as $file) {
        expect((string) file_get_contents($root.$file))->not->toContain('forAiGeneratedImage(');
    }
})->group('SPEC-022');
