<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Trust\RevocationSource;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\C2paVerifierReader;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadFailedException;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Reading\VerifiedRead;
use Provemark\ContentCredentials\Core\Signing\Asset;

/*
 * SPEC-046: the verifier's report of the same read. The fixtures come from
 * provemark/c2pa-verifier's tests/Fixtures/revocation/ (tests/Fixtures/spec046-README.md):
 * cs-parent.png is signed by signer P under a throw-away root and is Trusted
 * against it; the verifier asks a revocation source about the intermediate
 * (its SPEC-087) and about P, and spec046-p-good.der is P's good response.
 */

function spec046Fixture(string $name): string
{
    $bytes = file_get_contents(dirname(__DIR__, 2).'/Fixtures/spec046-'.$name);

    if (! is_string($bytes) || $bytes === '') {
        throw new RuntimeException("missing fixture spec046-{$name}");
    }

    return $bytes;
}

function spec046Parent(): Asset
{
    return new Asset(spec046Fixture('cs-parent.png'), MediaType::Png);
}

/** Every request the source got, as the CN of the certificate asked about. */
final class Spec046Requests
{
    /** @var list<string> */
    public array $subjects = [];
}

/** Answers P's good response about P, nothing about anything else, and records every request. */
function spec046Source(Spec046Requests $requests): RevocationSource
{
    return new class($requests) implements RevocationSource
    {
        public function __construct(private Spec046Requests $requests) {}

        public function ocspResponse(string $subject, string $issuer, array $urls): ?string
        {
            $parsed = openssl_x509_parse("-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($subject), 64, "\n")."-----END CERTIFICATE-----\n");
            $cn = is_array($parsed) && is_array($parsed['subject'] ?? null) && is_string($parsed['subject']['CN'] ?? null) ? $parsed['subject']['CN'] : '?';
            $this->requests->subjects[] = $cn;

            return $cn === 'Signer P (revocation)' ? spec046Fixture('p-good.der') : null;
        }
    };
}

/** @return array<string, mixed> every accessor of the report, by name */
function spec046Accessors(ManifestReport $report): array
{
    return [
        'hasManifest' => $report->hasManifest(),
        'activeManifestLabel' => $report->activeManifestLabel(),
        'declaredSpecVersion' => $report->declaredSpecVersion(),
        'signer' => $report->signer(),
        'assertions' => $report->assertions(),
        'validationStatusCodes' => $report->validationStatusCodes(),
        'isTrusted' => $report->isTrusted(),
        'validationState' => $report->validationState(),
        'isSignatureValid' => $report->isSignatureValid(),
        'hasTimestamp' => $report->hasTimestamp(),
        'isAiGenerated' => $report->isAiGenerated(),
        'involvesGenerativeAi' => $report->involvesGenerativeAi(),
        'isVerifiedAiGenerated' => $report->isVerifiedAiGenerated(),
        'softwareAgents' => $report->softwareAgents(),
        'digitalSourceTypes' => $report->digitalSourceTypes(),
        'ingredients' => $report->ingredients(),
    ];
}

/** @return array<string, mixed> */
function spec046Evidence(VerifiedRead $read): array
{
    $evidence = json_decode($read->verification->evidenceToJson(), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($evidence));

    /** @var array<string, mixed> $evidence */
    return $evidence;
}

it('AC1: one read gives the ManifestReport and the verifier report of the same verify() call', function () {
    $requests = new Spec046Requests;
    $reader = new C2paVerifierReader(spec046Fixture('throw-away-root.settings.json'), new Verifier(revocationSource: spec046Source($requests)));

    $read = $reader->readVerified(spec046Parent());
    // one verify() asks the source once about the intermediate and once about P
    expect($requests->subjects)->toBe(['Throw-away Intermediate (revocation)', 'Signer P (revocation)']);

    expect($read)->toBeInstanceOf(VerifiedRead::class)
        ->and(spec046Accessors($read->report))->toEqual(spec046Accessors($reader->read(spec046Parent())))
        ->and($read->report->validationState()?->value)->toBe('Trusted')
        ->and($read->verification->result->toArray()['validation_state'] ?? null)->toBe('Trusted');
})->group('SPEC-046');

it('AC2: the verifier report holds the evidence of that read', function () {
    $read = (new C2paVerifierReader(spec046Fixture('throw-away-root.settings.json')))->readVerified(spec046Parent());
    $evidence = spec046Evidence($read);

    expect($evidence['format'] ?? null)->toBe('c2pa-verifier/evidence')
        ->and($evidence['version'] ?? null)->toBe(1)
        ->and($evidence['validation_state'] ?? null)->toBe($read->report->validationState()?->value)
        ->and($evidence['active_manifest'] ?? null)->toBe($read->report->activeManifestLabel());
})->group('SPEC-046');

it('AC3: the verifier given is the one that reads, and its source answers', function () {
    $requests = new Spec046Requests;
    $reader = new C2paVerifierReader(spec046Fixture('throw-away-root.settings.json'), new Verifier(revocationSource: spec046Source($requests)));

    $evidence = spec046Evidence($reader->readVerified(spec046Parent()));
    $responses = [];
    foreach ((array) ($evidence['manifests'] ?? []) as $manifest) {
        foreach ((array) (is_array($manifest) ? ($manifest['ocsp'] ?? []) : []) as $entry) {
            if (is_array($entry)) {
                $response = is_string($entry['response'] ?? null) ? base64_decode($entry['response'], true) : false;
                $responses[] = [$entry['source'] ?? null, $entry['outcome'] ?? null, $entry['cert_status'] ?? null, $response === false ? null : hash('sha256', $response)];
            }
        }
    }

    expect($requests->subjects)->toContain('Signer P (revocation)')
        ->and($responses)->toBe([['fetched', 'chosen', 'good', hash('sha256', spec046Fixture('p-good.der'))]]);
})->group('SPEC-046');

it('AC4: read() without a Verifier gives what readVerified() gives as its report', function () {
    $reader = new C2paVerifierReader(spec046Fixture('throw-away-root.settings.json'));

    expect(spec046Accessors($reader->read(spec046Parent())))->toEqual(spec046Accessors($reader->readVerified(spec046Parent())->report))
        ->and($reader->read(spec046Parent())->validationState()?->value)->toBe('Trusted');
})->group('SPEC-046');

it('AC5: a refusal is the same refusal, never half a result', function (Asset $asset) {
    $reader = new C2paVerifierReader;
    $message = static function (callable $read): string {
        try {
            $read();
        } catch (ReadFailedException $e) {
            return $e->getMessage();
        }

        return '';
    };

    $fromRead = $message(fn () => $reader->read($asset));
    $fromReadVerified = $message(fn () => $reader->readVerified($asset));

    expect($fromRead)->not->toBe('')
        ->and($fromReadVerified)->toBe($fromRead);
})->with([
    'TIFF' => fn () => new Asset((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/signed-spec042.tiff'), MediaType::Tiff),
    'SVG' => fn () => new Asset((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/signed-spec042.svg'), MediaType::Svg),
    'truncated' => fn () => new Asset(substr(spec046Fixture('cs-parent.png'), 0, 20000), MediaType::Png),
])->group('SPEC-046');

it('AC7: lists given as TrustSettings::fromTrustLists() are named in the evidence', function () {
    $signers = spec046Fixture('throw-away-root.pem');
    $tsas = (string) file_get_contents(dirname(__DIR__, 3).'/certs/trust_anchors.pem');

    $read = (new C2paVerifierReader(TrustSettings::fromTrustLists($signers, $tsas)))->readVerified(spec046Parent());
    $lists = array_map(
        static fn (mixed $list): array => is_array($list) ? [$list['kind'] ?? null, $list['sha256'] ?? null] : [],
        (array) (spec046Evidence($read)['trust_lists'] ?? []),
    );
    $document = json_encode(['verify' => ['verify_trust' => true], 'trust' => ['trust_anchors' => $signers.$tsas]], JSON_THROW_ON_ERROR);

    expect($lists)->toBe([['manifest', hash('sha256', $signers)], ['tsa', hash('sha256', $tsas)]])
        ->and(spec046Accessors($read->report))->toEqual(spec046Accessors((new C2paVerifierReader($document))->read(spec046Parent())));
})->group('SPEC-046');

it('AC8: anything but null, a string or a TrustSettings is a TypeError from PHP itself; blank and null mean without trust', function () {
    expect(fn () => new C2paVerifierReader(42))->toThrow(TypeError::class) // @phpstan-ignore argument.type
        ->and(fn () => new C2paVerifierReader(new stdClass))->toThrow(TypeError::class); // @phpstan-ignore argument.type

    foreach ([null, '', "  \n"] as $none) {
        expect((new C2paVerifierReader($none))->read(spec046Parent())->validationState()?->value)->toBe('Valid');
    }
})->group('SPEC-046');
