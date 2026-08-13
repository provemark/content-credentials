<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Reading\ManifestReport;

/**
 * Every public accessor on `ManifestReport` is either documented or exempt.
 *
 * This guards a gap rather than a criterion, and it is worth being explicit
 * about that: no spec requires it today. It exists because `softwareAgents()`
 * shipped in 0.11.0 as public API with zero mentions across `docs/` and the
 * README, while its sibling `digitalSourceTypes()` appeared in four places.
 * `docs/` ships inside the Composer package, so that reached users as
 * documentation of a package whose method it did not mention.
 *
 * `bin/spec-check.php` holds spec-to-test, and SPEC-026's
 * "lists every emittable source type in the README" holds enum-to-docs. Nothing
 * held public-API-to-docs, and every documentation defect found on 2026-08-12
 * and 13 — a request table naming three fields the service never had, ten stale
 * engine versions, this — lived in exactly that unguarded space.
 *
 * The list is DERIVED from the class, not restated. Adding an accessor fails
 * this test until someone either documents it or exempts it below with a
 * reason, which is the decision this is meant to force.
 *
 * @see docs/usage.md
 */

/**
 * Accessors deliberately not promoted in the usage guide.
 *
 * These return the decoded manifest's raw shape. They exist so a caller can
 * answer a question the named accessors do not, and documenting them in the
 * how-to would invite reaching for them first — which is the opposite of why
 * the named accessors exist. `docs/stability.md` already covers them by listing
 * `ManifestReport` itself as public API.
 *
 * @return list<string>
 */
function readerAccessorsExemptFromDocs(): array
{
    return [
        'assertions',            // the raw assertion array — the escape hatch
        'activeManifestLabel',   // the manifest's URN; identity, not a verdict
        'validationStatusCodes', // raw c2pa-rs codes behind isTrusted()/isSignatureValid()
        'validationState',       // the enum behind the two verdicts; shown by the read command
    ];
}

it('documents every public accessor on the reader report', function () {
    $usage = (string) file_get_contents(dirname(__DIR__, 2).'/docs/usage.md');

    $accessors = array_values(array_filter(
        array_map(
            fn (ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(ManifestReport::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        ),
        fn (string $name) => ! str_starts_with($name, '__'),
    ));

    // If this ever comes back empty the assertion below would hold vacuously,
    // which is the failure mode this repository has documented five times.
    expect($accessors)->not->toBeEmpty('no public methods found — reflection returned nothing');

    $undocumented = array_values(array_filter(
        $accessors,
        fn (string $name) => ! in_array($name, readerAccessorsExemptFromDocs(), true)
            && ! str_contains($usage, $name.'()'),
    ));

    expect($undocumented)->toBe([], 'public reader accessors missing from docs/usage.md: '
        .implode(', ', $undocumented).' — document them, or exempt them with a reason');
})->group('SPEC-003', 'SPEC-033');

it('keeps the exemption list honest', function () {
    // An exemption for a method that no longer exists is a stale note that
    // would silently start excusing nothing. Derived from the class for the
    // same reason as above.
    $accessors = array_map(
        fn (ReflectionMethod $m) => $m->getName(),
        (new ReflectionClass(ManifestReport::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    $stale = array_values(array_diff(readerAccessorsExemptFromDocs(), $accessors));

    expect($stale)->toBe([], 'exempted methods that no longer exist: '.implode(', ', $stale));
})->group('SPEC-003', 'SPEC-033');
