<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Provemark\ContentCredentials\Core\Manifest\Manifest;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\Exception\TrustAnchorsNotAppliedException;
use Provemark\ContentCredentials\Core\Reading\SigningServiceReader;
use Provemark\ContentCredentials\Core\Reading\TrustAnchorsGuard;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\Exception\AssetTooLargeException;
use Provemark\ContentCredentials\Core\Signing\Exception\MediaTypeMismatchException;
use Provemark\ContentCredentials\Core\Signing\Exception\MissingParentAssetException;
use Provemark\ContentCredentials\Core\Signing\Exception\SigningTransportException;
use Provemark\ContentCredentials\Core\Signing\Exception\UnexpectedParentAssetException;
use Provemark\ContentCredentials\Core\Signing\SignedAsset;
use Provemark\ContentCredentials\Core\Signing\SignerInterface;
use Provemark\ContentCredentials\Laravel\Console\ReadCommand;
use Provemark\ContentCredentials\Laravel\Console\SignCommand;
use Provemark\ContentCredentials\Laravel\ContentCredentialsServiceProvider;
use Provemark\ContentCredentials\Laravel\Jobs\SignAssetJob;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * SPEC-032 — four places the client layer says something it does not do.
 *
 * @see specs/SPEC-032-client-layer-corrections.md
 */

/** A container wired with this package's provider. */
function cc32App(?ClientInterface $bound = null): Container
{
    $app = new Container;
    Container::setInstance($app);
    $app->instance('config', new Repository(['content-credentials' => [
        'service' => ['base_url' => 'http://localhost:3000', 'api_key' => 'test-key'],
    ]]));

    if ($bound !== null) {
        $app->instance(ClientInterface::class, $bound);
    }

    (new ContentCredentialsServiceProvider($app))->register();

    return $app;
}

/** The HTTP client an object was constructed with. */
function cc32ClientOf(object $subject): ClientInterface
{
    $property = new ReflectionProperty($subject, 'httpClient');
    $client = $property->getValue($subject);

    if (! $client instanceof ClientInterface) {
        throw new RuntimeException('no http client on '.$subject::class);
    }

    return $client;
}

/** A PSR-18 client that is never actually called. */
final class Cc32Client implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new RuntimeException('not expected to send anything');
    }
}

/** A signer that always raises the exception it was given. */
final class Cc32ThrowingSigner implements SignerInterface
{
    public function __construct(private readonly Throwable $raises) {}

    public function sign(Asset $asset, Manifest $manifest, ?Asset $parent = null): SignedAsset
    {
        throw $this->raises;
    }
}

/** A queue job double that records whether it was failed or released. */
final class Cc32QueueJob implements QueueJob
{
    public ?Throwable $failedWith = null;

    public bool $wasFailed = false;

    /**
     * Returned by the nullable ?int/?string members below.
     *
     * A literal would let PHPStan narrow each declared union to one arm and then
     * report the other as removable — a true observation about a double that
     * exists to satisfy an interface, and a pointless edit. The property keeps
     * the declared types honest.
     */
    public ?int $unset = null;

    public function fail($e = null): void
    {
        $this->wasFailed = true;
        $this->failedWith = $e instanceof Throwable ? $e : null;
    }

    public function uuid(): ?string
    {
        return $this->unset === null ? null : (string) $this->unset;
    }

    public function getJobId(): ?string
    {
        return $this->unset === null ? null : (string) $this->unset;
    }

    /** @return array<array-key, mixed> */
    public function payload(): array
    {
        return [];
    }

    public function attempts(): int
    {
        return 1;
    }

    public function body(): string
    {
        return '';
    }

    public function fire(): void {}

    public function markAsFailed(): void {}

    public function delete(): void {}

    public function isDeleted(): bool
    {
        return false;
    }

    public function release($delay = 0): void {}

    public function isReleased(): bool
    {
        return false;
    }

    public function isDeletedOrReleased(): bool
    {
        return false;
    }

    public function hasFailed(): bool
    {
        return $this->wasFailed;
    }

    public function getName(): string
    {
        return 'cc32';
    }

    public function resolveName(): string
    {
        return 'cc32';
    }

    public function getConnectionName(): string
    {
        return 'sync';
    }

    public function getQueue(): string
    {
        return 'default';
    }

    public function getRawBody(): string
    {
        return '';
    }

    public function maxTries(): ?int
    {
        return $this->unset;
    }

    public function maxExceptions(): ?int
    {
        return null;
    }

    public function backoff(): ?int
    {
        return $this->unset;
    }

    public function retryUntil(): ?int
    {
        return $this->unset;
    }

    public function timeout(): ?int
    {
        return $this->unset;
    }

    public function shouldFailOnTimeout(): bool
    {
        return false;
    }
}

/** A job wired to $signer, holding the queue-job double so `fail()` is observable. */
function cc32Job(Throwable $raises, Cc32QueueJob $queueJob, string $destination): SignAssetJob
{
    $job = new SignAssetJob(
        __DIR__.'/../../Fixtures/fixture.png',
        $destination,
        MediaType::Png,
        'SPEC-032',
    );
    $job->setJob($queueJob);

    $job->handle(new Cc32ThrowingSigner($raises));

    return $job;
}

// --- AC1: the CLI help names every extension it accepts ----------------------

it('names every supported extension in the command help', function () {
    $extensions = new ReflectionClassConstant(SignCommand::class, 'EXTENSIONS');
    /** @var array<string, MediaType> $known */
    $known = $extensions->getValue();

    $sign = new SignCommand;
    $read = new ReadCommand;

    $text = $sign->getDescription().' '.$read->getDescription();
    foreach ([$sign, $read] as $command) {
        foreach ($command->getDefinition()->getArguments() as $argument) {
            $text .= ' '.$argument->getDescription();
        }
    }

    // Derived from the constant, not from a second hand-written list here —
    // otherwise this test goes stale in exactly the way the help text did.
    foreach (array_keys($known) as $extension) {
        expect($text)->toContain(".{$extension}");
    }
})->group('SPEC-032');

it('does not call its input an image', function () {
    $text = strtolower(
        (new SignCommand)->getDescription().' '.(new ReadCommand)->getDescription()
    );

    // Thirteen media types, three of them video and three audio. "Image" has
    // been wrong since SPEC-021.
    expect(str_contains($text, 'image'))->toBeFalse('the command still describes its input as an image');
})->group('SPEC-032');

// --- AC2: one HTTP client, shared --------------------------------------------

it('gives the signer and the reader the same http client', function () {
    $app = cc32App();

    $signer = $app->make(SignerInterface::class);
    $reader = $app->make(SigningServiceReader::class);

    expect(cc32ClientOf($signer))->toBe(cc32ClientOf($reader));
})->group('SPEC-032');

it('uses an application-bound client for both, unchanged', function () {
    $bound = new Cc32Client;
    $app = cc32App($bound);

    expect(cc32ClientOf($app->make(SignerInterface::class)))->toBe($bound)
        ->and(cc32ClientOf($app->make(SigningServiceReader::class)))->toBe($bound);
})->group('SPEC-032');

// --- AC3 / AC4: the trust-anchor post-condition ------------------------------

it('accepts anchors the extension reports as applied', function () {
    // The positive direction. Nothing is thrown, which is the whole contract.
    TrustAnchorsGuard::ensureApplied(true);
})->group('SPEC-032')->throwsNoExceptions();

it('fails closed when the extension does not report the anchors as applied', function () {
    // The negative direction cannot be produced with a real Settings object:
    // measured 2026-08-08, ext-c2pa v0.1.0 reports hasTrustAnchors() true even
    // for garbage PEM. So the guard is a seam, and this is the case it exists
    // for — a rename or an immutable-fluent redesign upstream, after which the
    // reader would report isTrusted() false for everything while the operator
    // believes trust is on.
    TrustAnchorsGuard::ensureApplied(false);
})->group('SPEC-032')->throws(TrustAnchorsNotAppliedException::class);

it('says what went wrong when anchors are not applied', function () {
    try {
        TrustAnchorsGuard::ensureApplied(false);
    } catch (TrustAnchorsNotAppliedException $e) {
        expect(strtolower($e->getMessage()))->toContain('trust anchors')
            ->and(strtolower($e->getMessage()))->toContain('not applied');
    }
})->group('SPEC-032');

// --- AC5: a deterministic failure is not retried -----------------------------

it('fails a deterministic error without leaving it to be retried', function (Throwable $raises) {
    $queueJob = new Cc32QueueJob;
    $destination = sys_get_temp_dir().'/cc32-'.bin2hex(random_bytes(4)).'.png';

    cc32Job($raises, $queueJob, $destination);

    expect($queueJob->wasFailed)->toBeTrue('the job left a deterministic failure to be retried')
        ->and($queueJob->failedWith)->toBe($raises)
        ->and(is_file($destination))->toBeFalse('a failed sign wrote a destination file');
})->with([
    'too large' => fn () => new AssetTooLargeException('too large'),
    'media type mismatch' => fn () => new MediaTypeMismatchException('mismatch'),
    'missing parent' => fn () => new MissingParentAssetException('missing parent'),
    'unexpected parent' => fn () => new UnexpectedParentAssetException('unexpected parent'),
])->group('SPEC-032');

// --- AC6: a transient failure is still retried -------------------------------

it('lets a transient error propagate so the queue retries it', function () {
    // The control case. Without it AC5 passes just as happily against a job that
    // never retries anything (NOTES Step 26).
    $queueJob = new Cc32QueueJob;

    expect(fn () => cc32Job(
        new SigningTransportException('connection refused'),
        $queueJob,
        sys_get_temp_dir().'/cc32-transient.png',
    ))->toThrow(SigningTransportException::class);

    expect($queueJob->wasFailed)->toBeFalse('a transient failure was marked failed instead of retried');
})->group('SPEC-032');
