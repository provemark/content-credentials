<?php

declare(strict_types=1);

use Provemark\C2paVerifier\Trust\RevocationSource;
use Provemark\C2paVerifier\Trust\TrustSettings;
use Provemark\C2paVerifier\Verifier\Verifier;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\C2paVerifierReader;
use Provemark\ContentCredentials\Core\Reading\Exception\InvalidValidationTimeException;
use Provemark\ContentCredentials\Core\Reading\VerifiedRead;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/*
 * SPEC-047: a validation time for the verifier read. The fixture is SPEC-046's
 * cs-parent.png under its throw-away root (tests/Fixtures/spec046-README.md):
 * the signer is valid from 2026-10-09T14:26:58Z to 2036-10-06T14:26:58Z and
 * the file carries no time-stamp, so the given time is the time it is judged at.
 */

function spec047Fixture(string $name): string
{
    $bytes = file_get_contents(dirname(__DIR__, 2).'/Fixtures/spec046-'.$name);

    if (! is_string($bytes) || $bytes === '') {
        throw new RuntimeException("missing fixture spec046-{$name}");
    }

    return $bytes;
}

function spec047Asset(): Asset
{
    return new Asset(spec047Fixture('cs-parent.png'), MediaType::Png);
}

/** As SPEC-046 AC7's test builds it: the root as the signer list, the shipped anchors as the TSA list. */
function spec047Reader(?Verifier $verifier = null): C2paVerifierReader
{
    $tsas = (string) file_get_contents(dirname(__DIR__, 3).'/certs/trust_anchors.pem');

    return new C2paVerifierReader(TrustSettings::fromTrustLists(spec047Fixture('throw-away-root.pem'), $tsas), $verifier);
}

/** @return array<mixed> */
function spec047CrJson(VerifiedRead $read): array
{
    $doc = json_decode($read->verification->toCrJson(), true, 512, JSON_THROW_ON_ERROR);

    return is_array($doc) ? $doc : [];
}

function spec047CrJsonTime(VerifiedRead $read): mixed
{
    $manifests = spec047CrJson($read)['manifests'] ?? null;
    $first = is_array($manifests) ? ($manifests[0] ?? null) : null;
    $results = is_array($first) ? ($first['validationResults'] ?? null) : null;

    return is_array($results) ? ($results['validationTime'] ?? null) : null;
}

/** A source that records every request and answers none. */
final class Spec047Requests
{
    public int $count = 0;
}

function spec047Source(Spec047Requests $requests): RevocationSource
{
    return new class($requests) implements RevocationSource
    {
        public function __construct(private Spec047Requests $requests) {}

        public function ocspResponse(string $subject, string $issuer, array $urls): ?string
        {
            $this->requests->count++;

            return null;
        }
    };
}

// AC1
it('AC1: a time inside the validity judges the signer trusted', function () {
    $read = spec047Reader()->readVerified(spec047Asset(), new DateTimeImmutable('2030-01-01T00:00:00Z'));

    expect($read->report->isTrusted())->toBeTrue()
        ->and($read->verification->result->state->value)->toBe('Trusted')
        ->and($read->verification->validationTime?->format('Y-m-d\TH:i:s\Z'))->toBe('2030-01-01T00:00:00Z');
})->group('SPEC-047');

// AC2
it('AC2: a time after the validity judges the signer expired, where the clock does not', function () {
    $reader = spec047Reader();
    $later = $reader->readVerified(spec047Asset(), new DateTimeImmutable('2037-01-01T00:00:00Z'));
    $now = $reader->readVerified(spec047Asset());

    expect($later->report->isTrusted())->toBeFalse()
        ->and($later->report->validationStatusCodes())->toContain('signingCredential.expired')
        ->and($later->verification->result->state->value)->toBe('Invalid')
        ->and($now->report->isTrusted())->toBeTrue();
})->group('SPEC-047');

// AC3
it('AC3: the time reaches the crJSON, written in UTC', function (string $given) {
    $read = spec047Reader()->readVerified(spec047Asset(), new DateTimeImmutable($given));

    expect(spec047CrJsonTime($read))->toBe('2030-01-01T00:00:00Z');
})->with([
    'in UTC' => ['2030-01-01T00:00:00Z'],
    'with an offset' => ['2030-01-01T05:00:00+05:00'],
])->group('SPEC-047');

// AC4
it('AC4: without a time, the read is judged at the clock', function () {
    $before = time();
    $read = spec047Reader()->readVerified(spec047Asset());
    $after = time();
    $at = $read->verification->validationTime?->getTimestamp();

    expect($at)->toBeGreaterThanOrEqual($before)
        ->and($at)->toBeLessThanOrEqual($after)
        ->and($read->report->isTrusted())->toBeTrue();
})->group('SPEC-047');

// AC5
it('AC5: a time RFC 3339 cannot write is refused before anything is read', function (string $time, string $shown) {
    $requests = new Spec047Requests;
    $reader = spec047Reader(new Verifier(revocationSource: spec047Source($requests)));
    // SPEC-046 AC5's truncated asset: read through, it fails as ReadFailedException, so the time is refused before
    // the read's result is taken. That the verifier is not called at all is not observable here: Verifier is final,
    // and its revocation source is not asked about a signer that is not trusted; the count below is the weaker check
    $truncated = new Asset(substr(spec047Fixture('cs-parent.png'), 0, 20000), MediaType::Png);

    foreach ([spec047Asset(), $truncated] as $asset) {
        try {
            $reader->readVerified($asset, new DateTimeImmutable($time));
            throw new RuntimeException('no exception for '.$time);
        } catch (InvalidValidationTimeException $e) {
            expect($e)->toBeInstanceOf(InvalidArgumentException::class)
                ->and($e)->toBeInstanceOf(ContentCredentialsException::class)
                ->and($e->getMessage())->toContain($shown)
                ->and($e->getMessage())->toContain('0001')
                ->and($e->getMessage())->toContain('9999');
        }
    }
    expect($requests->count)->toBe(0);
})->with([
    'year 10000' => ['@253402300800', '10000-01-01T00:00:00Z'],
    'year 0' => ['@-62135596801', '0000-12-31T23:59:59Z'],
])->group('SPEC-047');

it('AC5: the edges of the range are read', function (string $time) {
    $read = spec047Reader()->readVerified(spec047Asset(), new DateTimeImmutable($time));

    expect(spec047CrJsonTime($read))->toBe($time);
})->with([
    'the first second of year 1' => ['0001-01-01T00:00:00Z'],
    'the last second of year 9999' => ['9999-12-31T23:59:59Z'],
])->group('SPEC-047');
