<?php

declare(strict_types=1);

/**
 * SPEC-027 — a README you can read in one sitting.
 *
 * The README had grown to 866 lines by accretion. These criteria guard the shape
 * of the split rather than its content: AC3 (nothing is lost) is held by the
 * doc tests that moved with their text, not by anything here.
 *
 * @see specs/SPEC-027-documentation-layout.md
 */

/**
 * The five pages this spec creates. The primer and the ADRs predate it.
 *
 * @return list<string>
 */
function spec027Pages(): array
{
    return ['usage.md', 'service.md', 'marking.md', 'readers.md', 'production.md'];
}

function spec027Root(): string
{
    return dirname(__DIR__, 2);
}

// --- AC1: the README is a map, not the territory ----------------------------

it('keeps the README short enough to read in one sitting', function () {
    $lines = substr_count((string) file_get_contents(spec027Root().'/README.md'), "\n");

    // 300 rather than something tighter: the quickstart is ~a third of it and is
    // what the reader came for. Shortening that would optimise the number.
    expect($lines)->toBeLessThan(300);
})->group('SPEC-027');

it('links to every page it sends the reader to', function () {
    $readme = (string) file_get_contents(spec027Root().'/README.md');

    foreach (spec027Pages() as $page) {
        expect($readme)->toContain("docs/{$page}");
    }
})->group('SPEC-027');

// --- AC2: no link points at nothing (error path) ----------------------------

/**
 * The GitHub-style anchor a heading generates: lowercased, punctuation dropped,
 * spaces to hyphens.
 *
 * @return list<string>
 */
function spec027Anchors(string $file): array
{
    $anchors = [];

    foreach (explode("\n", (string) file_get_contents($file)) as $line) {
        if (preg_match('/^#{1,6}\s+(.*)$/', $line, $m) !== 1) {
            continue;
        }

        $text = strtolower(trim($m[1]));
        $text = (string) preg_replace('/[`*_\[\]()]/', '', $text);
        $text = (string) preg_replace('/[^a-z0-9\s-]/', '', $text);
        $anchors[] = trim((string) preg_replace('/\s+/', '-', $text), '-');
    }

    return $anchors;
}

it('resolves every relative link in the documentation', function () {
    $root = spec027Root();
    $files = array_merge([$root.'/README.md'], glob($root.'/docs/*.md') ?: []);

    $broken = [];

    foreach ($files as $file) {
        preg_match_all('/\]\(([^)\s]+)\)/', (string) file_get_contents($file), $matches);

        foreach ($matches[1] as $target) {
            if (str_starts_with($target, 'http') || str_starts_with($target, 'mailto:')) {
                continue;
            }

            [$relative, $anchor] = array_pad(explode('#', $target, 2), 2, '');

            // An empty file part means "this page" — the case the first version
            // of this test skipped as "somebody else's problem". The move made
            // it this test's problem: three in-page anchors pointed at sections
            // that had left for another file, and nothing noticed.
            $path = $relative === '' ? $file : dirname($file).'/'.$relative;

            if (! file_exists($path)) {
                $broken[] = basename($file).' -> '.$target.' (no such file)';

                continue;
            }

            if ($anchor !== '' && ! in_array($anchor, spec027Anchors($path), true)) {
                $broken[] = basename($file).' -> '.$target.' (no such heading)';
            }
        }
    }

    // Link rot is what this reorganisation was most likely to introduce, and the
    // one nobody notices: a broken link renders as text and looks deliberate.
    expect($broken)->toBe([]);
})->group('SPEC-027');

// --- AC4: the docs ship with the package ------------------------------------

/** Whether git would exclude this path from `git archive`, i.e. from the dist. */
function spec027ExportIgnored(string $path): bool
{
    $out = (string) shell_exec(sprintf(
        'cd %s && git check-attr export-ignore -- %s 2>/dev/null',
        escapeshellarg(spec027Root()),
        escapeshellarg($path),
    ));

    return str_contains($out, ': set');
}

it('ships the documentation in the Composer package', function () {
    // `git check-attr` rather than `git archive`: it asks git the same question
    // the archiver asks, but against the working tree. `git archive HEAD` reads
    // the .gitattributes of the last COMMIT, so it cannot see this change until
    // after it is committed — a test that can only pass post-commit is one you
    // never watch go red.
    expect(spec027ExportIgnored('docs'))->toBeFalse();

    foreach (spec027Pages() as $page) {
        expect(spec027ExportIgnored("docs/{$page}"))->toBeFalse();
    }
})->group('SPEC-027');

it('still leaves the developer-only directories out of the package', function () {
    // Shipping docs/ must not have shipped everything else with it. Asked of
    // the directories, because that is where .gitattributes sets the attribute —
    // a file inside one answers "unspecified", which would make this pass for
    // the wrong reason.
    foreach (['tests', 'specs', 'service', 'bin', 'certs'] as $directory) {
        expect(spec027ExportIgnored($directory))->toBeTrue();
    }
})->group('SPEC-027');

// --- AC5: each page stands on its own ---------------------------------------

it('opens each page with what it covers and a way back', function (string $page) {
    $text = (string) file_get_contents(spec027Root().'/docs/'.$page);
    $head = substr($text, 0, 600);

    expect($head)->toStartWith('# ')
        // A deep link or a search result delivers someone here directly, with no
        // idea what package this is.
        ->and($head)->toContain('](../README.md')
        ->and(strlen(trim($text)))->toBeGreaterThan(400);
})->with(array_map(fn (string $page): array => [$page], spec027Pages()))->group('SPEC-027');
