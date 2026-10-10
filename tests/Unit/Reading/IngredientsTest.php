<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\C2paVerifierReader;
use Provemark\ContentCredentials\Core\Reading\ExtC2paReader;
use Provemark\ContentCredentials\Core\Reading\IngredientReport;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Reading\ManifestStoreParser;
use Provemark\ContentCredentials\Core\Reading\ReaderInterface;
use Provemark\ContentCredentials\Core\Signing\Asset;

/**
 * SPEC-045 — ingredients, each with its own verdict.
 *
 * The files are real, not built here, because the point is what writers this
 * library does not control put in a store (Drupal module NOTES Step 33,
 * 2026-10-10):
 *
 * - `spec045-c2pasign-*.png`: an AI original (`c2pa.created` +
 *   `trainedAlgorithmicMedia`) made with c2patool 0.9.12, then uploaded and
 *   published through C2PA Sign 1.4.11 with c2patool 0.9.12 — two re-signs,
 *   so the AI type is two ingredients down. `trusted`: original signed by the
 *   C2PA test signer. `rogue`: original signed by a self-made CA that is not in
 *   the anchors. `logo`: the trusted chain with C2PA Sign's site logo, which the
 *   verifier does not resolve (AC2's pinned exception). C2PA Sign itself signs
 *   with the C2PA test signer throughout.
 * - `spec045-c2patool027-*.png`: the same kind of original, re-signed once with
 *   c2patool 0.27.22 `-p`, a writer that records its own view of the ingredient
 *   (`signingCredential.untrusted`, having no anchors).
 * - `spec045-graft.png` (Amendment 2): a genuine trusted AI original grafted
 *   with c2patool 0.27.22 `-p` under an unrelated image signed by a self-made
 *   CA, so the store is `Invalid` and only the untrusted top vouches for the
 *   ingredient. Made by the independent review of this branch.
 * - `spec045-c2pa-rs-CIE-sig-CA.jpg`: c2pa-rs's own fixture `CIE-sig-CA.jpg`
 *   (MIT, see `spec045-c2pa-rs-LICENSE-MIT`): a `componentOf` ingredient whose
 *   claim signature does not verify, recorded by the writer as
 *   `claimSignature.mismatch`.
 *
 * Read under `certs/c2pa-trust.settings.json`, whose roots are the C2PA test
 * roots and not the rogue CA.
 *
 * @see specs/SPEC-045-ingredients-with-their-own-verdict.md
 */
const SPEC045_AI = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

function spec045Asset(string $name): Asset
{
    $bytes = file_get_contents(dirname(__DIR__, 2).'/Fixtures/'.$name);

    if (! is_string($bytes) || $bytes === '') {
        throw new RuntimeException("missing fixture {$name}");
    }

    return new Asset($bytes, str_ends_with($name, '.jpg') ? MediaType::Jpeg : MediaType::Png);
}

function spec045TrustSettings(): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/certs/c2pa-trust.settings.json');
}

function spec045AnchorsPem(): string
{
    $settings = json_decode(spec045TrustSettings(), true, 512, JSON_THROW_ON_ERROR);
    $trust = is_array($settings) ? ($settings['trust'] ?? null) : null;
    $pem = is_array($trust) ? ($trust['trust_anchors'] ?? null) : null;

    if (! is_string($pem)) {
        throw new RuntimeException('certs/c2pa-trust.settings.json carries no trust.trust_anchors');
    }

    return $pem;
}

/**
 * The routes this suite reads through, by name. The verifier arrives through
 * require-dev; the extension is only listed where it is loaded, so CI runs the
 * verifier half — as SPEC-019 AC2 already accepts for the extension.
 *
 * @return array<string, array{string}>
 */
function spec045Routes(): array
{
    return ['verifier' => ['verifier']]
        + (ExtC2paReader::isAvailable() ? ['extension' => ['extension']] : []);
}

function spec045Reader(string $route): ReaderInterface
{
    return $route === 'extension'
        ? new ExtC2paReader(spec045AnchorsPem())
        : new C2paVerifierReader(spec045TrustSettings());
}

function spec045Read(string $route, string $fixture): ManifestReport
{
    return spec045Reader($route)->read(spec045Asset($fixture));
}

/**
 * The ingredient tree as plain data: what a caller can observe.
 *
 * @param  list<IngredientReport>  $ingredients
 * @return list<array{relationship: ?string, hasManifest: bool, digitalSourceTypes: list<string>, isTrusted: bool, ingredients: list<mixed>}>
 */
function spec045Tree(array $ingredients): array
{
    return array_map(fn (IngredientReport $i) => [
        'relationship' => $i->relationship(),
        'hasManifest' => $i->hasManifest(),
        'digitalSourceTypes' => $i->digitalSourceTypes(),
        'isTrusted' => $i->isTrusted(),
        'ingredients' => spec045Tree($i->ingredients()),
    ], $ingredients);
}

/**
 * A synthetic store: manifests by label, the active one, and the deltas.
 *
 * @param  array<string, array<array-key, mixed>>  $manifests
 * @return array{active_manifest: string, manifests: array<string, array<array-key, mixed>>, validation_state: string, validation_results: array{activeManifest: array<string, mixed>, ingredientDeltas: mixed}}
 */
function spec045Store(string $active, array $manifests, mixed $deltas = []): array
{
    return [
        'active_manifest' => $active,
        'manifests' => $manifests,
        'validation_state' => 'Trusted',
        'validation_results' => [
            'activeManifest' => ['success' => [['code' => 'signingCredential.trusted']], 'informational' => [], 'failure' => []],
            'ingredientDeltas' => $deltas,
        ],
    ];
}

/**
 * A manifest whose actions carry the given source type (or none).
 *
 * @param  list<array<string, mixed>>  $ingredients
 * @return array<string, mixed>
 */
function spec045Manifest(?string $type, array $ingredients = []): array
{
    $action = ['action' => $type === null ? 'c2pa.opened' : 'c2pa.created'];
    if ($type !== null) {
        $action['digitalSourceType'] = $type;
    }

    return [
        'assertions' => [['label' => 'c2pa.actions', 'data' => ['actions' => [$action]]]],
        'ingredients' => $ingredients,
    ];
}

/**
 * An ingredient delta with the given codes.
 *
 * @param  list<string>  $success
 * @param  list<string>  $failure
 * @return array<string, mixed>
 */
function spec045Delta(string $in, array $success, array $failure = [], string $ingredientLabel = 'c2pa.ingredient'): array
{
    return [
        'ingredientAssertionURI' => "self#jumbf=/c2pa/{$in}/c2pa.assertions/{$ingredientLabel}",
        'validationDeltas' => [
            'success' => array_map(fn (string $code) => ['code' => $code], $success),
            'informational' => [],
            'failure' => array_map(fn (string $code) => ['code' => $code], $failure),
        ],
    ];
}

/**
 * A delta that AC3 accepts, for the ingredient `$ingredientLabel` named by manifest `$in`.
 *
 * @return array<string, mixed>
 */
function spec045GoodDelta(string $in, string $ingredientLabel = 'c2pa.ingredient'): array
{
    return spec045Delta($in, ['ingredient.manifest.validated', 'signingCredential.trusted', 'claimSignature.validated'], [], $ingredientLabel);
}

// --- AC1: the ingredient chain is reported --------------------------------------

it('reports the AI origin two ingredients down a C2PA Sign chain', function (string $route) {
    $report = spec045Read($route, 'spec045-c2pasign-trusted.png');

    // The existing accessor is unchanged: the active manifest names no type.
    expect($report->digitalSourceTypes())->toBe([]);

    $published = $report->ingredients();
    expect($published)->toHaveCount(1)
        ->and($published[0]->relationship())->toBe('parentOf')
        ->and($published[0]->hasManifest())->toBeTrue()
        ->and($published[0]->digitalSourceTypes())->toBe([]);

    $uploaded = $published[0]->ingredients();
    expect($uploaded)->toHaveCount(1)
        ->and($uploaded[0]->relationship())->toBe('parentOf')
        ->and($uploaded[0]->digitalSourceTypes())->toBe([SPEC045_AI])
        ->and($uploaded[0]->ingredients())->toBe([]);
})->with(spec045Routes())->group('SPEC-045');

// --- AC2: the logo exception, pinned rather than hidden -------------------------

it('pins the verifier refusing an ingredient whose C2PA Sign logo it cannot resolve', function () {
    $verifier = spec045Read('verifier', 'spec045-c2pasign-logo.png');

    // The upload manifest (first ingredient) carries the icon in c2pa.databoxes,
    // which makes the store Invalid here; Amendment 2 then lets nothing below
    // it be trusted either.
    expect($verifier->isTrusted())->toBeFalse()
        ->and($verifier->ingredients()[0]->isTrusted())->toBeFalse()
        ->and($verifier->ingredients()[0]->ingredients()[0]->isTrusted())->toBeFalse();
})->group('SPEC-045');

it('trusts the same logo-bearing ingredient through c2pa-rs', function () {
    $extension = spec045Read('extension', 'spec045-c2pasign-logo.png');

    expect($extension->ingredients()[0]->isTrusted())->toBeTrue()
        ->and($extension->ingredients()[0]->ingredients()[0]->isTrusted())->toBeTrue();
})->group('SPEC-045')->skip(fn () => ! ExtC2paReader::isAvailable(), 'ext-c2pa is not loaded');

// --- AC3: an ingredient is trusted only on the reader's own evidence ------------

it('gives each ingredient the verdict of the SPEC-045 table', function (string $route, string $fixture, array $verdicts) {
    $report = spec045Read($route, $fixture);

    $observed = [];
    $level = $report->ingredients();
    while ($level !== []) {
        $observed[] = $level[0]->isTrusted();
        $level = $level[0]->ingredients();
    }

    expect($observed)->toBe($verdicts);
})->with(spec045Routes())->with([
    // Good at both levels; C2PA Sign 0.9.12 records nothing about them.
    'c2pasign trusted' => ['spec045-c2pasign-trusted.png', [true, true]],
    // The upload manifest is C2PA Sign's own and good on its own, but the rogue
    // original below makes the store Valid, not Trusted: Amendment 2, nothing
    // under an untrusted report is trusted.
    'c2pasign rogue' => ['spec045-c2pasign-rogue.png', [false, false]],
    // Amendment 2: a trusted AI manifest grafted under a rogue-signed image.
    'graft' => ['spec045-graft.png', [false]],
    // The writer recorded claimSignature.validated; the delta only adds trusted.
    'c2patool 0.27 trusted' => ['spec045-c2patool027-trusted.png', [true]],
    // The writer recorded untrusted; the delta carries nothing trust-related.
    'c2patool 0.27 rogue' => ['spec045-c2patool027-rogue.png', [false]],
    // Delta says signingCredential.trusted, writer recorded claimSignature.mismatch.
    // Its own parentOf ingredient has no manifest, hence the second false.
    'c2pa-rs CIE-sig-CA' => ['spec045-c2pa-rs-CIE-sig-CA.jpg', [false, false]],
])->group('SPEC-045');

it('keeps the relationship verbatim', function () {
    $report = spec045Read('verifier', 'spec045-c2pa-rs-CIE-sig-CA.jpg');

    expect($report->ingredients()[0]->relationship())->toBe('componentOf');
})->group('SPEC-045');

// --- AC4: no evidence is not trust ----------------------------------------------

it('does not trust any ingredient when the reader has no anchors', function () {
    $report = (new C2paVerifierReader)->read(spec045Asset('spec045-c2pasign-trusted.png'));

    expect(spec045Tree($report->ingredients()))->toBe([[
        'relationship' => 'parentOf',
        'hasManifest' => true,
        'digitalSourceTypes' => [],
        'isTrusted' => false,
        'ingredients' => [[
            'relationship' => 'parentOf',
            'hasManifest' => true,
            'digitalSourceTypes' => [SPEC045_AI],
            'isTrusted' => false,
            'ingredients' => [],
        ]],
    ]]);
})->group('SPEC-045');

it('does not trust an ingredient without a manifest, or without its own delta', function () {
    $report = ManifestStoreParser::fromArray(spec045Store('top', [
        'top' => spec045Manifest(null, [
            ['relationship' => 'parentOf', 'label' => 'c2pa.ingredient'],
            ['relationship' => 'componentOf', 'label' => 'c2pa.ingredient__1', 'active_manifest' => 'other'],
        ]),
        'other' => spec045Manifest(SPEC045_AI),
    ], [
        // A good delta, but under a key that names no ingredient of this store.
        spec045GoodDelta('nowhere', 'c2pa.ingredient__1'),
    ]));

    expect(spec045Tree($report->ingredients()))->toBe([
        ['relationship' => 'parentOf', 'hasManifest' => false, 'digitalSourceTypes' => [], 'isTrusted' => false, 'ingredients' => []],
        ['relationship' => 'componentOf', 'hasManifest' => true, 'digitalSourceTypes' => [SPEC045_AI], 'isTrusted' => false, 'ingredients' => []],
    ]);
})->group('SPEC-045');

it('does not trust an ingredient without a manifest even under a good delta', function () {
    // A delta that would pass AC3, keyed to an ingredient whose manifest is not
    // in the store: the evidence is about nothing this report can show.
    $report = ManifestStoreParser::fromArray(spec045Store('top', [
        'top' => spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient']]),
    ], [spec045GoodDelta('top')]));

    expect($report->ingredients()[0]->hasManifest())->toBeFalse()
        ->and($report->ingredients()[0]->isTrusted())->toBeFalse();
})->group('SPEC-045');

/**
 * What a writer recorded about an ingredient, in the C2PA 2.x shape.
 *
 * @param  list<string>  $success
 * @param  list<string>  $failure
 * @return array<string, mixed>
 */
function spec045Recorded(array $success, array $failure): array
{
    return ['activeManifest' => [
        'success' => array_map(fn (string $c) => ['code' => $c], $success),
        'failure' => array_map(fn (string $c) => ['code' => $c], $failure),
    ]];
}

/**
 * One AC3 condition broken at a time, on an otherwise good ingredient named
 * `c2pa.ingredient` by manifest `top`.
 *
 * @return array{array<string, mixed>, array<string, mixed>} the ingredient entry and its delta
 */
function spec045Case(string $case): array
{
    $ingredient = ['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'parent'];

    return match ($case) {
        'all conditions hold' => [$ingredient, spec045GoodDelta('top')],
        'delta lacks signingCredential.trusted' => [$ingredient, spec045Delta('top', ['claimSignature.validated'])],
        'claimSignature.validated nowhere' => [$ingredient, spec045Delta('top', ['signingCredential.trusted'])],
        'claimSignature.validated only in what the writer recorded' => [
            [...$ingredient, 'validation_results' => spec045Recorded(['claimSignature.validated'], ['signingCredential.untrusted'])],
            spec045Delta('top', ['signingCredential.trusted']),
        ],
        'a failure in the delta' => [$ingredient, spec045Delta('top', ['signingCredential.trusted', 'claimSignature.validated'], ['assertion.hashedURI.mismatch'])],
        'the writer recorded a failure other than untrusted' => [
            [...$ingredient, 'validation_results' => spec045Recorded([], ['claimSignature.mismatch'])],
            spec045GoodDelta('top'),
        ],
        'an older writer recorded a failure in validation_status' => [
            [...$ingredient, 'validation_status' => [['code' => 'claimSignature.mismatch']]],
            spec045GoodDelta('top'),
        ],
        default => throw new InvalidArgumentException($case),
    };
}

it('trusts a synthetic ingredient only when every AC3 condition holds', function (string $case, bool $expected) {
    [$ingredient, $delta] = spec045Case($case);

    $report = ManifestStoreParser::fromArray(spec045Store('top', [
        'top' => spec045Manifest(null, [$ingredient]),
        'parent' => spec045Manifest(SPEC045_AI),
    ], [$delta]));

    expect($report->ingredients()[0]->isTrusted())->toBe($expected);
})->with([
    ['all conditions hold', true],
    ['delta lacks signingCredential.trusted', false],
    ['claimSignature.validated nowhere', false],
    ['claimSignature.validated only in what the writer recorded', true],
    ['a failure in the delta', false],
    ['the writer recorded a failure other than untrusted', false],
    ['an older writer recorded a failure in validation_status', false],
])->group('SPEC-045');

// --- AC3, Amendment 1: a writer's record can hide a failure anywhere ----------

/**
 * A two-ingredient store where every AC3 condition holds for `forged` on the
 * reader's side, and the writer of `top` recorded whatever $recordOn gets.
 *
 * @param  Closure(array<string, mixed>, array<string, mixed>): array{array<string, mixed>, array<string, mixed>}  $recordOn
 */
function spec045RecordedStore(Closure $recordOn): ManifestReport
{
    $forged = ['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'forged'];
    $other = ['relationship' => 'componentOf', 'label' => 'c2pa.ingredient__1', 'active_manifest' => 'other'];
    [$forged, $other] = $recordOn($forged, $other);

    return ManifestStoreParser::fromArray(spec045Store('top', [
        'top' => spec045Manifest(null, [$forged, $other]),
        'forged' => spec045Manifest(SPEC045_AI),
        'other' => spec045Manifest(null),
    ], [spec045GoodDelta('top'), spec045GoodDelta('top', 'c2pa.ingredient__1')]));
}

it('trusts the good synthetic chain when the writer recorded only allowed codes', function () {
    $report = spec045RecordedStore(fn (array $f, array $o) => [
        [...$f, 'validation_results' => spec045Recorded(['claimSignature.validated', 'timeStamp.validated'], ['signingCredential.untrusted'])],
        $o,
    ]);

    expect($report->ingredients()[0]->isTrusted())->toBeTrue()
        ->and($report->ingredients()[1]->isTrusted())->toBeTrue();
})->group('SPEC-045');

it('trusts no ingredient once a writer recorded a failure code anywhere', function (string $case) {
    $mismatch = ['code' => 'claimSignature.mismatch', 'url' => 'self#jumbf=/c2pa/forged/c2pa.signature'];
    $validated = ['code' => 'claimSignature.validated', 'url' => 'self#jumbf=/c2pa/forged/c2pa.signature'];

    $report = spec045RecordedStore(fn (array $f, array $o) => match ($case) {
        'under success' => [[...$f, 'validation_results' => ['activeManifest' => ['success' => [$validated, $mismatch]]]], $o],
        'under informational' => [[...$f, 'validation_results' => ['activeManifest' => ['success' => [$validated], 'informational' => [$mismatch]]]], $o],
        'under another ingredient' => [$f, [...$o, 'validation_results' => ['activeManifest' => ['success' => [], 'failure' => [$mismatch]]]]],
        'in a nested ingredientDeltas' => [[...$f, 'validation_results' => ['activeManifest' => ['success' => [$validated]], 'ingredientDeltas' => [['ingredientAssertionURI' => 'x', 'validationDeltas' => ['failure' => [$mismatch]]]]]], $o],
        'an unknown code' => [[...$f, 'validation_results' => ['activeManifest' => ['success' => [$validated, ['code' => 'something.new', 'url' => 'x']]]]], $o],
        'in an older validation_status' => [$f, [...$o, 'validation_status' => [$mismatch]]],
        default => throw new InvalidArgumentException($case),
    });

    expect(array_map(fn (IngredientReport $i) => $i->isTrusted(), $report->ingredients()))->toBe([false, false]);
})->with([
    'under success',
    'under informational',
    'under another ingredient',
    'in a nested ingredientDeltas',
    'an unknown code',
    'in an older validation_status',
])->group('SPEC-045');

it('looks for recorded codes in every manifest of the store, not only the active one', function () {
    // The record sits in `forged`'s own ingredient assertion, one level down.
    $report = ManifestStoreParser::fromArray(spec045Store('top', [
        'top' => spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'forged']]),
        'forged' => spec045Manifest(SPEC045_AI, [[
            'relationship' => 'parentOf', 'label' => 'c2pa.ingredient',
            'validation_results' => ['activeManifest' => ['success' => [['code' => 'claimSignature.mismatch', 'url' => 'x']]]],
        ]]),
    ], [spec045GoodDelta('top')]));

    expect($report->ingredients()[0]->isTrusted())->toBeFalse();
})->group('SPEC-045');

// --- AC3, Amendment 2: trust flows down; unreadable evidence is none --------

it('trusts no ingredient of a report that is not trusted', function () {
    $store = spec045Store('top', [
        'top' => spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'parent']]),
        'parent' => spec045Manifest(SPEC045_AI),
    ], [spec045GoodDelta('top')]);
    $store['validation_state'] = 'Invalid';

    expect(ManifestStoreParser::fromArray($store)->ingredients()[0]->isTrusted())->toBeFalse();
})->group('SPEC-045');

it('trusts no grandchild of an untrusted ingredient', function () {
    // `mid` has no delta of its own, so it is untrusted; `ai` below it has a
    // perfectly good one, and must still not be trusted.
    $report = ManifestStoreParser::fromArray(spec045Store('top', [
        'top' => spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'mid']]),
        'mid' => spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'ai']]),
        'ai' => spec045Manifest(SPEC045_AI),
    ], [spec045GoodDelta('mid')]));

    expect($report->ingredients()[0]->isTrusted())->toBeFalse()
        ->and($report->ingredients()[0]->ingredients()[0]->isTrusted())->toBeFalse();
})->group('SPEC-045');

it('trusts a grandchild when every link above it is trusted', function () {
    $report = ManifestStoreParser::fromArray(spec045Store('top', [
        'top' => spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'mid']]),
        'mid' => spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'ai']]),
        'ai' => spec045Manifest(SPEC045_AI),
    ], [spec045GoodDelta('top'), spec045GoodDelta('mid')]));

    expect($report->ingredients()[0]->ingredients()[0]->isTrusted())->toBeTrue();
})->group('SPEC-045');

it('counts neither of two deltas under the same key', function (bool $failingFirst) {
    $failing = spec045Delta('top', ['signingCredential.trusted', 'claimSignature.validated'], ['claimSignature.mismatch']);
    $clean = spec045GoodDelta('top');

    $report = ManifestStoreParser::fromArray(spec045Store('top', [
        'top' => spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'parent']]),
        'parent' => spec045Manifest(SPEC045_AI),
    ], $failingFirst ? [$failing, $clean] : [$clean, $failing]));

    expect($report->ingredients()[0]->isTrusted())->toBeFalse();
})->with(['failing delta first' => [true], 'clean delta first' => [false]])->group('SPEC-045');

it('counts an unreadable failure in a delta as a failure', function (mixed $failure) {
    $delta = spec045GoodDelta('top');
    $delta['validationDeltas'] = [...(array) $delta['validationDeltas'], 'failure' => $failure];

    $report = ManifestStoreParser::fromArray(spec045Store('top', [
        'top' => spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'parent']]),
        'parent' => spec045Manifest(SPEC045_AI),
    ], [$delta]));

    expect($report->ingredients()[0]->isTrusted())->toBeFalse();
})->with([
    'a string' => ['claimSignature.mismatch'],
    'an entry whose code is a list' => [[['code' => ['claimSignature.mismatch']]]],
    'an entry that is not an object' => [['claimSignature.mismatch']],
])->group('SPEC-045');

it('reads a large writer record in bounded time', function () {
    $nested = [];
    for ($n = 0; $n < 60_000; $n++) {
        $nested[] = ['ingredientAssertionURI' => "x{$n}", 'validationDeltas' => ['success' => [['code' => 'assertion.hashedURI.match', 'url' => 'x']]]];
    }

    // Parsing happens inside spec045RecordedStore(), so that call is what is timed.
    $started = hrtime(true);
    $report = spec045RecordedStore(fn (array $f, array $o) => [
        [...$f, 'validation_results' => ['activeManifest' => ['success' => [['code' => 'claimSignature.validated']]], 'ingredientDeltas' => $nested]],
        $o,
    ]);
    $seconds = (hrtime(true) - $started) / 1e9;

    // Measured at 18.6 s for this shape before Amendment 2; linear is well
    // under a second. Generous, so a slow CI runner cannot flake it.
    expect($seconds)->toBeLessThan(3.0)
        // and the record, all allowed codes, still leaves the ingredient trusted
        ->and($report->ingredients()[0]->isTrusted())->toBeTrue();
})->group('SPEC-045');

// --- AC5: hostile chains are bounded --------------------------------------------

it('stops at a manifest that names itself', function () {
    $report = ManifestStoreParser::fromArray(spec045Store('loop', [
        'loop' => spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'loop']]),
    ]));

    expect($report->ingredients())->toHaveCount(1)
        ->and($report->ingredients()[0]->ingredients())->toBe([]);
})->group('SPEC-045');

it('stops at a cycle through an ancestor', function () {
    $report = ManifestStoreParser::fromArray(spec045Store('a', [
        'a' => spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'b']]),
        'b' => spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'a']]),
    ]));

    expect($report->ingredients()[0]->ingredients())->toHaveCount(1)
        ->and($report->ingredients()[0]->ingredients()[0]->ingredients())->toBe([]);
})->group('SPEC-045');

it('reports an ingredient naming a label that is not in the store as having no manifest', function () {
    $report = ManifestStoreParser::fromArray(spec045Store('top', [
        'top' => spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'missing']]),
    ]));

    expect($report->ingredients()[0]->hasManifest())->toBeFalse()
        ->and($report->ingredients()[0]->isTrusted())->toBeFalse();
})->group('SPEC-045');

it('reports no deeper than depth 8', function () {
    $manifests = [];
    for ($n = 0; $n < 12; $n++) {
        $manifests["m{$n}"] = spec045Manifest(null, [['relationship' => 'parentOf', 'label' => 'c2pa.ingredient', 'active_manifest' => 'm'.($n + 1)]]);
    }
    $manifests['m12'] = spec045Manifest(SPEC045_AI);

    $depth = 0;
    $level = ManifestStoreParser::fromArray(spec045Store('m0', $manifests))->ingredients();
    while ($level !== []) {
        $depth++;
        $level = $level[0]->ingredients();
    }

    expect($depth)->toBe(8);
})->group('SPEC-045');

it('reports no more than 64 ingredients in total', function () {
    $ingredients = [];
    for ($n = 0; $n < 70; $n++) {
        $ingredients[] = ['relationship' => 'componentOf', 'label' => "c2pa.ingredient__{$n}"];
    }

    $report = ManifestStoreParser::fromArray(spec045Store('top', ['top' => spec045Manifest(null, $ingredients)]));

    expect($report->ingredients())->toHaveCount(64);
})->group('SPEC-045');

/**
 * How many ingredients a tree holds, all levels counted.
 *
 * @param  list<IngredientReport>  $ingredients
 */
function spec045Count(array $ingredients): int
{
    $count = 0;
    foreach ($ingredients as $ingredient) {
        $count += 1 + spec045Count($ingredient->ingredients());
    }

    return $count;
}

it('shares the 64 budget across a deep and wide tree', function () {
    // Four ingredients per manifest, four levels: 340 without a shared budget.
    $manifests = [];
    $make = function (string $label, int $level) use (&$make, &$manifests): void {
        $ingredients = [];
        if ($level < 4) {
            for ($n = 0; $n < 4; $n++) {
                $child = "{$label}.{$n}";
                $ingredients[] = ['relationship' => 'parentOf', 'label' => "c2pa.ingredient__{$n}", 'active_manifest' => $child];
                $make($child, $level + 1);
            }
        }
        $manifests[$label] = spec045Manifest(null, $ingredients);
    };
    $make('root', 0);

    expect(spec045Count(ManifestStoreParser::fromArray(spec045Store('root', $manifests))->ingredients()))->toBe(64);
})->group('SPEC-045');

// --- AC6: malformed ingredient data degrades, never throws ----------------------

it('degrades malformed ingredient data instead of throwing', function (mixed $ingredients, mixed $deltas, int $expectedCount) {
    $store = spec045Store('top', ['top' => ['assertions' => [], 'ingredients' => $ingredients]], $deltas);

    $report = ManifestStoreParser::fromArray($store);

    expect($report->ingredients())->toHaveCount($expectedCount);
    foreach ($report->ingredients() as $ingredient) {
        expect($ingredient->isTrusted())->toBeFalse();
    }
})->with([
    'ingredients is a string' => ['not a list', [], 0],
    'entries are not objects' => [['x', 3, null], [], 0],
    'non-string label and relationship' => [[['relationship' => 7, 'label' => ['x'], 'active_manifest' => 9]], [], 1],
    'deltas is a string' => [[['relationship' => 'parentOf', 'label' => 'c2pa.ingredient']], 'nope', 1],
    'delta entries malformed' => [[['relationship' => 'parentOf', 'label' => 'c2pa.ingredient']], [['ingredientAssertionURI' => 5], 'x', ['validationDeltas' => 'y']], 1],
])->group('SPEC-045');

it('treats an ingredient with a non-string relationship as having none, and still judges it', function () {
    $store = spec045Store('top', [
        'top' => ['assertions' => [], 'ingredients' => [['relationship' => 7, 'label' => 'c2pa.ingredient', 'active_manifest' => 'parent']]],
        'parent' => spec045Manifest(SPEC045_AI),
    ], [spec045GoodDelta('top')]);

    $ingredient = ManifestStoreParser::fromArray($store)->ingredients()[0];

    // Not vacuous: the same ingredient is trusted, so the null is the field
    // degrading, not the whole entry being thrown away.
    expect($ingredient->relationship())->toBeNull()
        ->and($ingredient->isTrusted())->toBeTrue();
})->group('SPEC-045');

it('reports no ingredients for an empty report', function () {
    expect((new ManifestReport(null, null, [], []))->ingredients())->toBe([]);
})->group('SPEC-045');
