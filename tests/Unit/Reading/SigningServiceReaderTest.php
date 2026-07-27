<?php

declare(strict_types=1);

use Http\Client\Exception as HttpClientException;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadFailedException;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadResponseException;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadTransportException;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Reading\SigningServiceReader;
use Provemark\ContentCredentials\Core\Reading\ValidationState;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * SPEC-003 — Reading & verification (ReaderInterface + SigningServiceReader).
 * Tests-first: reference src/Core/Reading classes that do not exist yet; RED
 * until implemented. Driven entirely by a mock PSR-18 client — no live network.
 *
 * @see specs/SPEC-003-reading.md
 */
const AI_TRAINED_URI_READ = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

function readerFor(MockClient $client, string $baseUrl = 'https://sign.test', string $apiKey = 'secret'): SigningServiceReader
{
    $factory = new Psr17Factory;

    return new SigningServiceReader($client, $factory, $factory, new SigningServiceConfig($baseUrl, $apiKey));
}

/**
 * @param  array<string, mixed>  $store
 */
function readStoreResponse(array $store): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($store, JSON_THROW_ON_ERROR));
}

/**
 * A manifest store as returned by /v1/read, with a single AI-generated action.
 *
 * @param  array<int, array<string, mixed>>  $actions
 * @param  array<string, mixed>|null  $signatureInfo
 * @param  list<array{code: string, explanation?: string}>  $validationStatus
 * @return array<string, mixed>
 */
function manifestStore(array $actions, ?array $signatureInfo, array $validationStatus): array
{
    $manifest = [
        'claim_generator_info' => [['name' => 'Content Credentials', 'version' => '0.1.0']],
        'assertions' => [
            ['label' => 'c2pa.actions.v2', 'data' => ['actions' => $actions]],
        ],
    ];
    if ($signatureInfo !== null) {
        $manifest['signature_info'] = $signatureInfo;
    }

    return [
        'active_manifest' => 'urn:c2pa:test',
        'manifests' => ['urn:c2pa:test' => $manifest],
        'validation_status' => $validationStatus,
    ];
}

/**
 * @return array<string, mixed>
 */
function aiStore(): array
{
    return manifestStore(
        [['action' => 'c2pa.created', 'digitalSourceType' => AI_TRAINED_URI_READ]],
        ['alg' => 'Es256', 'issuer' => 'C2PA Test Signing Cert', 'common_name' => 'C2PA Signer'],
        [['code' => 'signingCredential.untrusted', 'explanation' => 'signing certificate untrusted']],
    );
}

function onlyReadRequest(MockClient $client): RequestInterface
{
    $requests = $client->getRequests();
    expect($requests)->toHaveCount(1);

    return $requests[0] ?? throw new RuntimeException('no request was recorded');
}

// --- AC1: reads an AI-generated PNG manifest -------------------------------

it('reads an AI-generated PNG manifest', function () {
    $client = new MockClient;
    $client->addResponse(readStoreResponse(aiStore()));

    $report = readerFor($client)->read(new Asset('SIGNED-PNG', MediaType::Png));

    expect($report)->toBeInstanceOf(ManifestReport::class)
        ->and($report->hasManifest())->toBeTrue()
        ->and($report->activeManifestLabel())->toBe('urn:c2pa:test')
        ->and($report->isAiGenerated())->toBeTrue()
        ->and($report->digitalSourceTypes())->toBe([AI_TRAINED_URI_READ]);

    $signer = $report->signer() ?? throw new RuntimeException('expected a signer');
    expect($signer->issuer)->toBe('C2PA Test Signing Cert')
        ->and($signer->commonName)->toBe('C2PA Signer')
        ->and($signer->algorithm)->toBe('Es256');

    expect($report->assertions())->toBe([
        ['label' => 'c2pa.actions.v2', 'data' => ['actions' => [
            ['action' => 'c2pa.created', 'digitalSourceType' => AI_TRAINED_URI_READ],
        ]]],
    ]);
})->group('SPEC-003');

// --- AC2: request maps asset onto /v1/read ---------------------------------

it('maps the asset onto the /v1/read request', function () {
    $client = new MockClient;
    $client->addResponse(readStoreResponse(aiStore()));

    readerFor($client)->read(new Asset('SIGNED-PNG', MediaType::Png));

    $request = onlyReadRequest($client);
    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://sign.test/v1/read')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer secret')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json');

    $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($body)->toEqual([
        'content' => base64_encode('SIGNED-PNG'),
        'mime_type' => 'image/png',
    ]);
})->group('SPEC-003');

// --- AC3: no C2PA data is not an error -------------------------------------

it('treats absent C2PA data as an empty report, not an error', function () {
    $client = new MockClient;
    $client->addResponse(readStoreResponse([]));

    $report = readerFor($client)->read(new Asset('PLAIN', MediaType::Png));

    expect($report->hasManifest())->toBeFalse()
        ->and($report->signer())->toBeNull()
        ->and($report->assertions())->toBe([])
        ->and($report->isAiGenerated())->toBeFalse()
        ->and($report->digitalSourceTypes())->toBe([]);
})->group('SPEC-003');

// --- AC4: non-AI content reports false -------------------------------------

it('reports non-AI content as not AI-generated', function () {
    $client = new MockClient;
    $client->addResponse(readStoreResponse(manifestStore(
        [['action' => 'c2pa.created', 'digitalSourceType' => 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalCapture']],
        ['alg' => 'Es256', 'issuer' => 'C2PA Test Signing Cert'],
        [],
    )));

    $report = readerFor($client)->read(new Asset('SIGNED', MediaType::Png));

    expect($report->isAiGenerated())->toBeFalse()
        ->and($report->digitalSourceTypes())->not->toContain(AI_TRAINED_URI_READ);
})->group('SPEC-003');

// --- AC5: non-2xx response is a typed failure (error path) -----------------

it('throws ReadFailedException on a non-2xx response', function (int $status) {
    $client = new MockClient;
    $client->addResponse(new Response($status, [], json_encode(['error' => 'boom'], JSON_THROW_ON_ERROR)));

    expect(fn () => readerFor($client)->read(new Asset('B', MediaType::Png)))
        ->toThrow(ReadFailedException::class);
})->with([[500], [401]])->group('SPEC-003');

it('the read failure carries the status and service error, and implements the Core interface', function () {
    $client = new MockClient;
    $client->addResponse(new Response(500, [], json_encode(['error' => 'boom'], JSON_THROW_ON_ERROR)));

    try {
        readerFor($client)->read(new Asset('B', MediaType::Png));
        throw new RuntimeException('expected ReadFailedException was not thrown');
    } catch (ReadFailedException $e) {
        expect($e->getMessage())->toContain('500')
            ->and($e->getMessage())->toContain('boom')
            ->and($e)->toBeInstanceOf(ContentCredentialsException::class);
    }
})->group('SPEC-003');

// --- AC6: transport failure is wrapped (error path) ------------------------

it('wraps a PSR-18 transport failure', function () {
    $client = new MockClient;
    $client->addException(new class('boom') extends RuntimeException implements ClientExceptionInterface, HttpClientException {});

    try {
        readerFor($client)->read(new Asset('B', MediaType::Png));
        throw new RuntimeException('expected ReadTransportException was not thrown');
    } catch (ReadTransportException $e) {
        expect($e->getPrevious())->toBeInstanceOf(ClientExceptionInterface::class)
            ->and($e)->toBeInstanceOf(ContentCredentialsException::class);
    }
})->group('SPEC-003');

// --- AC7: unparseable 2xx body is rejected (malformed-input path) ----------

it('rejects an unparseable 2xx body', function () {
    $client = new MockClient;
    $client->addResponse(new Response(200, [], 'not json at all'));

    expect(fn () => readerFor($client)->read(new Asset('B', MediaType::Png)))
        ->toThrow(ReadResponseException::class);
})->group('SPEC-003');

// --- AC8: defensive parse of a partial manifest store ----------------------

it('defensively parses a manifest store missing signature_info and validation_status', function () {
    $client = new MockClient;
    $store = [
        'active_manifest' => 'urn:c2pa:test',
        'manifests' => ['urn:c2pa:test' => [
            'assertions' => [['label' => 'c2pa.actions.v2', 'data' => ['actions' => [['action' => 'c2pa.created']]]]],
        ]],
    ];
    $client->addResponse(readStoreResponse($store));

    $report = readerFor($client)->read(new Asset('B', MediaType::Png));

    expect($report->hasManifest())->toBeTrue()
        ->and($report->signer())->toBeNull()
        ->and($report->validationStatusCodes())->toBe([]);
})->group('SPEC-003');

// --- AC9: surfaces validation-status codes and trust -----------------------

it('surfaces the untrusted validation code and reports not trusted', function () {
    $client = new MockClient;
    $client->addResponse(readStoreResponse(aiStore()));

    $report = readerFor($client)->read(new Asset('B', MediaType::Png));

    expect($report->validationStatusCodes())->toContain('signingCredential.untrusted')
        ->and($report->isTrusted())->toBeFalse();
})->group('SPEC-003');

it('reports trusted when no untrusted code is present', function () {
    $client = new MockClient;
    $client->addResponse(readStoreResponse(manifestStore(
        [['action' => 'c2pa.created', 'digitalSourceType' => AI_TRAINED_URI_READ]],
        ['alg' => 'Es256', 'issuer' => 'C2PA Test Signing Cert'],
        [],
    )));

    $report = readerFor($client)->read(new Asset('B', MediaType::Png));

    expect($report->isTrusted())->toBeTrue();
})->group('SPEC-003');

// --- AC10: the API key never leaks -----------------------------------------

it('never leaks the API key in failure messages', function () {
    $client = new MockClient;
    $client->addResponse(new Response(500, [], json_encode(['error' => 'boom'], JSON_THROW_ON_ERROR)));

    try {
        readerFor($client, apiKey: 'super-secret-key')->read(new Asset('B', MediaType::Png));
        throw new RuntimeException('expected ReadFailedException was not thrown');
    } catch (ReadFailedException $e) {
        expect($e->getMessage())->not->toContain('super-secret-key')
            ->and((string) $e)->not->toContain('super-secret-key');
    }
})->group('SPEC-003');

// =========================================================================
// SPEC-005 — signature-validity verdict (isSignatureValid / validationState).
// @see specs/SPEC-005-signature-validity.md
// =========================================================================

/**
 * @param  array<string, mixed>  $store
 * @return array<string, mixed>
 */
function withState(array $store, ?string $state): array
{
    if ($state !== null) {
        $store['validation_state'] = $state;
    }

    return $store;
}

// --- AC1: intact, untrusted manifest is signature-valid --------------------

it('reports an intact untrusted manifest as signature-valid', function () {
    $client = new MockClient;
    $client->addResponse(readStoreResponse(withState(aiStore(), 'Valid'))); // aiStore() carries signingCredential.untrusted

    $report = readerFor($client)->read(new Asset('B', MediaType::Png));

    expect($report->isSignatureValid())->toBeTrue()
        ->and($report->validationState())->toBe(ValidationState::Valid)
        ->and($report->isTrusted())->toBeFalse(); // independent of validity
})->group('SPEC-005');

// --- AC2: tampered manifest is not signature-valid -------------------------

it('reports a tampered manifest as not signature-valid', function () {
    $store = manifestStore(
        [['action' => 'c2pa.created', 'digitalSourceType' => AI_TRAINED_URI_READ]],
        ['alg' => 'Es256', 'issuer' => 'C2PA Test Signing Cert'],
        [['code' => 'signingCredential.untrusted'], ['code' => 'assertion.hashedURI.mismatch']],
    );
    $client = new MockClient;
    $client->addResponse(readStoreResponse(withState($store, 'Invalid')));

    $report = readerFor($client)->read(new Asset('B', MediaType::Png));

    expect($report->isSignatureValid())->toBeFalse()
        ->and($report->validationState())->toBe(ValidationState::Invalid);
})->group('SPEC-005');

// --- AC3: trusted manifest is signature-valid ------------------------------

it('reports a trusted manifest as signature-valid', function () {
    $store = manifestStore(
        [['action' => 'c2pa.created', 'digitalSourceType' => AI_TRAINED_URI_READ]],
        ['alg' => 'Es256', 'issuer' => 'C2PA Test Signing Cert'],
        [],
    );
    $client = new MockClient;
    $client->addResponse(readStoreResponse(withState($store, 'Trusted')));

    $report = readerFor($client)->read(new Asset('B', MediaType::Png));

    expect($report->isSignatureValid())->toBeTrue()
        ->and($report->validationState())->toBe(ValidationState::Trusted);
})->group('SPEC-005');

// --- AC4: missing/unknown state does not assert validity (edge path) -------

it('does not assert validity for a missing or unknown state', function (?string $state) {
    $client = new MockClient;
    $client->addResponse(readStoreResponse(withState(aiStore(), $state)));

    $report = readerFor($client)->read(new Asset('B', MediaType::Png));

    expect($report->validationState())->toBeNull()
        ->and($report->isSignatureValid())->toBeFalse();
})->with([[null], ['Weird']])->group('SPEC-005');

// --- AC5: no manifest -> not signature-valid -------------------------------

it('reports no signature validity when there is no manifest', function () {
    $client = new MockClient;
    $client->addResponse(readStoreResponse([]));

    $report = readerFor($client)->read(new Asset('B', MediaType::Png));

    expect($report->isSignatureValid())->toBeFalse()
        ->and($report->validationState())->toBeNull();
})->group('SPEC-005');
