<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Manifest\SoftwareAgent;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Reading\SignerInfo;
use Provemark\ContentCredentials\Core\Reading\ValidationState;

/**
 * SPEC-033 — the package writes `softwareAgent` and must read it back.
 *
 * `digitalSourceTypes()` already encapsulates this traversal for the other half
 * of the same assertion. These tests pin the second half to the same rules:
 * same label matching, same de-duplication, same refusal to guess at malformed
 * input.
 *
 * AC4 (any name, verbatim) is quantified over `Gen::softwareAgentName()` and
 * therefore lives in the property suite — `tests/Unit/Property/` is where the
 * Eris DSL is allowed, because those paths are excluded from level-max analysis
 * for reasons phpstan.neon states.
 *
 * @see specs/SPEC-033-reading-software-agents.md
 */

/**
 * @param  list<mixed>  $actions
 */
function spec033Report(array $actions, string $label = 'c2pa.actions.v2'): ManifestReport
{
    return new ManifestReport(
        'urn:c2pa:test',
        new SignerInfo('Test', 'CN', 'Es256'),
        [['label' => $label, 'data' => ['actions' => $actions]]],
        [],
        ValidationState::Valid,
    );
}

/**
 * @return array<string, mixed>
 */
function spec033Action(mixed $agent): array
{
    return [
        'action' => 'c2pa.created',
        'digitalSourceType' => DigitalSourceType::TrainedAlgorithmicMedia->value,
        'softwareAgent' => $agent,
    ];
}

// AC1 — what the builder writes is what the reader returns. This is the
// asymmetry the spec exists to close, so it is tested through the real builder
// rather than a hand-built assertion.
it('reads back the software agent the builder wrote', function () {
    $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('ACME GenAI Image Model', '2.1')
        ->build();

    $agents = (new ManifestReport('urn:c2pa:test', null, $manifest->assertions(), []))
        ->softwareAgents();

    expect($agents)->toHaveCount(1)
        ->and($agents[0])->toBeInstanceOf(SoftwareAgent::class)
        ->and($agents[0]->name)->toBe('ACME GenAI Image Model')
        ->and($agents[0]->version)->toBe('2.1');
})->group('SPEC-033');

// AC2 — an absent version must stay absent. Coercing it to '' would make
// "unknown version" indistinguishable from "version is the empty string".
it('reports an absent version as null rather than an empty string', function () {
    $agents = spec033Report([spec033Action(['name' => 'Solo'])])->softwareAgents();

    expect($agents)->toHaveCount(1)
        ->and($agents[0]->name)->toBe('Solo')
        ->and($agents[0]->version)->toBeNull();
})->group('SPEC-033');

// AC3 — same de-duplication and ordering rule as digitalSourceTypes().
it('returns distinct agents in first-appearance order', function () {
    $agents = spec033Report([
        spec033Action(['name' => 'First', 'version' => '1']),
        spec033Action(['name' => 'Second']),
        spec033Action(['name' => 'First', 'version' => '1']),
    ])->softwareAgents();

    expect($agents)->toHaveCount(2)
        ->and($agents[0]->name)->toBe('First')
        ->and($agents[1]->name)->toBe('Second');
})->group('SPEC-033');

// AC3 continued — name alone does not make two agents the same. A version bump
// is a different agent, and collapsing them would hide exactly the change an
// auditor is looking for.
it('treats the same name at a different version as a distinct agent', function () {
    $agents = spec033Report([
        spec033Action(['name' => 'Same', 'version' => '1']),
        spec033Action(['name' => 'Same', 'version' => '2']),
    ])->softwareAgents();

    expect($agents)->toHaveCount(2)
        ->and($agents[0]->version)->toBe('1')
        ->and($agents[1]->version)->toBe('2');
})->group('SPEC-033');

// AC5 — malformed input contributes nothing and throws nothing. Third-party
// manifests are untrusted input; a reader that crashes on one is a reader that
// cannot be pointed at an upload folder.
it('skips a malformed softwareAgent instead of guessing', function (mixed $agent) {
    expect(spec033Report([spec033Action($agent)])->softwareAgents())->toBe([]);
})->with([
    'a bare string' => ['ACME GenAI'],
    'an integer' => [7],
    'a float' => [1.5],
    'a boolean' => [true],
    'null' => [null],
    'a list' => [['ACME GenAI']],
    'an object without a name' => [['version' => '2.1']],
    'a name that is not a string' => [['name' => 42]],
    'a name that is an array' => [['name' => ['ACME']]],
])->group('SPEC-033');

// AC5 continued — one bad entry must not take its well-formed siblings with it.
it('still returns well-formed agents beside a malformed one', function () {
    $agents = spec033Report([
        spec033Action('a bare string'),
        spec033Action(['name' => 'Survivor', 'version' => '3']),
        spec033Action(['name' => 42]),
    ])->softwareAgents();

    expect($agents)->toHaveCount(1)
        ->and($agents[0]->name)->toBe('Survivor')
        ->and($agents[0]->version)->toBe('3');
})->group('SPEC-033');

// AC5 continued — a version that is present but not a string is not a version.
// Dropping the agent entirely would lose a name we did read correctly, so the
// name survives and the version does not.
it('keeps a valid name when the version is malformed', function () {
    $agents = spec033Report([spec033Action(['name' => 'Named', 'version' => 9])])
        ->softwareAgents();

    expect($agents)->toHaveCount(1)
        ->and($agents[0]->name)->toBe('Named')
        ->and($agents[0]->version)->toBeNull();
})->group('SPEC-033');

// AC6 — nothing to report is an empty list, never an exception.
it('returns an empty list when there is nothing to report', function (ManifestReport $report) {
    expect($report->softwareAgents())->toBe([]);
})->with([
    'no manifest at all' => [fn () => new ManifestReport(null, null, [], [], null)],
    'no actions assertion' => [fn () => new ManifestReport(
        'urn:c2pa:test', null, [['label' => 'c2pa.thumbnail.claim', 'data' => []]], [],
    )],
    'actions key absent' => [fn () => new ManifestReport(
        'urn:c2pa:test', null, [['label' => 'c2pa.actions.v2', 'data' => []]], [],
    )],
    'actions is not a list' => [fn () => new ManifestReport(
        'urn:c2pa:test', null, [['label' => 'c2pa.actions.v2', 'data' => ['actions' => 'nope']]], [],
    )],
    'an action is not an array' => [fn () => spec033Report(['nope'])],
    'an action carries no softwareAgent' => [fn () => spec033Report([
        ['action' => 'c2pa.created', 'digitalSourceType' => DigitalSourceType::TrainedAlgorithmicMedia->value],
    ])],
])->group('SPEC-033');

// AC7 — both actions labels count, per the rule digitalSourceTypes() applies.
it('honours both the v1 and v2 actions labels', function () {
    $report = new ManifestReport(
        'urn:c2pa:test',
        null,
        [
            ['label' => 'c2pa.actions', 'data' => ['actions' => [spec033Action(['name' => 'Old'])]]],
            ['label' => 'c2pa.actions.v2', 'data' => ['actions' => [spec033Action(['name' => 'New'])]]],
        ],
        [],
    );

    $names = array_map(fn (SoftwareAgent $a) => $a->name, $report->softwareAgents());

    expect($names)->toBe(['Old', 'New']);
})->group('SPEC-033');

// AC7 continued — the label rule is a prefix match, not a whitelist, but it must
// not reach an unrelated assertion that merely mentions actions.
it('ignores an assertion whose label is not an actions assertion', function () {
    $report = new ManifestReport(
        'urn:c2pa:test',
        null,
        [['label' => 'com.example.actions', 'data' => ['actions' => [spec033Action(['name' => 'Nope'])]]]],
        [],
    );

    expect($report->softwareAgents())->toBe([]);
})->group('SPEC-033');
