<?php

declare(strict_types=1);

use Eris\Generator;
use Eris\Generators;
use Eris\TestTrait;
use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Provemark\ContentCredentials\Core\Manifest\DigitalSourceType;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\ReaderInterface;
use Provemark\ContentCredentials\Core\Reading\SigningServiceReader;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Signing\SigningServiceSigner;
use Provemark\ContentCredentials\Tests\Integration\Property\ProvenanceModel;

uses(TestTrait::class);

/**
 * Stateful properties over the C2PA provenance chain (integration).
 *
 * Unlike the Core property tests, real state lives here: each signing mutates
 * the asset's manifest store, and the result depends on everything that came
 * before. That is exactly the shape stateful property testing is for.
 *
 * Requires the signing service (docker compose up -d --build). Skips cleanly
 * when it is not reachable, so `composer check` stays green without it.
 *
 * NOTE: each command is a real HTTP round-trip, so sequences are capped and the
 * case count is deliberately small. Run it like the e2e harness, not on every
 * commit:  vendor/bin/pest --group=provenance
 */

// --- wiring ----------------------------------------------------------------

function pbtApiKey(): string
{
    $key = getenv('CONTENTAUTH_API_KEY') ?: '';
    $envFile = dirname(__DIR__, 3).'/.env';

    if ($key === '' && is_file($envFile)) {
        foreach ((array) file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
            if (is_string($line) && str_starts_with($line, 'CONTENTAUTH_API_KEY=')) {
                $key = substr($line, strlen('CONTENTAUTH_API_KEY='));
            }
        }
    }

    return $key;
}

function pbtBaseUrl(): string
{
    return getenv('CONTENTAUTH_SERVICE_URL') ?: 'http://localhost:3000';
}

function pbtServiceReachable(): bool
{
    if (pbtApiKey() === '') {
        return false;
    }

    $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);

    return @file_get_contents(pbtBaseUrl().'/health', false, $context) !== false;
}

/** @return array{SignerInterface, ReaderInterface} */
function pbtSignerAndReader(): array
{
    $factory = new Psr17Factory;
    $config = new SigningServiceConfig(pbtBaseUrl(), pbtApiKey());

    return [
        new SigningServiceSigner(new Client, $factory, $factory, $config),
        new SigningServiceReader(new Client, $factory, $factory, $config),
    ];
}

function pbtFixtureBytes(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/fixture.png');
}

// --- the command alphabet --------------------------------------------------

/**
 * Two commands. `sign` appends to the chain and takes an agent name; `read` is
 * a pure observation that must not disturb anything. Both are always enabled —
 * re-signing an already signed asset is legitimate, and reading an unsigned
 * asset must report absence rather than fail (SPEC-003).
 */
function pbtProvenanceCommand(): Generator
{
    return Generators::associative([
        'op' => Generators::elements(['sign', 'sign', 'read']),   // weighted towards signing
        'agent' => Generators::elements([
            'ACME GenAI Image Model', 'Model é漢', 'agent-2', 'x', 'Stable Something 1.5',
        ]),
        'version' => Generators::elements([null, '1.0.0', '3.1.0']),
    ]);
}

// --- the core stateful property -------------------------------------------

it('keeps the Article 50 marking intact across any chain of signings and reads', function () {
    [$signer, $reader] = pbtSignerAndReader();

    $this->limitTo(8);                     // each case is several HTTP round-trips

    $this->forAll(
        Generators::seq(pbtProvenanceCommand()),
    )
        ->then(function (array $commands) use ($signer, $reader) {
            $trained = DigitalSourceType::TrainedAlgorithmicMedia->value;
            $commands = array_slice($commands, 0, 4);   // bound the chain length

            $bytes = pbtFixtureBytes();
            $model = ProvenanceModel::unsigned(MediaType::Png);

            foreach ($commands as $command) {
                if ($command['op'] === 'sign') {
                    $manifest = ManifestBuilder::forAiGenerated($model->mediaType)
                        ->withSoftwareAgent($command['agent'], $command['version'])
                        ->withClaimGenerator('Content Credentials (pbt)', '0.1.0')
                        ->build();

                    $signed = $signer->sign(new Asset($bytes, $model->mediaType), $manifest);

                    // The signed bytes are the asset from here on — never re-encoded.
                    $bytes = $signed->bytes;
                    $model = $model->afterSign();

                    expect($signed->mediaType)->toBe($model->mediaType)
                        ->and($bytes)->not->toBe('');
                }

                // Observe after every command, whatever it was.
                $report = $reader->read(new Asset($bytes, $model->mediaType));

                expect($report->hasManifest())->toBe($model->expectsManifest());

                if (! $model->expectsManifest()) {
                    // Absence of C2PA data is an empty report, not an error (SPEC-003).
                    expect($report->isAiGenerated())->toBeFalse()
                        ->and($report->digitalSourceTypes())->toBe([]);

                    continue;
                }

                // THE invariant: the AI marking survives every further signing.
                expect($report->isAiGenerated())->toBe($model->expectsAiMarking())
                    ->and($report->digitalSourceTypes())->toContain($trained)
                    ->and($report->isSignatureValid())->toBeTrue();

                // Signing produced a cryptographically valid claim over these
                // exact bytes; if any layer had mutated them, this would fail.
            }
        });
})->group('pbt', 'stateful', 'provenance', 'integration')
    ->skip(fn () => ! pbtServiceReachable(), 'signing service not reachable — start it with docker compose up -d');

// --- reading is pure -------------------------------------------------------

it('reads the same report however often it is read', function () {
    [$signer, $reader] = pbtSignerAndReader();

    $this->limitTo(4);

    $this->forAll(Generators::choose(1, 3))
        ->then(function (int $signings) use ($signer, $reader) {
            $bytes = pbtFixtureBytes();

            for ($i = 0; $i < $signings; $i++) {
                $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
                    ->withSoftwareAgent("agent-$i")
                    ->build();
                $bytes = $signer->sign(new Asset($bytes, MediaType::Png), $manifest)->bytes;
            }

            $before = $bytes;
            $first = $reader->read(new Asset($bytes, MediaType::Png));
            $second = $reader->read(new Asset($bytes, MediaType::Png));

            expect($bytes)->toBe($before)
                ->and($second->hasManifest())->toBe($first->hasManifest())
                ->and($second->isAiGenerated())->toBe($first->isAiGenerated())
                ->and($second->digitalSourceTypes())->toBe($first->digitalSourceTypes())
                ->and($second->activeManifestLabel())->toBe($first->activeManifestLabel())
                ->and($second->isSignatureValid())->toBe($first->isSignatureValid());                        // reading mutates nothing
        });
})->group('pbt', 'stateful', 'provenance', 'integration')
    ->skip(fn () => ! pbtServiceReachable(), 'signing service not reachable — start it with docker compose up -d');

// --- ASSUMPTIONS: confirm these on the first run ---------------------------

/**
 * These two encode how c2pa-rs is BELIEVED to behave when re-signing, but they
 * were not verified against a running service when written. Run them first in
 * isolation. If one fails, the finding itself is the value — it tells you the
 * chain does not behave as the mental model says, which is exactly the kind of
 * thing worth writing up. Adjust or delete rather than weakening the tests above.
 */
it('makes the most recent signing the active manifest', function () {
    [$signer, $reader] = pbtSignerAndReader();

    $this->limitTo(3);

    $this->forAll(Generators::choose(2, 3))
        ->then(function (int $signings) use ($signer, $reader) {
            $bytes = pbtFixtureBytes();
            $lastAgent = '';
            $labels = [];

            for ($i = 0; $i < $signings; $i++) {
                $lastAgent = "agent-$i";
                $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
                    ->withSoftwareAgent($lastAgent)
                    ->build();
                $bytes = $signer->sign(new Asset($bytes, MediaType::Png), $manifest)->bytes;

                $labels[] = $reader->read(new Asset($bytes, MediaType::Png))->activeManifestLabel();
            }

            $report = $reader->read(new Asset($bytes, MediaType::Png));

            // ASSUMPTION 1: the active manifest carries the LAST agent used.
            $agentNames = [];
            foreach ($report->assertions() as $assertion) {
                foreach ($assertion['data']['actions'] ?? [] as $action) {
                    if (is_array($action) && isset($action['softwareAgent']['name'])) {
                        $agentNames[] = $action['softwareAgent']['name'];
                    }
                }
            }
            expect($agentNames)->toContain($lastAgent)
                ->and($labels)->toBe(array_values(array_unique($labels)));

            // ASSUMPTION 2: each signing yields a distinct active-manifest label.
        });
})->group('pbt', 'stateful', 'provenance', 'integration', 'assumption')
    ->skip(fn () => ! pbtServiceReachable(), 'signing service not reachable — start it with docker compose up -d');
