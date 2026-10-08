<?php

declare(strict_types=1);

use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\C2paVerifierReader;
use Provemark\ContentCredentials\Core\Reading\Exception\ReadFailedException;
use Provemark\ContentCredentials\Core\Reading\ExtC2paReader;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Tests\Integration\RemoteProbe;
use Provemark\ContentCredentials\Tests\Integration\ServiceHarness;

/**
 * SPEC-043 — the signing service never fetches a remote manifest.
 *
 * Measured 2026-10-08 before this file existed: POST /v1/read and POST
 * /v1/sign with a SPEC-028 parent both made a GET to the `dcterms:provenance`
 * URL in the uploaded file, and the service turned a store it fetched into a
 * report.
 *
 * Most of this file asserts an ABSENCE (no request reached the listener), the
 * shape this repository has repeatedly seen pass while testing nothing. So the
 * absence tests run only in the profile that also runs AC3's positive control:
 * there CONTENTAUTH_TSA_URL points at the same listener, every signature fails
 * closed on the timestamp, and the timestamp request arriving proves the
 * service can reach the listener in that very run. The CI guard asserts the
 * control and the absences all ran.
 *
 * Excluded from `composer check`; run with `vendor/bin/pest --group=integration`
 * or, in the probe profile, `--group=SPEC-043`.
 *
 * @see specs/SPEC-043-no-remote-manifest-fetch.md
 */
$skipUnlessProbe = fn () => match (true) {
    ! RemoteProbe::profileActive() => 'not the SPEC-043 probe profile (CC_REMOTE_PROBE=1, TSA pointed at the listener)',
    ! ServiceHarness::reachable() => 'signing service not reachable',
    default => false,
};

$skipUnlessService = fn () => ! ServiceHarness::reachable()
    ? 'signing service not reachable'
    : false;

/** An edit signed with $parent as its SPEC-028 parent. */
function spec043SignEdit(string $parent): string
{
    [$signer] = ServiceHarness::signerAndReader();

    $manifest = ManifestBuilder::forAiManipulated(MediaType::Jpeg)
        ->withSoftwareAgent('SPEC-043 probe')
        ->build();

    return $signer->sign(
        new Asset(ServiceHarness::mediaFixture(MediaType::Jpeg), MediaType::Jpeg),
        $manifest,
        new Asset($parent, MediaType::Jpeg),
    )->bytes;
}

// --- AC3: the positive control, run first ------------------------------------

it('reaches the listener with the request it is configured to make', function () {
    RemoteProbe::start();
    $path = RemoteProbe::nonce('tsa-control');

    // The profile points CONTENTAUTH_TSA_URL at the listener's /tsa-control;
    // the nonce is in the asset only so the attempt is ours. The signature
    // fails closed (SPEC-007): the listener answers 404, not a token.
    try {
        [$signer] = ServiceHarness::signerAndReader();
        $signer->sign(
            new Asset(ServiceHarness::mediaFixture(MediaType::Png), MediaType::Png),
            ManifestBuilder::forAiGenerated(MediaType::Png)->withSoftwareAgent($path)->build(),
        );
    } catch (Throwable) {
        // expected: no timestamp, no signature
    }

    expect(RemoteProbe::received('POST /tsa-control'))->toBeTrue(
        'the service never reached the listener, so no absence in this file means anything: '
        .json_encode(RemoteProbe::hits()),
    );
})->group('SPEC-043', 'integration')->skip($skipUnlessProbe);

// --- AC1: reading makes no request to a URL from the file --------------------

it('makes no request when reading a file whose manifest is remote', function () {
    RemoteProbe::start();
    $path = RemoteProbe::nonce('read');
    [, $reader] = ServiceHarness::signerAndReader();

    $thrown = null;

    try {
        $reader->read(new Asset(RemoteProbe::remoteOnlyJpeg(RemoteProbe::url($path)), MediaType::Jpeg));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect(RemoteProbe::received('/'.$path))->toBeFalse('the service fetched '.$path)
        ->and($thrown)->toBeInstanceOf(ReadFailedException::class)
        ->and($thrown?->getMessage())->toContain('read failed');
})->group('SPEC-043', 'integration')->skip($skipUnlessProbe);

// --- AC2: signing with such a parent makes no request ------------------------

it('makes no request when signing with a parent whose manifest is remote', function () {
    RemoteProbe::start();
    $path = RemoteProbe::nonce('parent');

    try {
        spec043SignEdit(RemoteProbe::remoteOnlyJpeg(RemoteProbe::url($path)));
    } catch (Throwable) {
        // In this profile every signature fails on the timestamp. The fetch,
        // when it happens, happens in addIngredient(), before that.
    }

    expect(RemoteProbe::received('/'.$path))->toBeFalse('the service fetched '.$path);
})->group('SPEC-043', 'integration')->skip($skipUnlessProbe);

it('still signs an edit whose parent declares a remote manifest', function () {
    // AC2's other half, where signatures can succeed. Green before SPEC-043 as
    // well: it guards that handing settings to the builder breaks nothing.
    // `.invalid` never resolves (RFC 2606), so no request leaves either way.
    $signed = spec043SignEdit(RemoteProbe::remoteOnlyJpeg('https://manifests.example.invalid/spec043-parent.c2pa'));

    [, $reader] = ServiceHarness::signerAndReader();
    $report = $reader->read(new Asset($signed, MediaType::Jpeg));

    expect($report->hasManifest())->toBeTrue()
        ->and($report->isSignatureValid())->toBeTrue()
        ->and($report->involvesGenerativeAi())->toBeTrue();
})->group('SPEC-043', 'integration')
    ->skip($skipUnlessService)
    ->skip(fn () => RemoteProbe::profileActive() ? 'the probe profile refuses every signature by design' : false);

// --- AC6: a remote-only file reads the same through all three readers --------

it('refuses a remote-only file through every reader', function () {
    // The listener serves a real manifest store under /manifest-…: measured
    // 2026-10-08, the service fetched it and returned a report (Invalid,
    // assertion.dataHash.mismatch). That report is what this asserts away.
    RemoteProbe::start();
    $path = RemoteProbe::nonce('manifest');
    $asset = new Asset(RemoteProbe::remoteOnlyJpeg(RemoteProbe::url($path)), MediaType::Jpeg);
    [, $serviceReader] = ServiceHarness::signerAndReader();

    $readers = ['service' => $serviceReader, 'verifier' => new C2paVerifierReader];

    if (extension_loaded('c2pa')) {
        $readers['extension'] = new ExtC2paReader;
    }

    foreach ($readers as $name => $reader) {
        expect(fn () => $reader->read($asset))->toThrow(ReadFailedException::class, null, "the {$name} reader");
    }

    expect(RemoteProbe::received('/'.$path))->toBeFalse('the service fetched the store');
})->group('SPEC-043', 'integration')->skip($skipUnlessProbe);

// --- AC5: /health reports it -------------------------------------------------

it('reports on /health that it never fetches a remote manifest', function () {
    expect(ServiceHarness::health())->toHaveKey('remote_manifest_fetch')
        ->and(ServiceHarness::health()['remote_manifest_fetch'] ?? null)->toBeFalse();
})->group('SPEC-043', 'integration')->skip($skipUnlessService);

// --- AC4: a trust settings file cannot turn it back on -----------------------

/**
 * Starts a second server process inside the running container, with
 * $settings written to its tmpfs, and returns its exit code and output. The
 * SPEC-014 startup tests use the same shape.
 *
 * @param  array<string, mixed>  $settings
 * @return array{exit: int, output: string}
 */
function spec043StartWith(array $settings, int $deadlineSeconds = 5): array
{
    $container = null;

    foreach (['docker compose', 'docker-compose'] as $binary) {
        $raw = shell_exec($binary.' ps -q service 2>/dev/null');
        $id = is_string($raw) ? trim($raw) : '';

        if ($id !== '') {
            $container = $id;
            break;
        }
    }

    if ($container === null) {
        return ['exit' => -1, 'output' => 'signing-service container not running'];
    }

    $inner = sprintf(
        'printf %%s %s > /tmp/spec043-trust.json && cd /app && PORT=3998 CONTENTAUTH_TRUST_SETTINGS=/tmp/spec043-trust.json timeout %d node server.js 2>&1; echo "EXIT=$?"; rm -f /tmp/spec043-trust.json',
        escapeshellarg((string) json_encode($settings)),
        $deadlineSeconds,
    );

    $raw = shell_exec(sprintf('docker exec %s sh -c %s 2>&1', escapeshellarg($container), escapeshellarg($inner)));
    $output = is_string($raw) ? $raw : '';
    preg_match('/EXIT=(\d+)/', $output, $m);

    return ['exit' => (int) ($m[1] ?? -1), 'output' => $output];
}

/**
 * The committed test trust settings, with verify.remote_manifest_fetch set to
 * $fetch, or left unmentioned when $fetch is null.
 *
 * @return array<string, mixed>
 */
function spec043TrustSettings(?bool $fetch = null): array
{
    $decoded = json_decode((string) file_get_contents(ServiceHarness::trustSettingsPath()), true);
    $settings = [];

    foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
        $settings[(string) $key] = $value;
    }

    if ($fetch !== null) {
        $verify = is_array($settings['verify'] ?? null) ? $settings['verify'] : [];
        $verify['remote_manifest_fetch'] = $fetch;
        $settings['verify'] = $verify;
    }

    return $settings;
}

it('refuses to start when trust settings turn remote fetching on', function () {
    $result = spec043StartWith(spec043TrustSettings(fetch: true));

    // 124 is timeout(1): the server started and kept running.
    expect($result['exit'])->not->toBe(124, $result['output'])
        ->and($result['exit'])->not->toBe(0, $result['output'])
        ->and($result['output'])->toContain('remote_manifest_fetch');
})->group('SPEC-043', 'integration')->skip(fn () => shell_exec('docker ps -q 2>/dev/null') === null ? 'docker not available' : false);

it('starts with trust settings that do not mention remote fetching', function () {
    // The other half: the refusal is about `true`, not about the trust file.
    $result = spec043StartWith(spec043TrustSettings());

    expect($result['exit'])->toBe(124, $result['output']);
})->group('SPEC-043', 'integration')->skip(fn () => shell_exec('docker ps -q 2>/dev/null') === null ? 'docker not available' : false);
