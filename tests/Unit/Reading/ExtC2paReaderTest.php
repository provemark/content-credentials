<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Reading\Exception\ExtensionMissingException;
use Provemark\ContentCredentials\Core\Reading\ExtC2paReader;

/**
 * SPEC-019 AC5/AC7 — the half that needs neither the extension nor a service.
 *
 * AC5 is the criterion that can only be tested where the extension is ABSENT,
 * which is the situation on most machines including CI. It is therefore the one
 * test here that genuinely exercises the failure mode rather than describing it.
 *
 * @see specs/SPEC-019-ext-c2pa-reader.md
 */
$skipIfExtensionLoaded = fn () => extension_loaded('c2pa')
    ? 'ext-c2pa is loaded — this criterion is about its absence'
    : false;

// --- AC5: a missing extension fails loudly at construction -------------------

// There was a test here asserting that ExtC2paReader implements ReaderInterface.
// PHPStan rejected it as `function.alreadyNarrowedType` — always true — and it
// was right: the `implements` clause is enforced by the type system, so the
// assertion tested the compiler rather than the code. Removed rather than
// silenced. What actually needs proving is that the two readers are
// interchangeable in behaviour, and that is SPEC-019 AC2's equivalence test.

it('reports whether it can be used at all', function () {
    // Callers need to ask before constructing, because construction throws.
    expect(ExtC2paReader::isAvailable())->toBe(extension_loaded('c2pa'));
})->group('SPEC-019');

it('throws at construction when the extension is not loaded', function () {
    new ExtC2paReader;
})->throws(ExtensionMissingException::class)
    ->group('SPEC-019')
    ->skip($skipIfExtensionLoaded);

it('names the extension and how to install it', function () {
    // A caller who hits this is usually not the person who chose the reader.
    // "Class not found" or a bare "unavailable" costs them an afternoon.
    try {
        new ExtC2paReader;
        $message = '';
    } catch (Throwable $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('ext-c2pa')
        ->and(strtolower($message))->toContain('pie');
})->group('SPEC-019')->skip($skipIfExtensionLoaded);

it('does not fall back to the signing service', function () {
    // The failure shape this project has now documented five times: a caller who
    // asked for in-process reading and silently got HTTP cannot tell. Worse
    // here than elsewhere — the fallback would need a service URL and token the
    // caller never supplied, so it would fail later and somewhere unrelated.
    try {
        new ExtC2paReader;
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull('construction succeeded without the extension');
    expect($thrown)->toBeInstanceOf(
        ExtensionMissingException::class,
    );
})->group('SPEC-019')->skip($skipIfExtensionLoaded);

// --- AC7: the choice is documented, with its risks ---------------------------

function spec019Readme(): string
{
    // Moved with its text by SPEC-027.
    $raw = @file_get_contents(dirname(__DIR__, 3).'/docs/readers.md');

    // Whitespace collapsed so a phrase survives a hard-wrapped paragraph — the
    // lesson from SPEC-018, where the match failed on a line break.
    return strtolower((string) preg_replace('/\s+/', ' ', is_string($raw) ? $raw : ''));
}

it('documents that verification needs no signing service', function () {
    // The single sentence that changes who can use this library.
    expect(spec019Readme())->toContain('verification needs no');
})->group('SPEC-019');

it('states how the extension is installed and that it is young', function () {
    $readme = spec019Readme();

    expect($readme)->toContain('ericmann/ext-c2pa')
        ->and($readme)->toContain('pie')
        // A reader deciding to depend on this must know it is v0.1.0.
        ->and($readme)->toContain('v0.1.0');
})->group('SPEC-019');

it('warns that the two readers carry different c2pa-rs versions', function () {
    // The risk that makes AC2 exist. If the README does not say it, the first
    // person to meet a discrepancy will think they found a bug in this library.
    $readme = spec019Readme();

    expect($readme)->toContain('c2pa-rs')
        ->and($readme)->toContain('0.89')
        ->and($readme)->toContain('0.90');
})->group('SPEC-019');

it('states that signing is deliberately unaffected', function () {
    // The extension can sign. Saying nothing would leave a reader to conclude
    // that in-process signing is simply missing, rather than declined.
    expect(spec019Readme())->toContain('signing still goes through the service');
})->group('SPEC-019');
