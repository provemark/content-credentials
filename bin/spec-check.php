#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * spec-check — spec-to-test traceability, v2.
 *
 * Reads specs/*.md, cross-references them with the test suite, and reports the
 * gaps a spec-driven lifecycle develops silently: a criterion with no row, a
 * spec marked `implemented` with an empty cell, a test claiming a criterion
 * that does not exist.
 *
 * ---------------------------------------------------------------------------
 * THE RULE THIS VERSION EXISTS FOR
 *
 * A check may only judge what it understood. v1 turned parse failures into
 * content conclusions: a row regex that did not match was reported as "no
 * traceability row", a group regex that missed multi-argument calls as "no test
 * carries this group", and an empty set of resolved references as thirty-six
 * orphaned source files. All three were the same mistake — absence of parsed
 * data treated as evidence of absence — and all three were wrong.
 *
 * So: every check states whether its input was understood before it judges.
 * Not understood is UNRESOLVED with the literal text, never ERROR. And the
 * report leads with coverage, because a run that parsed nothing and a run that
 * found nothing look identical otherwise.
 * ---------------------------------------------------------------------------
 *
 * Standalone: no dependencies, no autoloader. `php spec-check.php [path]`.
 */

// ------------------------------------------------------------------ model ---

final class Finding
{
    public function __construct(
        public string $level,   // ERROR | WARNING | UNRESOLVED
        public string $spec,
        public string $message,
    ) {}
}

final class Reference
{
    public function __construct(
        public string $kind,    // path | title | symbol
        public string $value,
    ) {}
}

final class Criterion
{
    public string $id;

    /** @var list<Reference> */
    public array $testRefs = [];

    /** @var list<Reference> */
    public array $sourceRefs = [];

    public function __construct(
        public int $number,
        public string $title,
        public bool $isRedirect,
        public ?string $testCell = null,
        public ?string $sourceCell = null,
        public bool $hasRow = false,
    ) {
        $this->id = 'AC'.$number;
    }
}

final class Spec
{
    /** @var array<int, Criterion> */
    public array $criteria = [];

    public bool $behaviorSectionFound = false;

    public bool $traceabilitySectionFound = false;

    public int $rowsSeen = 0;          // rows in the traceability table, however shaped

    public int $rowsMatched = 0;       // rows we could attribute to an AC number

    public function __construct(
        public string $file,
        public string $id,
        public string $status,
        public string $statusRaw,
        public bool $scheduled,
    ) {}
}

// ---------------------------------------------------------------- parsing ---

function parseSpec(string $path): Spec
{
    $text = (string) file_get_contents($path);
    $base = basename($path);

    preg_match('/^SPEC-(\d+)/', $base, $m);
    $id = $m[0] ?? $base;

    $statusRaw = 'unknown';
    if (preg_match('/^\|\s*Status\s*\|\s*(.+?)\s*\|/m', $text, $m)) {
        $statusRaw = trim($m[1]);
    }
    $status = 'unknown';
    foreach (['implemented', 'approved', 'superseded', 'draft'] as $candidate) {
        if (stripos($statusRaw, $candidate) === 0) {
            $status = $candidate;
            break;
        }
    }
    $scheduled = stripos($statusRaw, 'not scheduled') === false;

    $spec = new Spec($path, $id, $status, $statusRaw, $scheduled);

    // --- criteria, from the Behavior section ---
    if (preg_match('/^##\s+Behavio(?:u)?r\s*$(.*?)(?=^##\s|\z)/ms', $text, $m)) {
        $spec->behaviorSectionFound = true;
        preg_match_all('/^-\s+\*\*AC(\d+)\s*[—–-]?\s*(.*?)\*\*/m', $m[1], $all, PREG_SET_ORDER);
        foreach ($all as $hit) {
            $n = (int) $hit[1];
            $title = trim($hit[2]);
            $redirect = (bool) preg_match('/\b(removed|moved to|superseded|deleted)\b/i', $title);
            $spec->criteria[$n] = new Criterion($n, $title, $redirect);
        }
    }

    // --- traceability rows ---
    // Liberal on the first cell: `| AC1 |`, `| AC1 (#5 over-limit) |`,
    // `| AC1 — manifest-less read is empty |` all attribute to AC1.
    if (preg_match('/^##\s+Traceability\s*$(.*?)(?=^##\s|\z)/ms', $text, $m)) {
        $spec->traceabilitySectionFound = true;
        foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '|') {
                continue;
            }
            if (preg_match('/^\|[\s:|-]+\|$/', $line)) {
                continue;   // separator row
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            if ($cells === [] || stripos($cells[0], 'acceptance criterion') === 0) {
                continue;   // header row
            }
            $spec->rowsSeen++;
            if (! preg_match('/^AC\s*(\d+)\b/i', $cells[0], $hit)) {
                continue;   // a row whose first cell we do not recognise — counted, not judged
            }
            $spec->rowsMatched++;
            $n = (int) $hit[1];
            if (! isset($spec->criteria[$n])) {
                $spec->criteria[$n] = new Criterion($n, '(no Behavior entry)', false);
            }
            $c = $spec->criteria[$n];
            $c->hasRow = true;
            $c->testCell = $cells[1] ?? '';
            $c->sourceCell = $cells[2] ?? '';
            $c->testRefs = extractReferences($c->testCell);
            $c->sourceRefs = extractReferences($c->sourceCell);
        }
    }

    return $spec;
}

/**
 * Pull out what a cell actually names. Specs in the wild name three things:
 * file paths, quoted test titles, and code symbols. Only the first two are
 * verifiable; a symbol is recorded so the report can say so.
 *
 * @return list<Reference>
 */
function extractReferences(string $cell): array
{
    $refs = [];

    // `path/to/File.php`, `service/server.js` — anything with an extension
    if (preg_match_all('/`([^`]*\.(?:php|js|ts|py|rb|go))`/i', $cell, $m)) {
        foreach ($m[1] as $p) {
            $refs[] = new Reference('path', $p);
        }
    }

    // "a quoted test title" — long enough not to be an incidental quote
    if (preg_match_all('/"([^"]{15,})"/', $cell, $m)) {
        foreach ($m[1] as $t) {
            $refs[] = new Reference('title', $t);
        }
    }

    // `Class::method()` or `Class::$property` — a symbol, not a file
    if (preg_match_all('/`([A-Za-z_\\\\][\w\\\\]*::[\$\w]+(?:\(\))?)`/', $cell, $m)) {
        foreach ($m[1] as $s) {
            $refs[] = new Reference('symbol', $s);
        }
    }

    // Some specs write the test title as the whole cell, unquoted:
    //   | AC1 | rejects an over-limit response before decoding | ... |
    // If nothing else was found, treat the cell itself as a candidate title.
    // It may still be prose ("deptrac (`composer check`)"), so a miss is
    // UNRESOLVED, never an error.
    if ($refs === []) {
        $bare = trim((string) preg_replace('/\((?:[^()]|\([^()]*\))*\)\s*$/', '', $cell));
        $bare = trim(str_replace('`', '', $bare));
        if (strlen($bare) >= 15) {
            $refs[] = new Reference('bare-title', $bare);
        }
    }

    return $refs;
}

final class Suite
{
    /** @param array<string, list<string>> $groups group => files */
    /** @param list<string> $titles every it()/test() title in the suite */
    public function __construct(
        public array $groups,
        public array $titles,
        public int $filesScanned,
    ) {}
}

function scanSuite(array $roots): Suite
{
    $groups = [];
    $titles = [];
    $files = 0;

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $files++;
            $text = (string) file_get_contents($file->getPathname());

            // ->group('a') and ->group('a', 'b') and ->group("a")
            if (preg_match_all('/->group\(\s*([^)]*)\)/', $text, $m)) {
                foreach ($m[1] as $args) {
                    if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $args, $inner)) {
                        foreach ($inner[1] as $g) {
                            $groups[$g][] = $file->getPathname();
                        }
                    }
                }
            }

            // it('title') / test('title') — Pest, and the PHPUnit method form
            if (preg_match_all('/\b(?:it|test)\(\s*[\'"]([^\'"]+)[\'"]/', $text, $m)) {
                foreach ($m[1] as $t) {
                    $titles[] = $t;
                }
            }
        }
    }

    return new Suite(
        array_map(static fn (array $f): array => array_values(array_unique($f)), $groups),
        array_values(array_unique($titles)),
        $files,
    );
}

/** Spec cells truncate titles with an ellipsis, so match on prefix. */
function titleExistsInSuite(string $wanted, Suite $suite): bool
{
    $needle = rtrim(trim($wanted), ". \u{2026}");
    if ($needle === '') {
        return false;
    }
    foreach ($suite->titles as $actual) {
        if (str_starts_with($actual, $needle) || str_starts_with($needle, rtrim($actual, ". \u{2026}"))) {
            return true;
        }
    }

    return false;
}

function cellIsEmpty(?string $cell): bool
{
    $c = trim((string) $cell);

    return $c === '' || $c === '—' || $c === '–' || $c === '-' || $c === 'n/a' || $c === 'TBD';
}

// ------------------------------------------------------------------- main ---

$root = rtrim($argv[1] ?? getcwd(), '/');
if (! is_dir($root)) {
    fwrite(STDERR, "spec-check: not a directory: {$root}\n");
    exit(2);
}
chdir($root);

$specFiles = glob('specs/SPEC-*.md') ?: [];
if ($specFiles === []) {
    fwrite(STDERR, "spec-check: no specs/SPEC-*.md found in {$root}\n");
    exit(2);
}

/** @var list<Spec> $specs */
$specs = array_map('parseSpec', $specFiles);
$byId = [];
foreach ($specs as $s) {
    $byId[$s->id] = $s;
}

$suite = scanSuite(['tests', 'examples', 'test']);

/** @var list<Finding> $findings */
$findings = [];
$add = static function (string $level, string $spec, string $message) use (&$findings): void {
    $findings[] = new Finding($level, $spec, $message);
};

$snip = static function (string $s, int $n = 68): string {
    $s = trim((string) preg_replace('/\s+/', ' ', $s));

    return strlen($s) > $n ? substr($s, 0, $n - 3).'...' : $s;
};

// coverage counters
$cov = ['criteria' => 0, 'rows' => 0, 'rowsUnattributed' => 0, 'refsPath' => 0, 'refsTitle' => 0, 'refsSymbol' => 0, 'cellsProse' => 0, 'titlesUnverifiable' => 0];

foreach ($specs as $spec) {
    if ($spec->status === 'unknown') {
        $add('UNRESOLVED', $spec->id, "Status not readable from the header table (raw: \"{$spec->statusRaw}\") — nothing status-dependent is judged for this spec");
    }
    if (! $spec->behaviorSectionFound) {
        $add('UNRESOLVED', $spec->id, 'no "## Behavior" section found — criteria not parsed, so no criterion checks run');

        continue;
    }
    if ($spec->criteria === []) {
        $add('UNRESOLVED', $spec->id, 'Behavior section found but no "- **AC1 — …**" entries recognised — no criterion checks run');

        continue;
    }
    if (! $spec->traceabilitySectionFound) {
        $add('UNRESOLVED', $spec->id, 'no "## Traceability" section found — row checks skipped');

        continue;
    }
    if ($spec->rowsSeen > 0 && $spec->rowsMatched === 0) {
        $add('UNRESOLVED', $spec->id, "traceability table has {$spec->rowsSeen} row(s) but none begins with an AC number — row checks skipped rather than reported as missing");

        continue;
    }

    $cov['criteria'] += count($spec->criteria);
    $cov['rows'] += $spec->rowsMatched;
    $cov['rowsUnattributed'] += $spec->rowsSeen - $spec->rowsMatched;

    $live = array_filter($spec->criteria, static fn (Criterion $c): bool => ! $c->isRedirect);

    foreach ($spec->criteria as $c) {
        if (! $c->hasRow) {
            $add('ERROR', $spec->id, "{$c->id} has no traceability row (the table was parsed: {$spec->rowsMatched} row(s) attributed)");

            continue;
        }
        if ($c->title === '(no Behavior entry)') {
            $add('ERROR', $spec->id, "{$c->id} has a traceability row but no entry in Behavior — stale row?");

            continue;
        }
        if ($c->isRedirect) {
            continue;
        }

        if ($spec->status === 'implemented' && cellIsEmpty($c->testCell)) {
            $add('ERROR', $spec->id, "{$c->id}: spec is `implemented` but the Test cell is empty");
        }
        if ($spec->status === 'implemented' && cellIsEmpty($c->sourceCell)) {
            $add('WARNING', $spec->id, "{$c->id}: spec is `implemented` but the Source cell is empty");
        }

        foreach ([['Test', $c->testCell, $c->testRefs], ['Source', $c->sourceCell, $c->sourceRefs]] as [$which, $cell, $refs]) {
            if (cellIsEmpty($cell)) {
                continue;
            }
            if ($refs === []) {
                $cov['cellsProse']++;
                $add('UNRESOLVED', $spec->id, "{$c->id}: {$which} cell names no path, quoted title or symbol — prose only: \"".$snip((string) $cell).'"');

                continue;
            }
            foreach ($refs as $ref) {
                if ($ref->kind === 'path') {
                    $cov['refsPath']++;
                    if (! str_contains($ref->value, '/')) {
                        $add('UNRESOLVED', $spec->id, "{$c->id}: {$which} names `{$ref->value}` without a directory — cannot verify it exists");
                    } elseif (! is_file($ref->value)) {
                        $add('ERROR', $spec->id, "{$c->id}: {$which} names `{$ref->value}`, which does not exist");
                    }
                } elseif ($ref->kind === 'bare-title') {
                    if ($which === 'Test' && $suite->titles !== []) {
                        if (titleExistsInSuite($ref->value, $suite)) {
                            $cov['refsTitle']++;
                        } else {
                            $cov['cellsProse']++;
                            $add('UNRESOLVED', $spec->id, "{$c->id}: {$which} cell matches no it()/test() title — prose, or a renamed test: \"".$snip($ref->value).'"');
                        }
                    } else {
                        $cov['cellsProse']++;
                    }
                } elseif ($ref->kind === 'title') {
                    $cov['refsTitle']++;
                    // Same rule as everywhere: judge only what was understood. If no
                    // test titles were parsed at all, title verification is impossible
                    // and every "missing" title would be a parse failure in disguise.
                    if ($which === 'Test' && $suite->titles === []) {
                        $cov['titlesUnverifiable']++;
                    } elseif ($which === 'Test' && ! titleExistsInSuite($ref->value, $suite)) {
                        $add('ERROR', $spec->id, "{$c->id}: Test names \"".$snip($ref->value).'" — no it()/test() in the suite starts with that');
                    }
                } else {
                    $cov['refsSymbol']++;
                    // A symbol is not verifiable without parsing PHP. Not reported per-occurrence.
                }
            }
        }
    }

    if (in_array($spec->status, ['approved', 'implemented'], true) && $live !== [] && $spec->scheduled) {
        if (! isset($suite->groups[$spec->id])) {
            $add('ERROR', $spec->id, "status `{$spec->status}` with ".count($live)." live criteria, but no test in {$suite->filesScanned} scanned file(s) carries the group `{$spec->id}`");
        }
    }

    if ($spec->status === 'draft' && $spec->scheduled && isset($suite->groups[$spec->id])) {
        $add('WARNING', $spec->id, 'status `draft` but tests already carry its group — approval is meant to precede tests-first');
    }
}

// --- a SPEC group in the suite with no spec file ---
foreach ($suite->groups as $group => $files) {
    if (! preg_match('/^SPEC-\d+$/', $group)) {
        continue;
    }
    if (! isset($byId[$group])) {
        $add('ERROR', $group, 'tests carry this group but no specs/'.$group.'-*.md exists ('.count($files).' file(s), e.g. '.$files[0].')');
    }
}

// --- source files no spec accounts for ---
// Only assessable when the specs actually name source paths. If they name
// symbols instead, the question cannot be answered and is reported as such,
// rather than declaring every file an orphan.
$claimedBasenames = [];
$sourcePathRefs = 0;
foreach ($specs as $spec) {
    foreach ($spec->criteria as $c) {
        foreach ($c->sourceRefs as $ref) {
            if ($ref->kind === 'path') {
                $sourcePathRefs++;
                $claimedBasenames[basename($ref->value)] = true;
            }
        }
    }
}
if (is_dir('src')) {
    $srcFiles = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('src', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $srcFiles[] = $file->getPathname();
        }
    }
    $sourceCellsWithContent = 0;
    foreach ($specs as $sp) {
        foreach ($sp->criteria as $cc) {
            if (! cellIsEmpty($cc->sourceCell)) {
                $sourceCellsWithContent++;
            }
        }
    }
    if ($sourceCellsWithContent > 0 && $sourcePathRefs / $sourceCellsWithContent < 0.5) {
        $add('UNRESOLVED', '—', sprintf('orphan-source check skipped: only %d of %d non-empty Source cells name a file path (the rest name symbols), so "which src file is unaccounted for" cannot be answered — %d file(s) left unassessed', $sourcePathRefs, $sourceCellsWithContent, count($srcFiles)));
    } else {
        foreach ($srcFiles as $p) {
            if (! isset($claimedBasenames[basename($p)])) {
                $add('WARNING', '—', "src file no spec accounts for: {$p}");
            }
        }
    }
}

// ----------------------------------------------------------------- report ---

$order = ['ERROR' => 0, 'WARNING' => 1, 'UNRESOLVED' => 2];
usort($findings, static fn (Finding $a, Finding $b): int => [$order[$a->level], $a->spec] <=> [$order[$b->level], $b->spec]);

$counts = ['ERROR' => 0, 'WARNING' => 0, 'UNRESOLVED' => 0];
foreach ($findings as $f) {
    $counts[$f->level]++;
}

echo "spec-check\n\n";
echo "COVERAGE — what this run actually understood\n";
printf('  %d spec file(s), %d criteria parsed, %d traceability row(s) attributed to an AC', count($specs), $cov['criteria'], $cov['rows']);
echo $cov['rowsUnattributed'] > 0 ? sprintf(" (%d row(s) not AC-numbered — not judged)\n", $cov['rowsUnattributed']) : "\n";
printf("  %d test file(s) scanned, %d group(s), %d test title(s)\n", $suite->filesScanned, count($suite->groups), count($suite->titles));
printf("  references: %d path, %d title (verifiable) · %d symbol, %d prose-only cell (not verifiable)\n",
    $cov['refsPath'], $cov['refsTitle'], $cov['refsSymbol'], $cov['cellsProse']);
if ($cov['rows'] === 0) {
    echo "  !! no rows attributed — findings below are near-meaningless; check the table format\n";
}
if ($cov['titlesUnverifiable'] > 0) {
    printf("  !! no it()/test() titles parsed from %d file(s): %d title reference(s) left unverified rather than reported missing\n",
        $suite->filesScanned, $cov['titlesUnverifiable']);
}
echo "\n";

$level = null;
foreach ($findings as $f) {
    if ($f->level !== $level) {
        $level = $f->level;
        echo strtoupper($level)."\n";
    }
    printf("  %-10s %s\n", $f->spec, $f->message);
}
if ($findings === []) {
    echo "no findings\n";
}

echo "\n{$counts['ERROR']} error(s), {$counts['WARNING']} warning(s), {$counts['UNRESOLVED']} unresolved\n";

exit($counts['ERROR'] > 0 ? 1 : 0);
