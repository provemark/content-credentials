<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadFailedException;
use Provemark\ContentCredentials\Core\Reading\SigningServiceReader;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningFailedException;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Signing\SigningServiceSigner;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * SPEC-031 — one bounded error path for both clients.
 *
 * `extractError()` exists twice, identically, and only the signer's copy caps
 * what it carries into an exception. SPEC-025's Scope authorised "capping the
 * service error text copied into an exception"; its AC4 narrowed that to
 * `SigningFailedException`, and the implementation followed the criterion.
 *
 * @see specs/SPEC-031-one-bounded-error-path.md
 */

/** An HTTP client that always answers with one prepared response. */
final class Cc31Client implements ClientInterface
{
    public function __construct(private readonly ResponseInterface $returns) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->returns;
    }
}

/** A non-2xx response carrying $body verbatim. */
function cc31Response(string $body): ResponseInterface
{
    return new Response(400, [], $body);
}

/** A non-2xx response whose JSON `error` is $error. */
function cc31ErrorResponse(string $error): ResponseInterface
{
    return cc31Response((string) json_encode(['error' => $error], JSON_THROW_ON_ERROR));
}

/** The message of the exception the SIGNER raises for this response. */
function cc31SignerMessage(ResponseInterface $response): string
{
    $factory = new Psr17Factory;
    $signer = new SigningServiceSigner(
        new Cc31Client($response),
        $factory,
        $factory,
        new SigningServiceConfig('http://localhost:3000', 'test-key'),
    );

    $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('SPEC-031')
        ->build();

    try {
        $signer->sign(new Asset('x', MediaType::Png), $manifest);
    } catch (SigningFailedException $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('expected SigningFailedException was not thrown');
}

/** The message of the exception the READER raises for this response. */
function cc31ReaderMessage(ResponseInterface $response): string
{
    $factory = new Psr17Factory;
    $reader = new SigningServiceReader(
        new Cc31Client($response),
        $factory,
        $factory,
        new SigningServiceConfig('http://localhost:3000', 'test-key'),
    );

    try {
        $reader->read(new Asset('x', MediaType::Png));
    } catch (ReadFailedException $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('expected ReadFailedException was not thrown');
}

/**
 * Whether $value is valid UTF-8, without ext-mbstring.
 *
 * `mb_check_encoding()` would read better and this package deliberately does not
 * require the extension (SPEC-031, Open questions), so a test may not assume it
 * either. A `/u` pattern fails to match invalid UTF-8, which is the same answer.
 */
function cc31IsValidUtf8(string $value): bool
{
    return preg_match('//u', $value) === 1;
}

// --- AC1: a read error is capped ---------------------------------------------

it('caps the service error text a read copies into an exception', function () {
    $message = cc31ReaderMessage(cc31ErrorResponse(str_repeat('A', 50_000)));

    expect(strlen($message))->toBeLessThan(1024)
        ->and($message)->toContain('400');
})->group('SPEC-031');

// --- AC2: the signing side is unchanged --------------------------------------

it('still caps the service error text a sign copies into an exception', function () {
    $message = cc31SignerMessage(cc31ErrorResponse(str_repeat('A', 50_000)));

    expect(strlen($message))->toBeLessThan(1024)
        ->and($message)->toContain('400');
})->group('SPEC-031');

// --- AC3: a short error survives whole ---------------------------------------

it('reports a short service error in full, from either client', function (string $client) {
    $error = 'unsupported mime_type "image/bmp"';
    $message = $client === 'signer'
        ? cc31SignerMessage(cc31ErrorResponse($error))
        : cc31ReaderMessage(cc31ErrorResponse($error));

    expect($message)->toContain($error)
        ->and($message)->not->toContain('truncated');
})->with(['signer', 'reader'])->group('SPEC-031');

// --- AC4: a truncated message is valid UTF-8 ---------------------------------

it('truncates on a character boundary, from either client', function (string $client) {
    // A three-byte character, so a byte-wise cut at 256 lands inside one: 256/3
    // is not a whole number. Two-byte characters would divide evenly at 256 and
    // the test would pass against substr() — which is the defect.
    $error = str_repeat('⚡', 300);

    $message = $client === 'signer'
        ? cc31SignerMessage(cc31ErrorResponse($error))
        : cc31ReaderMessage(cc31ErrorResponse($error));

    expect(cc31IsValidUtf8($message))->toBeTrue('the truncated message is not valid UTF-8');
})->with(['signer', 'reader'])->group('SPEC-031');

// --- AC5: the fallback is unchanged ------------------------------------------

it('falls back to a generic message for a body it cannot read', function (string $client, string $body) {
    $message = $client === 'signer'
        ? cc31SignerMessage(cc31Response($body))
        : cc31ReaderMessage(cc31Response($body));

    expect($message)->toContain('unknown error');
})->with(['signer', 'reader'])
    ->with([
        'not JSON' => '<html>502 Bad Gateway</html>',
        'JSON without error' => '{"detail":"nope"}',
        'JSON error not a string' => '{"error":{"code":7}}',
    ])->group('SPEC-031');

// --- AC6: the two clients cannot drift again ---------------------------------

it('gives both clients the same answer for the same body', function (string $body) {
    // Behaviour rather than structure: this would also pass against two separate
    // implementations that happen to agree — and that is the point, because
    // agreeing is the property. It fails the moment one of them is changed alone,
    // which is exactly how this defect was introduced.
    $signer = cc31SignerMessage(cc31Response($body));
    $reader = cc31ReaderMessage(cc31Response($body));

    $strip = static fn (string $m): string => (string) preg_replace(
        '/^(Signing|Read) service returned HTTP \d+: /', '', $m,
    );

    expect($strip($signer))->toBe($strip($reader));
})->with([
    'oversized error' => '{"error":"'.str_repeat('A', 50_000).'"}',
    'multi-byte error' => '{"error":"'.str_repeat('⚡', 300).'"}',
    'short error' => '{"error":"nope"}',
    'not JSON' => '<html>502</html>',
])->group('SPEC-031');
