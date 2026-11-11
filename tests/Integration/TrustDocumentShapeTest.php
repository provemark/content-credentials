<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\ManifestReport;
use Provemark\ContentCredentials\Core\Reading\ManifestStoreParser;
use Provemark\ContentCredentials\Core\Reading\ValidationState;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-014 Amendment 1 — the trust document reaches the engine through a
 * `Context`, and the shapes that would verify nothing on c2pa-rs 0.91 are
 * refused at startup.
 *
 * Each case starts a SECOND service process inside the running container with
 * its own trust document (`spec014-probe.js`), so documents that cannot coexist
 * in one process are all exercised in one run, in every integration profile,
 * without a profile of their own. Verdict cases sign through the live service
 * and read through the second process; the signing certificate is the same.
 *
 * Why these matter (NOTES Step 67 addenda): on the `Context` path a snake_case
 * key that is not mapped is dropped without a word while `verify_trust`
 * survives, so the service would believe trust is on and verify nothing. The
 * verdict tests below are what notice that (AC11): they read `Trusted` only if
 * the anchors actually reached the engine.
 *
 * Every refusal test asserts something PRESENT — the key or the document named
 * in the message — and every verdict pair asserts that two documents DIFFER,
 * because a refusal test that only asserted "did not start" would also pass for
 * a process that crashed for an unrelated reason.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`.
 */
function spec014am1Container(): ?string
{
    foreach (['docker compose', 'docker-compose'] as $binary) {
        $raw = shell_exec($binary.' ps -q service 2>/dev/null');
        $id = is_string($raw) ? trim($raw) : '';

        if ($id !== '') {
            return $id;
        }
    }

    return null;
}

/**
 * Start a second service with `$settings` and optionally read `$asset` through it.
 *
 * @param  array<string, mixed>|string  $settings
 * @return array{started: bool, exit: int|null, output: string, read: array{status: int, body: mixed}|null}
 */
function spec014am1Probe(array|string $settings, ?string $asset = null): array
{
    $container = spec014am1Container();

    if ($container === null) {
        throw new RuntimeException('signing-service container not running');
    }

    $script = file_get_contents(__DIR__.'/spec014-probe.js');

    if (! is_string($script)) {
        throw new RuntimeException('cannot read spec014-probe.js');
    }

    $process = proc_open(
        sprintf('docker exec -i %s node -e %s', escapeshellarg($container), escapeshellarg($script)),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('cannot start docker exec');
    }

    fwrite($pipes[0], (string) json_encode([
        'settings' => $settings,
        'asset' => $asset === null ? null : base64_encode($asset),
        'mime' => 'image/png',
    ]));
    fclose($pipes[0]);

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    /** @var array{started: bool, exit: int|null, output: string, read: array{status: int, body: mixed}|null}|null $result */
    $result = json_decode($stdout, true);

    if (! is_array($result)) {
        throw new RuntimeException("probe returned no result: {$stdout} {$stderr}");
    }

    return $result;
}

function spec014am1Pem(string $file): string
{
    $pem = file_get_contents(dirname(__DIR__, 2).'/certs/'.$file);

    if (! is_string($pem) || $pem === '') {
        throw new RuntimeException("cannot read certs/{$file}");
    }

    return $pem;
}

/** The signing (end-entity) certificate alone: the first block of the chain. */
function spec014am1SigningCert(): string
{
    preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----\n?/s', spec014am1Pem('es256_certs.pem'), $m);

    return $m[0] ?? throw new RuntimeException('no certificate in certs/es256_certs.pem');
}

/** A freshly generated self-signed certificate: anchors that cover nothing we sign with. */
function spec014am1ForeignAnchor(): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

    if ($key === false) {
        throw new RuntimeException('cannot generate a foreign anchor key');
    }

    // openssl_csr_new() takes the key by reference, so it gets a copy.
    $csrKey = $key;
    $csr = openssl_csr_new(['commonName' => 'SPEC-014 foreign anchor'], $csrKey, ['digest_alg' => 'sha256']);
    $cert = $csr instanceof OpenSSLCertificateSigningRequest
        ? openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256'])
        : false;

    if ($cert === false || ! openssl_x509_export($cert, $pem) || ! is_string($pem)) {
        throw new RuntimeException('cannot generate a foreign anchor');
    }

    return $pem;
}

/**
 * @param  array<string, mixed>  $trust
 * @param  array<string, mixed>  $verify
 * @return array<string, mixed>
 */
function spec014am1Document(array $trust, array $verify = ['verify_trust' => true]): array
{
    return ['trust' => $trust, 'verify' => $verify];
}

/** Sign the fixture through the live service. */
function spec014am1Signed(): string
{
    [$signer] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiGenerated(MediaType::Png)
        ->withSoftwareAgent('SPEC-014 Amendment 1')
        ->build();

    return $signer->sign(new Asset(ServiceHarness::fixtureBytes(), MediaType::Png), $manifest)->bytes;
}

/**
 * Read a signed asset through a second service holding `$settings`.
 *
 * @param  array<string, mixed>  $settings
 */
function spec014am1ReadWith(array $settings, string $signed): ManifestReport
{
    $result = spec014am1Probe($settings, $signed);

    if (! $result['started'] || $result['read'] === null || $result['read']['status'] !== 200) {
        throw new RuntimeException('second service did not read: '.json_encode($result));
    }

    return ManifestStoreParser::fromJson((string) json_encode($result['read']['body']));
}

/** @param array{started: bool, exit: int|null, output: string, read: mixed} $result */
function spec014am1Describe(array $result): string
{
    return $result['started']
        ? 'the service STARTED and served with this document; output: '.trim($result['output'])
        : sprintf('exit %s; output: %s', var_export($result['exit'], true), trim($result['output']));
}

$skipUnlessContainer = fn () => spec014am1Container() === null || ! ServiceHarness::reachable()
    ? 'signing-service container not running — start it with docker-compose up -d'
    : false;

// --- Positive control: the shipped document starts --------------------------
// Without this, every refusal below would also pass against a probe that cannot
// start a service at all.

it('starts with the trust document this repository ships', function () {
    $result = spec014am1Probe((string) file_get_contents(dirname(__DIR__, 2).'/certs/c2pa-trust.settings.json'));

    expect($result['started'])->toBeTrue(spec014am1Describe($result))
        ->and($result['output'])->toContain('trust=true');
})->group('SPEC-014', 'integration')->skip($skipUnlessContainer);

// --- AC5 (Amendment 1): shapes that would verify nothing are refused -------

it('refuses an allowed list without anchors beside it, pointing at the docs', function () {
    $result = spec014am1Probe(spec014am1Document(['allowed_list' => spec014am1SigningCert()]));

    expect($result['started'])->toBeFalse(spec014am1Describe($result))
        ->and($result['exit'])->not->toBe(0)
        ->and($result['output'])->toContain('trust.allowed_list')
        ->and($result['output'])->toContain('docs/production.md');
})->group('SPEC-014', 'integration')->skip($skipUnlessContainer);

it('refuses a document carrying the trust.anchors form', function () {
    $result = spec014am1Probe(spec014am1Document([
        'trust_anchors' => spec014am1Pem('trust_anchors.pem'),
        'anchors' => [['trust_kind' => 'manifest', 'trust_anchors' => spec014am1Pem('trust_anchors.pem')]],
    ]));

    expect($result['started'])->toBeFalse(spec014am1Describe($result))
        ->and($result['exit'])->not->toBe(0)
        ->and($result['output'])->toContain('trust.anchors');
})->group('SPEC-014', 'integration')->skip($skipUnlessContainer);

it('refuses a document whose verify_trust is not true', function () {
    $result = spec014am1Probe(spec014am1Document(
        ['trust_anchors' => spec014am1Pem('trust_anchors.pem')],
        ['verify_trust' => false],
    ));

    expect($result['started'])->toBeFalse(spec014am1Describe($result))
        ->and($result['output'])->toContain('verify.verify_trust');
})->group('SPEC-014', 'integration')->skip($skipUnlessContainer);

// --- AC9: every key the service passes on reaches the engine ---------------

it('refuses a key under verify that it does not map, naming it', function () {
    $result = spec014am1Probe(spec014am1Document(
        ['trust_anchors' => spec014am1Pem('trust_anchors.pem')],
        ['verify_trust' => true, 'verify_after_sign' => true],
    ));

    expect($result['started'])->toBeFalse(spec014am1Describe($result))
        ->and($result['output'])->toContain('verify.verify_after_sign');
})->group('SPEC-014', 'integration')->skip($skipUnlessContainer);

it('refuses a camelCase key under trust, naming it', function () {
    // The Context's own spelling. In this document it would reach the engine
    // beside the snake_case key under two names; refused like any unmapped key.
    $result = spec014am1Probe(spec014am1Document([
        'trust_anchors' => spec014am1Pem('trust_anchors.pem'),
        'trustAnchors' => spec014am1Pem('trust_anchors.pem'),
    ]));

    expect($result['started'])->toBeFalse(spec014am1Describe($result))
        ->and($result['output'])->toContain('trust.trustAnchors');
})->group('SPEC-014', 'integration')->skip($skipUnlessContainer);

it('accepts every key it maps', function () {
    $result = spec014am1Probe(spec014am1Document(
        [
            'trust_anchors' => spec014am1Pem('trust_anchors.pem'),
            'user_anchors' => spec014am1Pem('trust_anchors.pem'),
            'trust_config' => spec014am1Pem('store.cfg'),
            'allowed_list' => spec014am1SigningCert(),
        ],
        ['verify_trust' => true, 'remote_manifest_fetch' => false],
    ));

    expect($result['started'])->toBeTrue(spec014am1Describe($result));
})->group('SPEC-014', 'integration')->skip($skipUnlessContainer);

// --- AC11 (AC1 + AC2 through the Context) and AC10 -------------------------
// Each pair is read from ONE signed asset under two documents, and asserts the
// verdicts differ: a pair that agreed would mean the trust material never
// reached the engine, which is the Step 67 failure this amendment exists for.

it('reads Trusted under covering anchors and untrusted under foreign ones', function () {
    $signed = spec014am1Signed();
    $eku = spec014am1Pem('store.cfg');

    $covering = spec014am1ReadWith(spec014am1Document(['trust_anchors' => spec014am1Pem('trust_anchors.pem'), 'trust_config' => $eku]), $signed);
    $foreign = spec014am1ReadWith(spec014am1Document(['trust_anchors' => spec014am1ForeignAnchor(), 'trust_config' => $eku]), $signed);

    expect($covering->validationState())->toBe(ValidationState::Trusted)
        ->and($covering->isTrusted())->toBeTrue()
        ->and($foreign->validationState())->toBe(ValidationState::Valid)
        ->and($foreign->validationStatusCodes())->toContain('signingCredential.untrusted')
        ->and($foreign->isSignatureValid())->toBeTrue();
})->group('SPEC-014', 'integration')->skip($skipUnlessContainer);

it('reads Trusted when the allowed list beside foreign anchors names the signer', function () {
    $signed = spec014am1Signed();
    $foreignAnchors = spec014am1ForeignAnchor();
    $eku = spec014am1Pem('store.cfg');

    $allowed = spec014am1ReadWith(spec014am1Document([
        'trust_anchors' => $foreignAnchors,
        'trust_config' => $eku,
        'allowed_list' => spec014am1SigningCert(),
    ]), $signed);
    $neither = spec014am1ReadWith(spec014am1Document([
        'trust_anchors' => $foreignAnchors,
        'trust_config' => $eku,
        'allowed_list' => spec014am1ForeignAnchor(),
    ]), $signed);

    expect($allowed->validationState())->toBe(ValidationState::Trusted)
        ->and($allowed->isTrusted())->toBeTrue()
        ->and($neither->validationState())->toBe(ValidationState::Valid)
        ->and($neither->validationStatusCodes())->toContain('signingCredential.untrusted');
})->group('SPEC-014', 'integration')->skip($skipUnlessContainer);

it('reads Trusted when user_anchors cover the signer beside foreign trust_anchors', function () {
    // AC9's mapping of user_anchors (the amendment's open question, leaning
    // "map"): mapped, it confers trust; dropped, this reads untrusted.
    $signed = spec014am1Signed();

    $report = spec014am1ReadWith(spec014am1Document([
        'trust_anchors' => spec014am1ForeignAnchor(),
        'user_anchors' => spec014am1Pem('trust_anchors.pem'),
        'trust_config' => spec014am1Pem('store.cfg'),
    ]), $signed);

    expect($report->validationState())->toBe(ValidationState::Trusted);
})->group('SPEC-014', 'integration')->skip($skipUnlessContainer);
