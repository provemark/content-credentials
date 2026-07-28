<?php

declare(strict_types=1);

use Http\Mock\Client;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Signing\Asset;

/**
 * SPEC-007 — TSA timestamping, reading side (ManifestReport::hasTimestamp()).
 * Tests-first: reference an accessor that does not exist yet; RED until
 * implemented (D4 amends SPEC-003). Driven by a mock PSR-18 client — no live
 * network, no TSA. Reuses the manifest-store helpers from
 * SigningServiceReaderTest.php (readerFor / manifestStore / readStoreResponse /
 * AI_TRAINED_URI_READ).
 *
 * @see specs/SPEC-007-tsa-timestamping.md
 */

/**
 * A signed AI manifest store whose signature_info carries the given `time`
 * value (D1: the timestamp surfaces as signature_info.time). Pass null to omit
 * the field entirely (an untimestamped signature).
 *
 * @return array<string, mixed>
 */
function timestampStore(mixed $time): array
{
    $signatureInfo = ['alg' => 'Es256', 'issuer' => 'C2PA Test Signing Cert', 'common_name' => 'C2PA Signer'];
    if ($time !== null) {
        $signatureInfo['time'] = $time;
    }

    return manifestStore(
        [['action' => 'c2pa.created', 'digitalSourceType' => AI_TRAINED_URI_READ]],
        $signatureInfo,
        [['code' => 'signingCredential.untrusted', 'explanation' => 'signing certificate untrusted']],
    );
}

// --- AC1: a timestamped manifest is reported as timestamped -----------------

it('reports a timestamped manifest as timestamped', function () {
    $client = new Client;
    $client->addResponse(readStoreResponse(timestampStore('2026-07-28T10:15:30+00:00')));

    $report = readerFor($client)->read(new Asset('SIGNED-PNG', MediaType::Png));

    expect($report->hasTimestamp())->toBeTrue();
})->group('SPEC-007');

// --- AC2: an untimestamped manifest is reported as not timestamped ----------

it('reports a manifest without a timestamp field as not timestamped', function () {
    $client = new Client;
    $client->addResponse(readStoreResponse(timestampStore(null))); // signature_info has no `time`

    $report = readerFor($client)->read(new Asset('SIGNED-PNG', MediaType::Png));

    expect($report->hasManifest())->toBeTrue()
        ->and($report->hasTimestamp())->toBeFalse();
})->group('SPEC-007');

it('reports no timestamp when there is no manifest', function () {
    $client = new Client;
    $client->addResponse(readStoreResponse([]));

    $report = readerFor($client)->read(new Asset('PLAIN', MediaType::Png));

    expect($report->hasManifest())->toBeFalse()
        ->and($report->hasTimestamp())->toBeFalse();
})->group('SPEC-007');

// --- AC3: a malformed timestamp does not crash reading (error path) ---------

it('treats a malformed timestamp as absent without throwing', function (mixed $badTime) {
    $client = new Client;
    $client->addResponse(readStoreResponse(timestampStore($badTime)));

    $report = readerFor($client)->read(new Asset('SIGNED-PNG', MediaType::Png));

    // No exception escapes; the timestamp is simply not recognised...
    expect($report->hasTimestamp())->toBeFalse();

    // ...and the other accessors still behave per SPEC-003/005.
    $signer = $report->signer() ?? throw new RuntimeException('expected a signer');
    expect($signer->issuer)->toBe('C2PA Test Signing Cert')
        ->and($report->isAiGenerated())->toBeTrue();
})->with([
    'not a date' => ['not-a-timestamp'],
    'empty string' => [''],
    'non-string (int)' => [1234567890],
    'non-string (array)' => [['nested' => true]],
])->group('SPEC-007');
