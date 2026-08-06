<?php

declare(strict_types=1);

/**
 * End-to-end integration harness using the ACTUAL library (not bin/spike.php's
 * raw curl): Core\Manifest -> Core\Signing -> the running service -> Core\Reading,
 * over a real Guzzle PSR-18 client. Then it runs bin/verify.sh (c2patool) for the
 * authoritative check.
 *
 * Requires the signing service running (docker compose up) and a CONTENTAUTH_API_KEY
 * in .env (or the environment). Not part of `composer check` — run it explicitly:
 *
 *   php bin/e2e.php
 */
$root = dirname(__DIR__);
require "$root/vendor/autoload.php";

use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Provemark\ContentCredentials\Core\Manifest\ManifestBuilder;
use Provemark\ContentCredentials\Core\Manifest\MediaType;
use Provemark\ContentCredentials\Core\Reading\ExtC2paReader;
use Provemark\ContentCredentials\Core\Reading\SigningServiceReader;
use Provemark\ContentCredentials\Core\Signing\Asset;
use Provemark\ContentCredentials\Core\Signing\SigningServiceConfig;
use Provemark\ContentCredentials\Core\Signing\SigningServiceSigner;

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: $message\n");
    exit(1);
}

// --- config: CONTENTAUTH_API_KEY from env or .env, base URL with a default ---
$apiKey = getenv('CONTENTAUTH_API_KEY') ?: '';
if ($apiKey === '' && is_file("$root/.env")) {
    foreach (file("$root/.env", FILE_IGNORE_NEW_LINES) as $line) {
        if (str_starts_with($line, 'CONTENTAUTH_API_KEY=')) {
            $apiKey = substr($line, strlen('CONTENTAUTH_API_KEY='));
        }
    }
}
if ($apiKey === '') {
    fail('CONTENTAUTH_API_KEY is not set (env or .env). Copy .env.example to .env and set it.');
}
$baseUrl = getenv('CONTENTAUTH_SERVICE_URL') ?: 'http://localhost:3000';

// --- health check so failures are obvious ---
$health = @file_get_contents("$baseUrl/health");
if ($health === false) {
    fail("signing service not reachable at $baseUrl (start it with: docker compose up -d --build).");
}
// SPEC-007: whether the service is configured to add trusted timestamps.
$healthData = json_decode((string) $health, true);
$timestampingEnabled = is_array($healthData) && (bool) ($healthData['timestamping'] ?? false);

// --- wire the library with a real PSR-18 client + PSR-17 factories ---
$factory = new Psr17Factory;
$config = new SigningServiceConfig($baseUrl, $apiKey);
$signer = new SigningServiceSigner(new Client, $factory, $factory, $config);
$reader = new SigningServiceReader(new Client, $factory, $factory, $config);

// --- 1. build the AI manifest (Core\Manifest) ---
$fixture = "$root/tests/fixture.png";
is_file($fixture) || fail("fixture not found: $fixture");
$bytes = (string) file_get_contents($fixture);
echo '→ fixture: '.strlen($bytes)." bytes\n";

$manifest = ManifestBuilder::forAiGeneratedImage(MediaType::Png)
    ->withSoftwareAgent('ACME GenAI Image Model', '3.1.0')
    ->withClaimGenerator('Content Credentials (e2e)', '0.1.0')
    ->build();

// --- 2. sign via the service (Core\Signing) ---
echo "→ signing via {$baseUrl}/v1/sign …\n";
$signed = $signer->sign(new Asset($bytes, MediaType::Png), $manifest);
@mkdir("$root/out", 0755, true);
$outPath = "$root/out/signed.png";
file_put_contents($outPath, $signed->bytes);
echo '✓ wrote '.$outPath.' ('.strlen($signed->bytes)." bytes)\n";

// --- 3. read it back (Core\Reading) ---
echo "→ reading back via /v1/read …\n";
$report = $reader->read(new Asset($signed->bytes, MediaType::Png));
$s = $report->signer();

printf("  hasManifest        : %s\n", var_export($report->hasManifest(), true));
printf("  isAiGenerated      : %s\n", var_export($report->isAiGenerated(), true));
printf("  digitalSourceTypes : %s\n", implode(', ', $report->digitalSourceTypes()));
printf("  signer             : %s / CN=%s [%s]\n", $s?->issuer ?? '?', $s?->commonName ?? '?', $s?->algorithm ?? '?');
printf("  validationStatus   : %s\n", implode(', ', $report->validationStatusCodes()) ?: '(none)');
printf("  validationState    : %s\n", $report->validationState()?->value ?? '(none)');
printf("  isSignatureValid   : %s\n", var_export($report->isSignatureValid(), true));
printf("  isTrusted          : %s\n", var_export($report->isTrusted(), true));
printf("  hasTimestamp       : %s (service timestamping=%s)\n", var_export($report->hasTimestamp(), true), var_export($timestampingEnabled, true));

$libOk = $report->hasManifest() && $report->isAiGenerated() && $s !== null;
echo $libOk ? "✓ library sign+read: OK\n" : "✗ library sign+read: FAILED\n";

// SPEC-007 AC4: when the service reports timestamping enabled, the signed asset
// MUST read back as timestamped; when disabled, hasTimestamp() must be false.
$tsOk = $report->hasTimestamp() === $timestampingEnabled;
echo match (true) {
    ! $tsOk => sprintf(
        "✗ timestamp mismatch: service timestamping=%s but hasTimestamp()=%s (SPEC-007 AC4)\n",
        var_export($timestampingEnabled, true),
        var_export($report->hasTimestamp(), true),
    ),
    $timestampingEnabled => "✓ timestamp present (SPEC-007 AC4)\n",
    default => "· timestamping disabled — set CONTENTAUTH_TSA_URL to exercise AC4\n",
};
// SPEC-007 AC5 (fail-closed) is a separate check: start the service with an
// unreachable CONTENTAUTH_TSA_URL and confirm /v1/sign returns a 5xx error and
// no signed_content (not automated here — it requires a deliberately bad TSA).

// SPEC-014 AC1: when the service verifies against a trust list, the library
// path must reach a verdict that is consistent with what it was given.
//
// Note what this deliberately does NOT assert: that trust verification being on
// implies this asset is trusted. That only holds when the configured anchors
// cover the signing certificate. Verified 2026-08-06 against the official
// C2PA trust list (c2pa-org/conformance-public): the test certificate reads
// back Valid + signingCredential.untrusted, and isTrusted() === false is the
// CORRECT answer there. An earlier version of this check asserted
// isTrusted() === $trustEnabled and reported a failure for that entirely
// healthy configuration.
//
// What must hold in every configuration: trusted implies the reader saw the
// Trusted verdict and reported no untrusted code, and untrusted-under-
// verification is explained by a status code rather than by silence.
$trustEnabled = is_array($healthData) && (bool) ($healthData['trust_verification'] ?? false);
$untrusted = in_array('signingCredential.untrusted', $report->validationStatusCodes(), true);
$trustState = $report->validationState()?->value ?? '(none)';

$trustOk = match (true) {
    // Trust off: false by design, and the reader should say why.
    ! $trustEnabled => $report->isTrusted() === false,
    // Trust on and the anchors cover this certificate.
    $report->isTrusted() => $trustState === 'Trusted' && ! $untrusted,
    // Trust on and they do not — legitimate, but it must be explained.
    default => $untrusted,
};

echo match (true) {
    ! $trustOk => sprintf(
        "✗ inconsistent trust verdict: trust_verification=%s, isTrusted()=%s, state=%s, untrusted-code=%s (SPEC-014 AC1)\n",
        var_export($trustEnabled, true),
        var_export($report->isTrusted(), true),
        $trustState,
        var_export($untrusted, true),
    ),
    $trustEnabled && $report->isTrusted() => "✓ certificate trusted via the library path (SPEC-014 AC1)\n",
    $trustEnabled => '· trust verification on, but these anchors do not cover this certificate'
        ." — reported untrusted, which is correct (SPEC-014 AC2)\n",
    default => "· trust verification off — set CONTENTAUTH_TRUST_SETTINGS to exercise AC1\n",
};

// --- 3b. the in-process reader, and whether it agrees (SPEC-019) ---
//
// The same signed bytes, read a second way: through ext-c2pa, with no HTTP and
// no service. Two engines are involved — c2pa-rs 0.89.0 in the extension against
// 0.90.4 in the service — so agreement is a result, not a given. Skipped rather
// than failed where the extension is absent, which is the normal case.
if (! ExtC2paReader::isAvailable()) {
    echo "· ext-c2pa not installed — in-process reading not exercised"
        ." (pie install ericmann/ext-c2pa)\n";
} else {
    $inProcess = (new ExtC2paReader)->read(new Asset($signed->bytes, MediaType::Png));

    // Compared on the accessors a caller acts on. Anything else here would be
    // agreement by coincidence.
    $disagreements = array_keys(array_filter([
        'hasManifest' => $inProcess->hasManifest() !== $report->hasManifest(),
        'isSignatureValid' => $inProcess->isSignatureValid() !== $report->isSignatureValid(),
        'isAiGenerated' => $inProcess->isAiGenerated() !== $report->isAiGenerated(),
        'hasTimestamp' => $inProcess->hasTimestamp() !== $report->hasTimestamp(),
        'validationState' => $inProcess->validationState() !== $report->validationState(),
    ]));

    echo $disagreements === []
        ? "✓ in-process reader agrees with the service reader (SPEC-019 AC2)\n"
        : '✗ readers disagree on: '.implode(', ', $disagreements)
            ." — c2pa-rs 0.89.0 vs 0.90.4 may have drifted (SPEC-019 AC2)\n";

    // Trust is NOT compared above, and the reason is worth stating: the two are
    // configured separately. The service verifies against CONTENTAUTH_TRUST_SETTINGS;
    // this reader was given no anchors, so it cannot report trusted. Comparing
    // them would fail on a difference in configuration rather than in engines.
    echo $inProcess->isTrusted()
        ? "✗ in-process reader reported trusted with no anchors configured\n"
        : "· in-process reader given no anchors — isTrusted() false by design (SPEC-019 AC4)\n";
}

// --- 4. authoritative c2patool verification (trust enabled) ---
$verify = "$root/bin/verify.sh";
if (is_file($verify) && is_file("$root/tools/c2patool")) {
    echo "\n→ bin/verify.sh (c2patool, trust enabled):\n";
    passthru(escapeshellarg($verify).' '.escapeshellarg($outPath), $verifyExit);
} else {
    echo "\n(note: run `bin/verify.sh $outPath` for the authoritative c2patool check.)\n";
    $verifyExit = 0;
}

exit($libOk && $tsOk && $verifyExit === 0 ? 0 : 1);
