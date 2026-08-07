<?php

declare(strict_types=1);

use Eris\Generator;
use Eris\Generators;
use Eris\TestTrait;
use Provemark\ContentCredentials\Core\Manifest\Exception\InvalidSoftwareAgentException;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Tests\Unit\Property\BuilderModel;
use Provemark\ContentCredentials\Tests\Unit\Property\Gen;

uses(TestTrait::class);

/**
 * SPEC-001 AC5/D3 — stateful properties over with* call sequences.
 *
 * The stateless properties check single builds. These generate whole SEQUENCES
 * of builder calls and drive the real builder and a shadow model in lockstep,
 * asserting after every step. That is the model-based (stateful) pattern:
 * commands, a precondition-free transition function, and a postcondition
 * comparing system to model.
 *
 * ManifestBuilder is immutable, so "state" is the instance you currently hold —
 * each command produces a new one. The bug class hunted here is accumulation
 * order: a with* that clobbers an unrelated slot, or output that depends on the
 * path taken rather than on the last write of each kind.
 */

/**
 * The command alphabet: which setter to call, with which arguments.
 * Blank names are included on purpose — they must make build() throw, and the
 * model predicts exactly that.
 */
function pbtCommandGenerator(): Generator
{
    return Generators::associative([
        'op' => Generators::elements(['softwareAgent', 'claimGenerator']),
        'name' => Generators::oneOf(
            Gen::softwareAgentName(),
            Generators::elements(['', '   ', "\t"]),   // blank: build() must fail
        ),
        'version' => Gen::optionalVersion(),
    ]);
}

/** Apply one command to both the real builder and the model. */
function pbtApply(ManifestBuilder $builder, BuilderModel $model, array $command): array
{
    ['op' => $op, 'name' => $name, 'version' => $version] = $command;

    return $op === 'softwareAgent'
        ? [$builder->withSoftwareAgent($name, $version), $model->withSoftwareAgent($name, $version)]
        : [$builder->withClaimGenerator($name, $version), $model->withClaimGenerator($name, $version)];
}

// --- The core stateful property -------------------------------------------

it('matches the shadow model after every step of any with* sequence', function () {
    $this->forAll(
        Gen::mediaType(),
        Generators::seq(pbtCommandGenerator()),
    )->then(function (MediaType $type, array $commands) {
        $builder = ManifestBuilder::forAiGenerated($type);
        $model = BuilderModel::initial($type);

        foreach ($commands as $command) {
            [$builder, $model] = pbtApply($builder, $model, $command);

            // Postcondition: the real builder agrees with the model, including
            // on whether building is possible at all.
            if ($model->canBuild()) {
                expect($builder->build()->toArray())->toBe($model->expectedToArray());
            } else {
                expect(fn () => $builder->build())->toThrow(InvalidSoftwareAgentException::class);
            }
        }
    });
})->group('SPEC-001', 'pbt', 'stateful');

// --- Last-write-wins: history collapses to the final writes ----------------

it('collapses any sequence to just the last write of each kind', function () {
    $this->forAll(
        Gen::mediaType(),
        Generators::seq(pbtCommandGenerator()),
    )->then(function (MediaType $type, array $commands) {
        $chained = ManifestBuilder::forAiGenerated($type);
        $model = BuilderModel::initial($type);

        foreach ($commands as $command) {
            [$chained, $model] = pbtApply($chained, $model, $command);
        }

        if (! $model->canBuild()) {
            return;   // covered by the property above
        }

        // Rebuild from the model's final state only — no history at all.
        $direct = ManifestBuilder::forAiGenerated($type)
            ->withSoftwareAgent($model->softwareAgent['name'], $model->softwareAgent['version']);

        if ($model->claimGenerator !== null) {
            $direct = $direct->withClaimGenerator($model->claimGenerator['name'], $model->claimGenerator['version']);
        }

        expect($chained->build()->toArray())->toBe($direct->build()->toArray());
    });
})->group('SPEC-001', 'pbt', 'stateful');

// --- The two setters commute ----------------------------------------------

it('gives the same manifest whichever setter is called first', function () {
    $this->forAll(
        Gen::mediaType(),
        Gen::softwareAgentName(),
        Gen::optionalVersion(),
        Gen::softwareAgentName(),
        Gen::optionalVersion(),
    )->then(function (MediaType $type, string $agent, ?string $agentV, string $claim, ?string $claimV) {
        $base = ManifestBuilder::forAiGenerated($type);

        $agentFirst = $base->withSoftwareAgent($agent, $agentV)->withClaimGenerator($claim, $claimV);
        $claimFirst = $base->withClaimGenerator($claim, $claimV)->withSoftwareAgent($agent, $agentV);

        expect($agentFirst->build()->toArray())->toBe($claimFirst->build()->toArray());
    });
})->group('SPEC-001', 'pbt', 'stateful');

// --- No command can retroactively change an earlier instance ---------------

it('leaves every intermediate builder untouched by later commands', function () {
    $this->forAll(
        Gen::mediaType(),
        Gen::softwareAgentName(),
        Generators::seq(pbtCommandGenerator()),
    )->then(function (MediaType $type, string $name, array $commands) {
        // A buildable starting point, and a snapshot of what it produces.
        $pinned = ManifestBuilder::forAiGenerated($type)->withSoftwareAgent($name);
        $snapshot = $pinned->build()->toArray();

        // Chain an arbitrary sequence off it, discarding the results.
        $branch = $pinned;
        $model = BuilderModel::initial($type)->withSoftwareAgent($name, null);
        foreach ($commands as $command) {
            [$branch, $model] = pbtApply($branch, $model, $command);
        }

        expect($pinned->build()->toArray())->toBe($snapshot);
    });
})->group('SPEC-001', 'pbt', 'stateful');
