<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\C2paVerifierReader;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadFailedException;
use Provemark\ContentCredentials\Core\Reading\Exception\TrustSettingsRejectedException;
use Provemark\ContentCredentials\Core\Reading\Exception\VerifierMissingException;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Support\ContentCredentialsException;

/**
 * SPEC-042 AC2–AC7 — the reader over provemark/c2pa-verifier, with no service.
 *
 * Everything here runs in every `composer check` leg: the verifier is pure PHP
 * and arrives through require-dev. The signed fixture was produced once by the
 * service (c2pa-rs 0.90.22, TSA on) and verifies Trusted under
 * certs/c2pa-trust.settings.json with `bin/verify.sh`.
 *
 * @see specs/SPEC-042-pure-php-reader-via-c2pa-verifier.md
 */
function spec042Fixture(string $name): string
{
    $bytes = file_get_contents(dirname(__DIR__, 2).'/Fixtures/'.$name);

    if (! is_string($bytes) || $bytes === '') {
        throw new RuntimeException("missing fixture {$name}");
    }

    return $bytes;
}

function spec042Signed(): string
{
    return spec042Fixture('signed-spec042.png');
}

function spec042TrustSettings(): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/certs/c2pa-trust.settings.json');
}

function spec042Png(string $bytes): Asset
{
    return new Asset($bytes, MediaType::Png);
}

/** The message of whatever $read throws, or '' when it does not throw. */
function spec042Message(callable $read): string
{
    try {
        $read();
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return '';
}

// --- The happy path the other criteria are measured against ------------------

it('reads the signed fixture as trusted, AI-generated and timestamped', function () {
    // Pinned values rather than agreement: AC1's comparison lives in the
    // integration suite, and a reader that returned nothing would agree with
    // nothing. These are what the service reader and ExtC2paReader answer for
    // this file under the same settings.
    $report = (new C2paVerifierReader(spec042TrustSettings()))->read(spec042Png(spec042Signed()));

    expect($report->hasManifest())->toBeTrue()
        ->and($report->validationState()?->value)->toBe('Trusted')
        ->and($report->isTrusted())->toBeTrue()
        ->and($report->isVerifiedAiGenerated())->toBeTrue()
        ->and($report->hasTimestamp())->toBeTrue()
        ->and($report->declaredSpecVersion())->toBe('2.4.0')
        ->and($report->digitalSourceTypes())->toBe([
            'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
        ])
        ->and(array_map(fn ($agent) => $agent->name, $report->softwareAgents()))
        ->toBe(['SPEC-042 fixture']);
})->group('SPEC-042');

// --- AC2: no manifest is an empty report, not an error -----------------------

it('returns an empty report for an asset with no C2PA data', function () {
    $report = (new C2paVerifierReader)->read(spec042Png(spec042Fixture('fixture.png')));

    expect($report->hasManifest())->toBeFalse()
        ->and($report->isSignatureValid())->toBeFalse()
        ->and($report->isAiGenerated())->toBeFalse();
})->group('SPEC-042');

it('reports no validation state for an unsigned asset, as the other readers do', function () {
    // Measured 2026-10-07: the verifier says `validation_state: "Invalid"` with
    // no status codes here, and the parser passes that on. Both other readers
    // answer null. This is the assertion that fails if the report is handed
    // to ManifestStoreParser unmapped.
    $report = (new C2paVerifierReader(spec042TrustSettings()))->read(spec042Png(spec042Fixture('fixture.png')));

    expect($report->validationState())->toBeNull()
        ->and($report->validationStatusCodes())->toBe([]);
})->group('SPEC-042');

it('returns exactly the report ExtC2paReader returns for no manifest', function () {
    // The shape ExtC2paReader builds by hand for SPEC-003 D2.
    $report = (new C2paVerifierReader)->read(spec042Png(spec042Fixture('fixture.png')));

    expect($report)->toEqual(new ManifestReport(null, null, [], [], null));
})->group('SPEC-042');

// --- AC3: a type the verifier cannot read is refused before it is read -------

it('refuses a media type the verifier cannot read', function (MediaType $type) {
    // A SIGNED asset, so the failure being guarded is visible: read unrefused,
    // the verifier calls it "no manifest" and hasManifest() says false for a
    // file that carries Content Credentials.
    $bytes = spec042Fixture('signed-spec042.'.($type === MediaType::Tiff ? 'tiff' : 'svg'));

    $message = spec042Message(fn () => (new C2paVerifierReader)->read(new Asset($bytes, $type)));

    expect($message)->toContain($type->value)
        ->and($message)->toContain('not supported by this reader');
    expect(fn () => (new C2paVerifierReader)->read(new Asset($bytes, $type)))
        ->toThrow(ReadFailedException::class);
})->with([
    'TIFF' => [MediaType::Tiff],
    'SVG' => [MediaType::Svg],
])->group('SPEC-042');

it('accepts every other media type', function (MediaType $type) {
    // AC3's second half: the refused set is a measurement, so a MediaType case
    // added later must not be refused (or accepted) by accident. Garbage bytes
    // make the VERIFIER fail, and that is asserted positively — its own
    // explanation must arrive. A first version asserted only that the up-front
    // refusal was absent, and passed before the class existed.
    $message = spec042Message(fn () => (new C2paVerifierReader)->read(new Asset('not a media file', $type)));

    expect($message)->toStartWith('Could not read the asset: ')
        ->and($message)->toContain('unsupported file type');
})->with(fn () => array_map(
    fn (MediaType $type) => [$type],
    array_values(array_filter(
        MediaType::cases(),
        fn (MediaType $type) => ! in_array($type, [MediaType::Tiff, MediaType::Svg], true),
    )),
))->group('SPEC-042');

it('knows of thirteen media types, eleven of them readable here', function () {
    // The tripwire for a fourteenth: `accepts every other media type` would
    // silently include it. A new case needs a measurement against the verifier
    // first, and this count is what makes someone go and do it.
    expect(MediaType::cases())->toHaveCount(13);
})->group('SPEC-042');

// --- AC4: a fault in the file is an exception, as in the other readers -------

it('throws ReadFailedException on input the verifier cannot parse', function (string $bytes) {
    // Measured: the verifier returns an Invalid report with `general.error`
    // for each of these, and ExtC2paReader and SigningServiceReader both throw.
    expect(fn () => (new C2paVerifierReader)->read(spec042Png($bytes)))
        ->toThrow(ReadFailedException::class);
})->with([
    'empty' => [''],
    'random bytes' => [str_repeat("\x71\x7B\xE1\x1E", 1024)],
    'PNG signature then garbage' => ["\x89PNG\r\n\x1a\n".str_repeat("\xA5", 2000)],
    'signed PNG cut in half' => [fn () => substr(spec042Signed(), 0, intdiv(strlen(spec042Signed()), 2))],
])->group('SPEC-042');

it('throws ReadFailedException for a store the verifier reached but could not decode', function () {
    // Amendment 1. A byte flipped INSIDE the PNG manifest chunk (caBX starts
    // at byte 37 and runs past byte 53,000): the verifier reports
    // has_manifest true and a chunk-CRC general.error, and decodes no
    // manifest. Mapped as it first was, that read hasManifest() === false
    // with Invalid — "no credentials" to a caller asking only that.
    $bytes = spec042Signed();
    $bytes[1000] = chr(ord($bytes[1000]) ^ 0xFF);

    $message = spec042Message(fn () => (new C2paVerifierReader(spec042TrustSettings()))->read(spec042Png($bytes)));

    expect($message)->toStartWith('Could not read the asset: ')
        ->and($message)->toContain('CRC');
})->group('SPEC-042');

it('refuses a file whose only manifest is remote, naming the URL', function () {
    // Amendment 2. The fixture is the unsigned JPEG with one XMP segment
    // declaring `dcterms:provenance` — synthetic, so no third-party file is
    // committed. Measured on it and on two real files (c2pa-rs cloud.jpg, an
    // Adobe Photoshop export): the verifier says has_manifest false with the
    // URL in remote_manifest, and ExtC2paReader throws naming the URL. Mapped
    // as an empty report, that read "no Content Credentials".
    $asset = new Asset(spec042Fixture('remote-manifest-spec042.jpg'), MediaType::Jpeg);

    $message = spec042Message(fn () => (new C2paVerifierReader)->read($asset));

    expect($message)->toStartWith('Could not read the asset: ')
        ->and($message)->toContain('https://manifests.example.invalid/spec042-remote.c2pa')
        ->and($message)->toContain('never fetches');
})->group('SPEC-042');

it('carries the verifier explanation in the message', function () {
    // The operator must be able to see WHY, as with the extension's message.
    $message = spec042Message(fn () => (new C2paVerifierReader)->read(spec042Png('not a media file')));

    expect($message)->toStartWith('Could not read the asset: ')
        ->and($message)->toContain('unsupported file type');
})->group('SPEC-042');

it('reports a broken hard binding as an Invalid report, not an exception', function () {
    // A failed validation is an answer. Byte flipped near the END of the file,
    // inside the image data and outside the manifest chunk: measured to give
    // `assertion.dataHash.mismatch`.
    $bytes = spec042Signed();
    $at = strlen($bytes) - 200;
    $bytes[$at] = chr(ord($bytes[$at]) ^ 0xFF);

    $report = (new C2paVerifierReader(spec042TrustSettings()))->read(spec042Png($bytes));

    expect($report->hasManifest())->toBeTrue()
        ->and($report->validationState()?->value)->toBe('Invalid')
        ->and($report->validationStatusCodes())->toContain('assertion.dataHash.mismatch')
        ->and($report->isVerifiedAiGenerated())->toBeFalse();
})->group('SPEC-042');

// --- AC10: a copy of the asset does not double its memory (Amendment 3) ----

it('adds little to peak memory when reading a large asset', function () {
    // Measured 2026-10-08 on 32 MiB: 33.4 MB over baseline with php://memory,
    // 1.4 MB with php://temp. Garbage bytes make the verifier stop at the
    // signature, so what is measured is this reader's own copy.
    $asset = spec042Png(str_repeat("\xA5", 32 * 1024 * 1024));
    $reader = new C2paVerifierReader;

    gc_collect_cycles();
    memory_reset_peak_usage();
    $baseline = memory_get_usage();

    try {
        $reader->read($asset);
    } catch (ReadFailedException) {
        // expected: not a media file
    }

    expect(memory_get_peak_usage() - $baseline)->toBeLessThan(8 * 1024 * 1024);
})->group('SPEC-042');

it('fails with ReadFailedException when the copy cannot spill to disk', function () {
    // Measured: without a writable temp directory, fwrite() to php://temp
    // returns 0 past the in-memory limit and only warns. Unchecked, the
    // verifier would read an empty stream. A child process, because
    // sys_temp_dir cannot be changed at runtime.
    $root = dirname(__DIR__, 3);
    $script = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        $asset = new Provemark\ContentCredentials\Core\Signing\Asset(
            str_repeat("\xA5", 3 * 1024 * 1024),
            Provemark\ContentCredentials\Core\Manifest\MediaType::Png,
        );
        try {
            (new Provemark\ContentCredentials\Core\Reading\C2paVerifierReader)->read($asset);
            echo "no exception\n";
        } catch (Throwable $e) {
            echo get_class($e), "\n", $e->getMessage(), "\n";
        }
        PHP;

    $output = (string) shell_exec(sprintf(
        '%s -d sys_temp_dir=/nonexistent/spec042 -d display_errors=0 -r %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($script),
        escapeshellarg($root),
    ));

    expect($output)->toContain(ReadFailedException::class)
        ->and($output)->toContain('could not be copied')
        ->and($output)->not->toContain('no exception');
})->group('SPEC-042');

// --- AC6: trust settings fail at construction, not at the first read --------

it('refuses trust settings the verifier refuses, at construction', function (string $json, string $needle) {
    $thrown = null;

    try {
        new C2paVerifierReader($json);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(TrustSettingsRejectedException::class)
        ->and($thrown)->toBeInstanceOf(ContentCredentialsException::class)
        // The verifier's own explanation is the useful part: it names where a
        // top-level allowed_list belongs.
        ->and($thrown?->getMessage())->toContain($needle);
})->with([
    'not JSON' => ['{', 'not valid JSON'],
    'a path where PEM contents belong' => [
        (string) json_encode(['trust' => ['trust_anchors' => '/etc/c2pa/anchors.pem']]),
        'no PEM block',
    ],
    'a top-level allowed_list' => [
        (string) json_encode(['verify' => ['verify_trust' => true], 'trust' => ['allowed_list' => "-----BEGIN CERTIFICATE-----\nMA==\n-----END CERTIFICATE-----\n"]]),
        'trust.anchors[].allowed_list',
    ],
])->group('SPEC-042');

it('verifies without trust when no settings are given', function () {
    $report = (new C2paVerifierReader)->read(spec042Png(spec042Signed()));

    expect($report->validationState()?->value)->toBe('Valid')
        ->and($report->isSignatureValid())->toBeTrue()
        ->and($report->isTrusted())->toBeFalse()
        ->and($report->validationStatusCodes())->toContain('signingCredential.untrusted');
})->group('SPEC-042');

it('treats empty settings as no settings', function () {
    // What an unset env var or an empty config value produces. Not an error,
    // and not "trusted": the same answer as null.
    $report = (new C2paVerifierReader('  '))->read(spec042Png(spec042Signed()));

    expect($report->validationState()?->value)->toBe('Valid');
})->group('SPEC-042');

// --- AC7: the package is optional, and its absence is named ------------------

it('reports itself available when the verifier is installed', function () {
    expect(C2paVerifierReader::isAvailable())->toBeTrue();
})->group('SPEC-042');

it('names the package to install when the verifier is absent', function () {
    // The verifier is in require-dev, so its absence has to be produced: a
    // child PHP process loads this package's autoloader with the verifier's
    // PSR-4 prefix emptied, which is what a host without the package has.
    $root = dirname(__DIR__, 3);
    $script = <<<'PHP'
        $loader = require $argv[1].'/vendor/autoload.php';
        $loader->setPsr4('Provemark\\C2paVerifier\\', []);
        echo json_encode(['available' => Provemark\ContentCredentials\Core\Reading\C2paVerifierReader::isAvailable()]), "\n";
        try {
            new Provemark\ContentCredentials\Core\Reading\C2paVerifierReader;
            echo "constructed\n";
        } catch (Throwable $e) {
            echo get_class($e), "\n", $e->getMessage(), "\n";
        }
        PHP;

    $output = (string) shell_exec(sprintf(
        '%s -r %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($script),
        escapeshellarg($root),
    ));

    expect($output)->toContain('{"available":false}')
        ->and($output)->toContain(VerifierMissingException::class)
        ->and($output)->toContain('composer require provemark/c2pa-verifier')
        ->and($output)->not->toContain('constructed');
})->group('SPEC-042');

// --- SPEC-044: the reader says which media types it reads ---------------------

it('says it does not read TIFF or SVG', function (MediaType $type) {
    expect(C2paVerifierReader::supports($type))->toBeFalse();
})->with([
    'TIFF' => [MediaType::Tiff],
    'SVG' => [MediaType::Svg],
])->group('SPEC-044');

it('says it reads every other media type', function (MediaType $type) {
    // Listed by exclusion over MediaType::cases(), so SPEC-042's
    // thirteen-cases tripwire still forces a measurement for a fourteenth.
    expect(C2paVerifierReader::supports($type))->toBeTrue();
})->with(fn () => array_map(
    fn (MediaType $type) => [$type],
    array_values(array_filter(
        MediaType::cases(),
        fn (MediaType $type) => ! in_array($type, [MediaType::Tiff, MediaType::Svg], true),
    )),
))->group('SPEC-044');

it('answers supports() without the verifier installed', function () {
    // The same child process as SPEC-042 AC7: the verifier's PSR-4 prefix
    // emptied, which is what a host without the package has. A caller asks
    // this while deciding whether the route is usable at all.
    $root = dirname(__DIR__, 3);
    $script = <<<'PHP'
        $loader = require $argv[1].'/vendor/autoload.php';
        $loader->setPsr4('Provemark\\C2paVerifier\\', []);
        $reader = Provemark\ContentCredentials\Core\Reading\C2paVerifierReader::class;
        $type = Provemark\ContentCredentials\Core\Manifest\MediaType::class;
        try {
            echo json_encode([
                'available' => $reader::isAvailable(),
                'png' => $reader::supports($type::Png),
                'tiff' => $reader::supports($type::Tiff),
            ]), "\n";
        } catch (Throwable $e) {
            echo get_class($e), "\n", $e->getMessage(), "\n";
        }
        PHP;

    $output = (string) shell_exec(sprintf(
        '%s -r %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($script),
        escapeshellarg($root),
    ));

    expect(trim($output))->toBe('{"available":false,"png":true,"tiff":false}');
})->group('SPEC-044');

it('refuses up front exactly the types supports() says no to', function (MediaType $type) {
    // AC3: read() and supports() cannot disagree, in either direction. Garbage
    // bytes, so a supported type reaches the verifier and fails there with
    // its own explanation; an unsupported one never gets that far.
    $message = spec042Message(fn () => (new C2paVerifierReader)->read(new Asset('not a media file', $type)));

    if (C2paVerifierReader::supports($type)) {
        expect($message)->toStartWith('Could not read the asset: ');
    } else {
        expect($message)->toBe(sprintf(
            'Media type %s is not supported by this reader: provemark/c2pa-verifier cannot read it. '
            .'Read it with the service or extension reader.',
            $type->value,
        ));
    }
})->with(fn () => array_map(fn (MediaType $type) => [$type], MediaType::cases()))
    ->group('SPEC-044');
